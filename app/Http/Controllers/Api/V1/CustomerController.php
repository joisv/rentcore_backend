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

        $customer = Customer::firstOrCreate(
            ['phone' => $request->phone],
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
            $filePath = $file->storeAs('customer_identities', $filename, 'public');
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

        Storage::disk('public')->put($path, $decoded);

        return $path;
    }
}
