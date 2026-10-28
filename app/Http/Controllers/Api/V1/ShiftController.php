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
        $cashPaymentQuery = PaymentTransaction::where(function ($sq) use ($activeShift, $shiftStart, $now) {
                $sq->where('payment_transactions.shift_id', $activeShift->id)
                   ->orWhere(function ($tsq) use ($shiftStart, $now) {
                       $tsq->whereNull('payment_transactions.shift_id')
                           ->whereBetween('payment_transactions.transacted_at', [$shiftStart, $now]);
                   });
            })
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
        $baseShiftNumber = sprintf('SHF-%s-%s', $branch->code ?? 'CBG', $datePrefix);
        $latestShift = CashDrawerShift::withoutGlobalScopes()
            ->where('shift_number', 'like', "{$baseShiftNumber}-%")
            ->latest('id')
            ->first();
        $nextSeq = 1;
        if ($latestShift && preg_match('/-(\d{4})$/', $latestShift->shift_number, $matches)) {
            $nextSeq = (int)$matches[1] + 1;
        }
        $shiftNumber = sprintf('%s-%04d', $baseShiftNumber, $nextSeq);

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
                'branch_name' => $branch->name,
                'opened_by' => $shift->opened_by,
                'opened_by_name' => $user->name,
                'opening_cash_float' => (float)$shift->opening_cash_float,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'status' => $shift->status,
                'closing_notes' => $shift->closing_notes,
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
        $cashPaymentQuery = PaymentTransaction::where(function ($sq) use ($shift, $shiftStart, $now) {
                $sq->where('payment_transactions.shift_id', $shift->id)
                   ->orWhere(function ($tsq) use ($shiftStart, $now) {
                       $tsq->whereNull('payment_transactions.shift_id')
                           ->whereBetween('payment_transactions.transacted_at', [$shiftStart, $now]);
                   });
            })
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

    /**
     * Retrieve paginated history of closed and past cash drawer shifts.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $branchId = $request->get('branch_id')
            ?? $request->header('X-Branch-Id')
            ?? $user?->affiliate_id;

        $query = CashDrawerShift::with(['openedBy', 'closedBy', 'branch'])
            ->orderBy('opened_at', 'desc');

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        if ($branchId && !$request->boolean('all_branches')) {
            $query->where('branch_id', $branchId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        $perPage = min(100, max(5, (int)$request->get('per_page', 20)));
        $shifts = $query->paginate($perPage);

        $formatted = $shifts->getCollection()->map(function ($s) {
            return [
                'id' => $s->id,
                'shift_number' => $s->shift_number,
                'branch_id' => $s->branch_id,
                'branch_name' => $s->branch?->name,
                'opened_by' => $s->opened_by,
                'opened_by_name' => $s->openedBy?->name ?? 'Kasir',
                'closed_by' => $s->closed_by,
                'closed_by_name' => $s->closedBy?->name,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'closed_at' => $s->closed_at?->toIso8601String(),
                'opening_cash_float' => (float)$s->opening_cash_float,
                'expected_cash_total' => $s->expected_cash_total !== null ? (float)$s->expected_cash_total : null,
                'actual_cash_counted' => $s->actual_cash_counted !== null ? (float)$s->actual_cash_counted : null,
                'cash_difference' => $s->cash_difference !== null ? (float)$s->cash_difference : null,
                'difference_reason' => $s->difference_reason,
                'status' => $s->status,
                'closing_notes' => $s->closing_notes,
                'created_at' => $s->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'meta' => [
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }

    /**
     * Retrieve the effective shift schedule and current operational status.
     */
    public function getSchedule(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $branchId = $request->get('branch_id')
            ?? $request->header('X-Branch-Id')
            ?? $user?->affiliate_id;

        $business = \App\Models\Business::find($businessId);
        if (!$business && $branchId) {
            $branch = Affiliate::find($branchId);
            $business = $branch?->business;
        }

        if (!$business) {
            return response()->json([
                'success' => false,
                'message' => 'Bisnis tidak ditemukan.',
            ], 404);
        }

        $schedule = $business->getShiftSchedule($branchId ? (int)$branchId : null);
        $timezone = $schedule['timezone'];
        $now = Carbon::now($timezone);

        $openingTime = $schedule['shift_opening_time'];
        $closingTime = $schedule['shift_closing_time'];
        $graceMinutes = $schedule['shift_grace_period_minutes'];
        $isOvernight = $schedule['is_overnight'];

        // Compute start and end datetime for today's operating cycle
        if ($isOvernight) {
            if ($now->format('H:i') < $closingTime) {
                $shiftOpenDt = Carbon::parse($now->toDateString() . ' ' . $openingTime, $timezone)->subDay();
                $shiftCloseDt = Carbon::parse($now->toDateString() . ' ' . $closingTime, $timezone);
            } else {
                $shiftOpenDt = Carbon::parse($now->toDateString() . ' ' . $openingTime, $timezone);
                $shiftCloseDt = Carbon::parse($now->toDateString() . ' ' . $closingTime, $timezone)->addDay();
            }
        } else {
            $shiftOpenDt = Carbon::parse($now->toDateString() . ' ' . $openingTime, $timezone);
            $shiftCloseDt = Carbon::parse($now->toDateString() . ' ' . $closingTime, $timezone);
        }

        $overdueDt = $shiftCloseDt->copy()->addMinutes($graceMinutes);
        $closingSoonDt = $shiftCloseDt->copy()->subMinutes(60);

        // Check if there is an active open shift
        $activeShift = CashDrawerShift::where('status', 'open')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->latest('opened_at')
            ->first();

        $operationalState = 'not_opened';
        $statusMessage = 'Shift belum dibuka untuk hari ini.';

        if ($activeShift) {
            if ($now->greaterThan($overdueDt)) {
                $operationalState = 'closing_overdue';
                $statusMessage = 'Shift kasir telah melewati batas jam tutup operasional (' . $closingTime . '). Segera lakukan tutup shift.';
            } elseif ($now->greaterThanOrEqualTo($closingSoonDt)) {
                $operationalState = 'closing_soon';
                $statusMessage = 'Mendekati jam tutup operasional (' . $closingTime . '). Siapkan perhitungan kas fisik laci kasir.';
            } else {
                $operationalState = 'open';
                $statusMessage = 'Shift kasir sedang aktif melayani transaksi.';
            }
        } else {
            // Check if shift was closed today
            $closedShiftToday = CashDrawerShift::where('status', 'closed')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->whereBetween('closed_at', [$shiftOpenDt, $now])
                ->latest('closed_at')
                ->first();

            if ($closedShiftToday && $now->greaterThanOrEqualTo($shiftCloseDt)) {
                $operationalState = 'closed';
                $statusMessage = 'Shift kasir untuk sesi ini telah ditutup.';
            } elseif ($now->greaterThanOrEqualTo($shiftOpenDt)) {
                $operationalState = 'opening_required';
                $statusMessage = 'Jam operasional telah dimulai (' . $openingTime . '). Wajib membuka shift kasir sebelum memproses booking baru.';
            } else {
                $operationalState = 'not_opened';
                $statusMessage = 'Belum memasuki jam operasional shift (' . $openingTime . ' - ' . $closingTime . ').';
            }
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($schedule, [
                'current_time' => $now->toIso8601String(),
                'scheduled_open_at' => $shiftOpenDt->toIso8601String(),
                'scheduled_close_at' => $shiftCloseDt->toIso8601String(),
                'overdue_threshold_at' => $overdueDt->toIso8601String(),
                'operational_state' => $operationalState,
                'status_message' => $statusMessage,
                'has_active_shift' => $activeShift !== null,
                'active_shift_id' => $activeShift?->id,
            ]),
        ]);
    }

    /**
     * Update shift schedule configuration (Owner & Admin only).
     */
    public function updateSchedule(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->isAdmin()) : ($user && ($user->isSuperAdmin() || $user->isPlatformAdmin()));

        if (!$isOwnerOrAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya Owner dan Admin yang dapat mengubah setelan jadwal shift.',
            ], 403);
        }

        $validated = $request->validate([
            'shift_enforcement_enabled' => 'nullable|boolean',
            'shift_opening_time' => ['nullable', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'shift_closing_time' => ['nullable', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'shift_grace_period_minutes' => 'nullable|integer|min:0|max:240',
            'branch_id' => 'nullable|integer|exists:affiliates,id',
            'branch_opening_override' => ['nullable', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'branch_closing_override' => ['nullable', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $business = \App\Models\Business::findOrFail($businessId);

        $updates = [];
        if (isset($validated['shift_enforcement_enabled'])) {
            $updates['shift_enforcement_enabled'] = (bool)$validated['shift_enforcement_enabled'];
        }
        if (!empty($validated['shift_opening_time'])) {
            $updates['shift_opening_time'] = $validated['shift_opening_time'];
        }
        if (!empty($validated['shift_closing_time'])) {
            $updates['shift_closing_time'] = $validated['shift_closing_time'];
        }
        if (isset($validated['shift_grace_period_minutes'])) {
            $updates['shift_grace_period_minutes'] = (int)$validated['shift_grace_period_minutes'];
        }

        if (!empty($updates)) {
            $business->update($updates);
        }

        if (!empty($validated['branch_id'])) {
            $branch = Affiliate::where('id', $validated['branch_id'])
                ->where('business_id', $businessId)
                ->firstOrFail();

            $branch->update([
                'shift_opening_time_override' => $validated['branch_opening_override'] ?? null,
                'shift_closing_time_override' => $validated['branch_closing_override'] ?? null,
            ]);
        }

        AuditLogService::log(
            $user,
            'shift_schedule.updated',
            'Business',
            $business->slug,
            $updates
        );

        return $this->getSchedule($request);
    }
}
