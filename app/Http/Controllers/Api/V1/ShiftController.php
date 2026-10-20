<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\BranchExpense;
use App\Models\CashDrawerShift;
use App\Models\PaymentTransaction;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    /**
     * Retrieve the active open cash drawer shift for the user's branch.
     */
    public function currentShift(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $branchId = $request->get('branch_id')
            ?? $request->header('X-Branch-Id')
            ?? $user->affiliate_id;

        if (!$branchId && $businessId) {
            $defaultBranch = Affiliate::where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('is_central', 'desc')
                ->first();
            $branchId = $defaultBranch?->id;
        }

        if (!$branchId) {
            return response()->json([
                'success' => false,
                'message' => 'Cabang tidak ditentukan.',
            ], 422);
        }

        $activeShift = CashDrawerShift::with(['openedBy', 'branch'])
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if (!$activeShift) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_active_shift' => false,
                    'shift' => null,
                ],
            ]);
        }

        $shiftStart = $activeShift->opened_at;
        $now = Carbon::now();

        // Calculate real-time net cash within this shift
        $cashPaymentQuery = PaymentTransaction::whereBetween('transacted_at', [$shiftStart, $now])
            ->where('status', '!=', 'void')
            ->where(function ($q) use ($branchId) {
                $q->where('payment_transactions.branch_id', $branchId)
                  ->orWhere(function ($fb) use ($branchId) {
                      $fb->whereNull('payment_transactions.branch_id')
                         ->whereHas('booking', fn($bq) => $bq->where('affiliate_id', $branchId));
                  });
            })
            ->whereHas('paymentMethod', function ($mq) {
                $mq->whereRaw('LOWER(code) = ?', ['cash'])
                   ->orWhereRaw('LOWER(name) LIKE ?', ['%tunai%']);
            });

        $cashInflow = (float)(clone $cashPaymentQuery)
            ->where('type', '!=', 'refund')
            ->where(function ($q) {
                $q->whereNull('direction')->orWhere('direction', '!=', 'outflow');
            })
            ->sum('amount');

        $cashRefund = (float)(clone $cashPaymentQuery)
            ->where(function ($q) {
                $q->where('type', 'refund')->orWhere('direction', 'outflow');
            })
            ->sum('amount');

        $netCashRevenue = $cashInflow - $cashRefund;

        // Disbursed cash drawer expenses during this shift
        $disbursedCashExpenses = (float)BranchExpense::whereBetween('disbursed_at', [$shiftStart, $now])
            ->where('branch_id', $branchId)
            ->where('payment_source', 'cash_drawer')
            ->where('status', 'disbursed')
            ->sum('amount');

        $expectedCash = max(0.00, (float)$activeShift->opening_cash_float + $netCashRevenue - $disbursedCashExpenses);

        return response()->json([
            'success' => true,
            'data' => [
                'has_active_shift' => true,
                'shift' => [
                    'id' => $activeShift->id,
                    'shift_number' => $activeShift->shift_number,
                    'branch_id' => $activeShift->branch_id,
                    'branch_name' => $activeShift->branch?->name,
                    'opened_by_name' => $activeShift->openedBy?->name ?? 'Kasir',
                    'opened_at' => $activeShift->opened_at->toIso8601String(),
                    'opening_cash_float' => (float)$activeShift->opening_cash_float,
                    'current_cash_inflow' => $cashInflow,
                    'current_cash_refund' => $cashRefund,
                    'current_cash_expenses' => $disbursedCashExpenses,
                    'expected_cash_now' => $expectedCash,
                    'status' => $activeShift->status,
                ],
            ],
        ]);
    }

    /**
     * Open a new cash drawer shift with opening float (modal awal).
     */
    public function openShift(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_cash_float' => 'required|numeric|min:0',
            'branch_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $branchId = $validated['branch_id']
            ?? $request->header('X-Branch-Id')
            ?? $user->affiliate_id;

        if (!$branchId && $businessId) {
            $defaultBranch = Affiliate::where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('is_central', 'desc')
                ->first();
            $branchId = $defaultBranch?->id;
        }

        if (!$branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['Cabang tidak dapat ditentukan.'],
            ]);
        }

        $branch = Affiliate::find($branchId);
        if (!$branch) {
            throw ValidationException::withMessages([
                'branch_id' => ['Cabang tidak ditemukan.'],
            ]);
        }

        // Check if an open shift already exists
        $existingShift = CashDrawerShift::where('branch_id', $branchId)
            ->where('status', 'open')
            ->first();

        if ($existingShift) {
            throw ValidationException::withMessages([
                'shift' => ['Shift kasir di cabang ini masih terbuka (Shift: ' . $existingShift->shift_number . '). Harap lakukan tutup shift terlebih dahulu.'],
            ]);
        }

        $datePrefix = Carbon::now()->format('ymd');
        $countToday = CashDrawerShift::withoutGlobalScopes()
            ->whereDate('created_at', Carbon::today())
            ->count() + 1;
        $shiftNumber = sprintf('SHF-%s-%s-%04d', $branch->code ?? 'CBG', $datePrefix, $countToday);

        $shift = CashDrawerShift::create([
            'business_id' => $businessId ?? $branch->business_id,
            'branch_id' => $branch->id,
            'shift_number' => $shiftNumber,
            'opened_by' => $user->id,
            'opened_at' => Carbon::now(),
            'opening_cash_float' => (float)$validated['opening_cash_float'],
            'status' => 'open',
            'closing_notes' => $validated['notes'] ?? null,
        ]);

        AuditLogService::log(
            $user,
            'cash_shift.opened',
            'CashDrawerShift',
            $shift->shift_number,
            [
                'opening_float' => $shift->opening_cash_float,
                'branch' => $branch->name,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Shift kasir berhasil dibuka.',
            'data' => [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'branch_id' => $shift->branch_id,
                'opening_cash_float' => (float)$shift->opening_cash_float,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'status' => $shift->status,
            ],
        ], 201);
    }

    /**
     * Close the current active shift with physical cash count and variance logging.
     */
    public function closeShift(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'actual_cash_counted' => 'required|numeric|min:0',
            'difference_reason' => 'nullable|string',
            'closing_notes' => 'nullable|string',
            'shift_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $shiftQuery = CashDrawerShift::where('status', 'open');
        if (!empty($validated['shift_id'])) {
            $shiftQuery->where('id', $validated['shift_id']);
        } else {
            $branchId = $request->header('X-Branch-Id') ?? $user->affiliate_id;
            if ($branchId) {
                $shiftQuery->where('branch_id', $branchId);
            }
        }

        $shift = $shiftQuery->latest('opened_at')->first();

        if (!$shift) {
            throw ValidationException::withMessages([
                'shift' => ['Tidak ada shift kasir yang sedang aktif untuk ditutup.'],
            ]);
        }

        $now = Carbon::now();
        $shiftStart = $shift->opened_at;
        $branchId = $shift->branch_id;

        // Calculate cash inflow & refund during this shift
        $cashPaymentQuery = PaymentTransaction::whereBetween('transacted_at', [$shiftStart, $now])
            ->where('status', '!=', 'void')
            ->where(function ($q) use ($branchId) {
                $q->where('payment_transactions.branch_id', $branchId)
                  ->orWhere(function ($fb) use ($branchId) {
                      $fb->whereNull('payment_transactions.branch_id')
                         ->whereHas('booking', fn($bq) => $bq->where('affiliate_id', $branchId));
                  });
            })
            ->whereHas('paymentMethod', function ($mq) {
                $mq->whereRaw('LOWER(code) = ?', ['cash'])
                   ->orWhereRaw('LOWER(name) LIKE ?', ['%tunai%']);
            });

        $cashInflow = (float)(clone $cashPaymentQuery)
            ->where('type', '!=', 'refund')
            ->where(function ($q) {
                $q->whereNull('direction')->orWhere('direction', '!=', 'outflow');
            })
            ->sum('amount');

        $cashRefund = (float)(clone $cashPaymentQuery)
            ->where(function ($q) {
                $q->where('type', 'refund')->orWhere('direction', 'outflow');
            })
            ->sum('amount');

        $disbursedCashExpenses = (float)BranchExpense::whereBetween('disbursed_at', [$shiftStart, $now])
            ->where('branch_id', $branchId)
            ->where('payment_source', 'cash_drawer')
            ->where('status', 'disbursed')
            ->sum('amount');

        $expectedCash = max(0.00, (float)$shift->opening_cash_float + $cashInflow - $cashRefund - $disbursedCashExpenses);
        $actualCounted = (float)$validated['actual_cash_counted'];
        $cashDifference = $actualCounted - $expectedCash;

        if (abs($cashDifference) > 5000 && empty($validated['difference_reason'])) {
            throw ValidationException::withMessages([
                'difference_reason' => [
                    'Terdapat selisih kas fisik sebesar ' . number_format($cashDifference, 0, ',', '.') . 
                    '. Keterangan/alasan selisih kas wajib diisi sebelum shift dapat ditutup.'
                ],
            ]);
        }

        $shift->update([
            'closed_by' => $user->id,
            'closed_at' => $now,
            'expected_cash_total' => $expectedCash,
            'actual_cash_counted' => $actualCounted,
            'cash_difference' => $cashDifference,
            'difference_reason' => $validated['difference_reason'] ?? null,
            'status' => 'closed',
            'closing_notes' => $validated['closing_notes'] ?? null,
        ]);

        AuditLogService::log(
            $user,
            'cash_shift.closed',
            'CashDrawerShift',
            $shift->shift_number,
            [
                'expected' => $expectedCash,
                'actual' => $actualCounted,
                'difference' => $cashDifference,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Shift kasir berhasil ditutup.',
            'data' => [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'closed_at' => $shift->closed_at->toIso8601String(),
                'opening_cash_float' => (float)$shift->opening_cash_float,
                'cash_inflow' => $cashInflow,
                'cash_refund' => $cashRefund,
                'cash_expenses' => $disbursedCashExpenses,
                'expected_cash_total' => $expectedCash,
                'actual_cash_counted' => $actualCounted,
                'cash_difference' => $cashDifference,
                'difference_reason' => $shift->difference_reason,
                'status' => $shift->status,
            ],
        ]);
    }
}
