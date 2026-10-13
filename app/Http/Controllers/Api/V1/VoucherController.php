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
     * List active vouchers.
     */
    public function index(Request $request): JsonResponse
    {
        $vouchers = Voucher::active()
            ->with(['affiliate', 'creator'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $vouchers,
        ]);
    }

    /**
     * Super-Admin governance: create new percentage voucher with minimum transaction threshold.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Enforce Super-Admin exclusive authorization
        if (!$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Super-Admin yang memiliki otorisasi untuk membuat voucher diskon sistem.',
            ], 403);
        }

        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:vouchers,code',
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'discount_percentage' => 'required|numeric|min:1|max:100',
            'max_discount_cap' => 'nullable|numeric|min:0',
            'min_transaction_amount' => 'required|numeric|min:0',
            'barcode_type' => 'nullable|in:qr_code,datamatrix,code128',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'affiliate_id' => 'nullable|exists:affiliates,id',
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_customer' => 'nullable|integer|min:1',
        ]);

        $code = !empty($validated['code']) 
            ? strtoupper(trim($validated['code'])) 
            : 'RC-VCR-' . strtoupper(Str::random(6));

        $voucher = Voucher::create([
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
            'message' => 'Voucher diskon berhasil dibuat oleh Super-Admin.',
            'data' => $voucher,
        ], 201);
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
        ]);

        $affiliateId = $request->user()->affiliate_id;

        $result = $this->voucherService->validateVoucher(
            $request->code,
            (float)$request->subtotal,
            $request->customer_id,
            $affiliateId
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
