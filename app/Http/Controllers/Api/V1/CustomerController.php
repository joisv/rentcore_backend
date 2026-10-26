<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * Search and list customers with identities and booking counts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::with(['identities'])->withCount('bookings');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhereHas('identities', function ($iq) use ($search) {
                      $iq->where('identity_number', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('is_blacklisted')) {
            $query->where('is_blacklisted', filter_var($request->is_blacklisted, FILTER_VALIDATE_BOOLEAN));
        }

        $customers = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    /**
     * Quick lookup customer by phone number or NIK/identity number.
     * Used for auto-fill in direct counter rental wizard.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:3',
        ]);

        $queryStr = trim($request->query('query'));

        $customer = Customer::with(['identities', 'bookings' => function ($bq) {
            $bq->latest()->take(3);
        }])
        ->withCount('bookings')
        ->where('phone', $queryStr)
        ->orWhere('phone', 'like', "%{$queryStr}%")
        ->orWhereHas('identities', function ($iq) use ($queryStr) {
            $iq->where('identity_number', $queryStr)
              ->orWhere('identity_number', 'like', "%{$queryStr}%");
        })
        ->first();

        if (!$customer) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Pelanggan tidak ditemukan.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $customer,
        ]);
    }

    /**
     * Create or update customer record.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string',
            'identity_type' => 'nullable|in:KTP,SIM,KK,PASSPORT,KARTU_PELAJAR',
            'identity_number' => 'nullable|string|max:100',
            'identity_photo' => 'nullable',
        ]);

        $businessId = app(\App\Tenancy\TenantContext::class)->getBusinessId();

        $searchAttrs = ['phone' => $request->phone];
        if ($businessId) {
            $searchAttrs['business_id'] = $businessId;
        }

        $customer = Customer::firstOrCreate(
            $searchAttrs,
            [
                'name' => $request->name,
                'email' => $request->email,
                'address' => $request->address,
            ]
        );

        // Update details if customer existed
        $customer->update(array_filter([
            'name' => $request->name,
            'email' => $request->email,
            'address' => $request->address,
        ]));

        // Handle identity document if provided
        if ($request->filled('identity_number')) {
            $filePath = null;
            if ($request->filled('identity_photo')) {
                $filePath = $this->saveIdentityPhoto($request->input('identity_photo'), $customer->id);
            }

            CustomerIdentity::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'identity_type' => $request->input('identity_type', 'KTP'),
                    'identity_number' => $request->identity_number,
                ],
                array_filter([
                    'file_path' => $filePath,
                    'is_verified' => true,
                ])
            );
        }

        $customer->load('identities');

        return response()->json([
            'success' => true,
            'message' => 'Data pelanggan berhasil disimpan.',
            'data' => $customer,
        ], 201);
    }

    /**
     * Upload or attach customer identity photo document.
     */
    public function uploadIdentityDocument(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $request->validate([
            'identity_type' => 'nullable|in:KTP,SIM,KK,PASSPORT,KARTU_PELAJAR',
            'identity_number' => 'nullable|string|max:100',
            'photo' => 'required', // File upload or base64 string
        ]);

        $filePath = null;
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = 'id_' . $customer->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('customer_identities', $filename, 'local');
        } else {
            $filePath = $this->saveIdentityPhoto($request->input('photo'), $customer->id);
        }

        $identityType = $request->input('identity_type', 'KTP');
        $identityNumber = $request->input('identity_number') ?? ('DOC-' . time());

        $identity = CustomerIdentity::updateOrCreate(
            [
                'customer_id' => $customer->id,
                'identity_type' => $identityType,
                'identity_number' => $identityNumber,
            ],
            [
                'file_path' => $filePath,
                'is_verified' => true,
            ]
        );

        AuditLogService::log(
            $request->user(),
            'customer_identity_uploaded',
            'customer_identities',
            $identity->id,
            ['customer_id' => $customer->id, 'file_path' => $filePath],
            null
        );

        return response()->json([
            'success' => true,
            'message' => 'Dokumen jaminan identitas berhasil diunggah.',
            'data' => $identity,
        ]);
    }

    /**
     * Toggle customer blacklist status with reason.
     */
    public function toggleBlacklist(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $user = $request->user();
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $customer->business_id ?? $tenantContext->getBusinessId();
        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->isAdmin()) : ($user && ($user->isSuperAdmin() || $user->isPlatformAdmin()));

        if (!$isOwnerOrAdmin) {
            $reason = $request->input('blacklist_reason');
            $hasValidReason = !empty($reason) && strlen(trim($reason)) >= 5;
            $managerPin = $request->input('manager_pin');

            if (!$hasValidReason && empty($managerPin)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pencatatan daftar hitam oleh staf wajib menyertakan alasan keterangan yang jelas (minimal 5 karakter) atau otorisasi PIN Manager.',
                    'code' => 'BLACKLIST_REASON_REQUIRED',
                ], 422);
            }

            if (!empty($managerPin)) {
                $branchId = $tenantContext->getBranchId() ?? $user->affiliate_id;
                $managers = \App\Models\User::where(function ($q) use ($branchId, $businessId) {
                    if ($branchId) {
                        $q->where('affiliate_id', $branchId);
                    }
                    if ($businessId) {
                        $q->orWhereHas('businessMembers', function ($bm) use ($businessId) {
                            $bm->where('business_id', $businessId)->whereIn('role', ['owner', 'admin']);
                        });
                    }
                })
                ->whereNotNull('manager_override_pin')
                ->get();

                $matchingManager = $managers->first(fn($mgr) => password_verify($managerPin, $mgr->manager_override_pin));
                if (!$matchingManager) {
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN Manager tidak valid.',
                        'code' => 'INVALID_PIN',
                    ], 422);
                }
            }
        }

        $request->validate([
            'is_blacklisted' => 'required|boolean',
            'blacklist_reason' => 'nullable|string',
        ]);

        $before = [
            'is_blacklisted' => $customer->is_blacklisted,
            'blacklist_reason' => $customer->blacklist_reason,
        ];

        $customer->update([
            'is_blacklisted' => $request->boolean('is_blacklisted'),
            'blacklist_reason' => $request->boolean('is_blacklisted') ? $request->input('blacklist_reason', 'Ditandai oleh staf kasir.') : null,
        ]);

        AuditLogService::log(
            $request->user(),
            'customer_blacklist_updated',
            'customers',
            $customer->id,
            [
                'is_blacklisted' => $customer->is_blacklisted,
                'blacklist_reason' => $customer->blacklist_reason,
            ],
            $before
        );

        return response()->json([
            'success' => true,
            'message' => $customer->is_blacklisted ? 'Pelanggan berhasil dimasukkan ke daftar hitam.' : 'Pelanggan dipulihkan dari daftar hitam.',
            'data' => $customer,
        ]);
    }

    /**
     * Helper to decode and save base64 image data.
     */
    protected function saveIdentityPhoto(string $data, int $customerId): string
    {
        // Strip data:image/...;base64, prefix if present
        if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
            $data = substr($data, strpos($data, ',') + 1);
            $extension = strtolower($type[1]);
        } else {
            $extension = 'jpg';
        }

        $decoded = base64_decode($data);
        if ($decoded === false) {
            $decoded = $data; // Fallback plain content if simulation
        }

        $filename = 'id_' . $customerId . '_' . time() . '_' . Str::random(6) . '.' . $extension;
        $path = 'customer_identities/' . $filename;

        Storage::disk('local')->put($path, $decoded);

        return $path;
    }

    /**
     * Retrieve customer identity photo document securely under tenant authorization.
     */
    public function getIdentityDocument(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        $customer = Customer::findOrFail($id);
        $identity = $customer->identities()->latest()->first();

        if (!$identity || empty($identity->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen identitas pelanggan belum diunggah.',
            ], 404);
        }

        $disk = 'local';
        if (!Storage::disk('local')->exists($identity->file_path) && Storage::disk('public')->exists($identity->file_path)) {
            $disk = 'public'; // Backward compatibility for legacy uploads
        }

        if (!Storage::disk($disk)->exists($identity->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File dokumen fisik tidak ditemukan pada server.',
            ], 404);
        }

        return Storage::disk($disk)->response($identity->file_path);
    }
}
