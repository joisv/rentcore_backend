<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Services\AuditLogService;
use App\Services\VoucherService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    public function __construct(
        protected VoucherService $voucherService
    ) {}

    /**
     * Check if the authenticated user is an authorized Owner or platform Super-Admin.
     */
    protected function checkIsOwner(Request $request): bool
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return true;
        }

        $tenant = app(\App\Tenancy\TenantContext::class);
        return $tenant->hasActiveBusiness() && $tenant->isOwner();
    }

    /**
     * Enforce Owner-only authorization.
     */
    protected function authorizeOwner(Request $request): void
    {
        if (!$this->checkIsOwner($request)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Hanya Owner bisnis atau Super-Admin yang memiliki otorisasi untuk mengelola voucher promo.',
            ], 403));
        }
    }

    /**
     * List vouchers with status, search, and branch filters for Owner; active-only for Cashier.
     */
    public function index(Request $request): JsonResponse
    {
        $isOwner = $this->checkIsOwner($request);

        $query = Voucher::with(['affiliate', 'creator']);

        if (!$isOwner) {
            // Cashier/staff: strictly active and valid date vouchers only
            $query->active();
        } else {
            // Owner view: support status and search filters
            $status = $request->query('status', 'all');
            $now = Carbon::now();

            if ($status === 'active') {
                $query->where('is_active', true)
                      ->where('start_at', '<=', $now)
                      ->where('end_at', '>=', $now);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($status === 'expired') {
                $query->where('end_at', '<', $now);
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%");
                });
            }

            if ($request->filled('affiliate_id') || $request->filled('branch_id')) {
                $branchId = $request->query('affiliate_id') ?? $request->query('branch_id');
                if ($branchId === 'all' || $branchId === '' || $branchId === '-1') {
                    // All branches
                } elseif ($branchId === 'business_wide' || $branchId === '0') {
                    $query->whereNull('affiliate_id');
                } else {
                    $query->where('affiliate_id', $branchId);
                }
            }
        }

        $vouchers = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $vouchers,
        ]);
    }

    /**
     * View voucher details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $voucher = Voucher::with(['affiliate', 'creator', 'usages.customer'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $voucher,
        ]);
    }

    /**
     * Owner governance: create new percentage voucher with minimum transaction threshold.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $user = $request->user();
        $tenant = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenant->getBusinessId();

        $validated = $request->validate([
            'code' => [
                'nullable',
                'string',
                'max:50',
                \Illuminate\Validation\Rule::unique('vouchers', 'code')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'discount_percentage' => 'required|numeric|min:1|max:100',
            'max_discount_cap' => 'nullable|numeric|min:0',
            'min_transaction_amount' => 'required|numeric|min:0',
            'barcode_type' => 'nullable|in:qr_code,datamatrix,code128',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'affiliate_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('affiliates', 'id')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_customer' => 'nullable|integer|min:1',
        ]);

        $code = !empty($validated['code']) 
            ? strtoupper(trim($validated['code'])) 
            : 'RC-VCR-' . strtoupper(Str::random(6));

        $voucher = Voucher::create([
            'business_id' => $businessId,
            'affiliate_id' => $validated['affiliate_id'] ?? null,
            'code' => $code,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'discount_percentage' => $validated['discount_percentage'],
            'max_discount_cap' => $validated['max_discount_cap'] ?? null,
            'min_transaction_amount' => $validated['min_transaction_amount'],
            'barcode_type' => $validated['barcode_type'] ?? 'qr_code',
            'start_at' => Carbon::parse($validated['start_at']),
            'end_at' => Carbon::parse($validated['end_at']),
            'usage_limit_total' => $validated['usage_limit_total'] ?? null,
            'usage_limit_per_customer' => $validated['usage_limit_per_customer'] ?? 1,
            'created_by' => $user->id,
            'is_active' => true,
        ]);

        AuditLogService::log(
            $user,
            'voucher.created',
            'Voucher',
            $voucher->code,
            [
                'code' => $voucher->code,
                'name' => $voucher->name,
                'discount_percentage' => $voucher->discount_percentage,
                'min_transaction_amount' => $voucher->min_transaction_amount,
                'barcode_type' => $voucher->barcode_type,
            ],
            null,
            $request->ip(),
            $voucher->affiliate_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Voucher promo diskon berhasil diterbitkan oleh Owner.',
            'data' => $voucher->fresh(['affiliate', 'creator']),
        ], 201);
    }

    /**
     * Owner governance: update existing voucher.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeOwner($request);

        $voucher = Voucher::findOrFail($id);
        $user = $request->user();
        $tenant = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenant->getBusinessId() ?? $voucher->business_id;

        $hasBeenUsed = $voucher->usage_count > 0 || $voucher->usages()->exists();

        $rules = [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'max_discount_cap' => 'nullable|numeric|min:0',
            'min_transaction_amount' => 'required|numeric|min:0',
            'barcode_type' => 'nullable|in:qr_code,datamatrix,code128',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'affiliate_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('affiliates', 'id')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_customer' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ];

        // If voucher has not been used, allow updating code and discount percentage
        if (!$hasBeenUsed) {
            $rules['code'] = [
                'required',
                'string',
                'max:50',
                \Illuminate\Validation\Rule::unique('vouchers', 'code')
                    ->ignore($voucher->id)
                    ->where(function ($q) use ($businessId) {
                        if ($businessId) {
                            $q->where('business_id', $businessId);
                        }
                    }),
            ];
            $rules['discount_percentage'] = 'required|numeric|min:1|max:100';
        }

        $validated = $request->validate($rules);

        $updateData = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'max_discount_cap' => $validated['max_discount_cap'] ?? null,
            'min_transaction_amount' => $validated['min_transaction_amount'],
            'barcode_type' => $validated['barcode_type'] ?? $voucher->barcode_type,
            'start_at' => Carbon::parse($validated['start_at']),
            'end_at' => Carbon::parse($validated['end_at']),
            'affiliate_id' => array_key_exists('affiliate_id', $validated) ? $validated['affiliate_id'] : $voucher->affiliate_id,
            'usage_limit_total' => $validated['usage_limit_total'] ?? null,
            'usage_limit_per_customer' => $validated['usage_limit_per_customer'] ?? $voucher->usage_limit_per_customer,
        ];

        if (isset($validated['is_active'])) {
            $updateData['is_active'] = (bool) $validated['is_active'];
        }

        if (!$hasBeenUsed) {
            $updateData['code'] = strtoupper(trim($validated['code']));
            $updateData['discount_percentage'] = $validated['discount_percentage'];
        }

        $voucher->update($updateData);

        AuditLogService::log(
            $user,
            'voucher.updated',
            'Voucher',
            $voucher->code,
            $updateData,
            null,
            $request->ip(),
            $voucher->affiliate_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Voucher diskon berhasil diperbarui.',
            'data' => $voucher->fresh(['affiliate', 'creator']),
        ]);
    }

    /**
     * Owner governance: toggle active status of a voucher.
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $this->authorizeOwner($request);

        $voucher = Voucher::findOrFail($id);
        $voucher->is_active = !$voucher->is_active;
        $voucher->save();

        AuditLogService::log(
            $request->user(),
            'voucher.status_toggled',
            'Voucher',
            $voucher->code,
            ['is_active' => $voucher->is_active],
            null,
            $request->ip(),
            $voucher->affiliate_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Status voucher berhasil diubah menjadi ' . ($voucher->is_active ? 'Aktif' : 'Nonaktif') . '.',
            'data' => $voucher->fresh(['affiliate', 'creator']),
        ]);
    }

    /**
     * Owner governance: delete an unredeemed voucher.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeOwner($request);

        $voucher = Voucher::findOrFail($id);

        if ($voucher->usage_count > 0 || $voucher->usages()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher tidak dapat dihapus karena telah digunakan dalam riwayat transaksi (' . $voucher->usage_count . ' kali). Silakan nonaktifkan voucher sebagai gantinya.',
            ], 422);
        }

        $code = $voucher->code;
        $affiliateId = $voucher->affiliate_id;
        $voucher->delete();

        AuditLogService::log(
            $request->user(),
            'voucher.deleted',
            'Voucher',
            $code,
            ['code' => $code],
            null,
            $request->ip(),
            $affiliateId
        );

        return response()->json([
            'success' => true,
            'message' => 'Voucher promo diskon berhasil dihapus.',
        ]);
    }

    /**
     * Validate voucher against checkout subtotal & minimum transaction amount.
     */
    public function validateVoucher(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0',
            'customer_id' => 'nullable|integer',
            'affiliate_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
        ]);

        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $affiliateId = $request->input('affiliate_id') 
            ?? $request->input('branch_id') 
            ?? $tenantContext->getBranchId() 
            ?? $request->user()->affiliate_id;

        $result = $this->voucherService->validateVoucher(
            $request->code,
            (float)$request->subtotal,
            $request->customer_id,
            $affiliateId ? (int)$affiliateId : null
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Generate thermal ESC/POS payload with 2D barcode for printing physical voucher slips.
     */
    public function printSlip(Request $request, string $code): JsonResponse
    {
        $voucher = Voucher::where('code', trim($code))->firstOrFail();
        $affiliate = $request->user()->affiliate;

        $slipPayload = $this->voucherService->generateThermalSlipPayload($voucher, $affiliate);

        return response()->json([
            'success' => true,
            'message' => 'Payload slip voucher thermal berhasil digenerate.',
            'data' => $slipPayload,
        ]);
    }
}
