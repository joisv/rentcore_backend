<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\BranchExpense;
use App\Models\CashDrawerShift;
use App\Models\PaymentTransaction;
use App\Services\AuditLogService;
use App\Services\IdempotencyService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
            ?? $tenantContext->getBranchId()
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

        if ($denied = $this->authorizeBranchAccess($request, (int)$branchId, $businessId)) {
            return $denied;
        }

        $business = $businessId
            ? \App\Models\Business::find($businessId)
            : Affiliate::find($branchId)?->business;
        $enforcementEnabled = (bool)($business?->shift_enforcement_enabled ?? true);

        $activeShift = CashDrawerShift::with(['openedBy', 'branch'])
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if (!$activeShift) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_active_shift' => false,
                    'shift_enforcement_enabled' => $enforcementEnabled,
                    'shift' => null,
                ],
            ]);
        }

        // True Blind Close: while shift status === 'open', redact expected cash and cash movement totals
        // so cashiers count physical cash without knowing the target figure beforehand.
        // Any non-blind expectation must go through $this->calculateExpectedCash($activeShift).
        return response()->json([
            'success' => true,
            'data' => [
                'has_active_shift' => true,
                'shift_enforcement_enabled' => $enforcementEnabled,
                'shift' => [
                    'id' => $activeShift->id,
                    'shift_number' => $activeShift->shift_number,
                    'branch_id' => $activeShift->branch_id,
                    'branch_name' => $activeShift->branch?->name,
                    'opened_by_name' => $activeShift->openedBy?->name ?? 'Kasir',
                    'opened_at' => $activeShift->opened_at->toIso8601String(),
                    'opening_cash_float' => (float)$activeShift->opening_cash_float,
                    'status' => $activeShift->status,
                    'is_blind_close' => true,
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

        return IdempotencyService::execute(
            $request,
            $businessId ? (int) $businessId : null,
            'shift.close',
            function () use ($request, $validated, $user, $tenantContext, $businessId): JsonResponse {
        $shiftQuery = CashDrawerShift::where('status', 'open')
            ->when($businessId, fn($q) => $q->where('business_id', $businessId));

        if (!empty($validated['shift_id'])) {
            $shiftQuery->where('id', $validated['shift_id']);
        } else {
            $branchId = $tenantContext->isBranchRestricted()
                ? $tenantContext->getBranchId()
                : ($request->header('X-Branch-Id') ?? $tenantContext->getBranchId() ?? $user->affiliate_id);

            if ($branchId) {
                if ($denied = $this->authorizeBranchAccess($request, (int)$branchId, $businessId)) {
                    return $denied;
                }
                $shiftQuery->where('branch_id', $branchId);
            }
        }

        $shift = $shiftQuery->latest('opened_at')->first();

        if (!$shift) {
            throw ValidationException::withMessages([
                'shift' => ['Tidak ada shift kasir yang sedang aktif untuk ditutup.'],
            ]);
        }

        if ($denied = $this->authorizeBranchAccess($request, (int)$shift->branch_id, $businessId)) {
            return $denied;
        }

        $now = Carbon::now();
        $cashSummary = $this->calculateExpectedCash($shift);
        $totalTransactions = $cashSummary['total_transactions'];
        $cashInflow = $cashSummary['cash_inflow'];
        $cashRefund = $cashSummary['cash_outflow'];
        $nonCashInflow = $cashSummary['non_cash_inflow'];
        $disbursedCashExpenses = $cashSummary['cash_expenses'];
        $expectedCash = $cashSummary['expected_cash'];

        $actualCounted = round((float)$validated['actual_cash_counted'], 2);
        $cashDifference = round($actualCounted - $expectedCash, 2);

        $tolerance = 5000;
        if (abs($cashDifference) > $tolerance && empty(trim((string)($validated['difference_reason'] ?? '')))) {
            $neutralMessage = 'Hitungan kas fisik tidak sesuai dengan catatan sistem. Isi keterangan/alasan selisih kas untuk menyelesaikan tutup shift.';

            return response()->json([
                'success' => false,
                'message' => $neutralMessage,
                'requires_difference_reason' => true,
                'errors' => [
                    'difference_reason' => [$neutralMessage],
                ],
            ], 422);
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

        $shift->loadMissing(['branch', 'openedBy', 'closedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Shift kasir berhasil ditutup.',
            'data' => [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'branch_id' => $shift->branch_id,
                'branch_name' => $shift->branch?->name,
                'opened_by_name' => $shift->openedBy?->name ?? $user->name,
                'closed_by_name' => $user->name,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'closed_at' => $shift->closed_at->toIso8601String(),
                'opening_cash_float' => (float)$shift->opening_cash_float,
                'total_transactions' => $totalTransactions,
                'cash_inflow' => $cashInflow,
                'cash_outflow' => $cashRefund,
                'non_cash_inflow' => $nonCashInflow,
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
        );
    }

    /**
     * Single source of truth for shift physical cash drawer expectation.
     *
     * Strictly scopes payment_transactions to the shift's business, branch, shift_id,
     * completed status, and CASH payment method code (excluding QRIS/e-wallets/bank transfers).
     */
    private function calculateExpectedCash(CashDrawerShift $shift): array
    {
        $baseQuery = PaymentTransaction::where('payment_transactions.business_id', $shift->business_id)
            ->where('payment_transactions.branch_id', $shift->branch_id)
            ->where('payment_transactions.shift_id', $shift->id)
            ->where('payment_transactions.status', 'completed');

        $totalTransactions = (int) (clone $baseQuery)->count();

        $nonCashInflow = (float) (clone $baseQuery)
            ->where('payment_transactions.direction', 'inflow')
            ->whereHas('paymentMethod', function ($mq) {
                $mq->whereRaw('UPPER(code) != ?', ['CASH']);
            })
            ->sum('amount');

        $cashPaymentQuery = (clone $baseQuery)
            ->whereHas('paymentMethod', function ($mq) {
                $mq->whereRaw('UPPER(code) = ?', ['CASH']);
            });

        $cashInflow = (float) (clone $cashPaymentQuery)
            ->where('payment_transactions.direction', 'inflow')
            ->sum('amount');

        $cashOutflow = (float) (clone $cashPaymentQuery)
            ->where('payment_transactions.direction', 'outflow')
            ->sum('amount');

        $expenseQuery = BranchExpense::where('branch_id', $shift->branch_id)
            ->where('payment_source', 'cash_drawer')
            ->where('status', 'disbursed');

        if (Schema::hasColumn('branch_expenses', 'shift_id')) {
            $expenseQuery->where('shift_id', $shift->id);
        } else {
            $expenseQuery->whereBetween('disbursed_at', [
                $shift->opened_at,
                $shift->closed_at ?? Carbon::now(),
            ]);
        }

        $cashExpenses = (float) $expenseQuery->sum('amount');
        $expectedCash = round(
            (float) $shift->opening_cash_float + $cashInflow - $cashOutflow - $cashExpenses,
            2
        );

        return [
            'total_transactions' => $totalTransactions,
            'cash_inflow' => round($cashInflow, 2),
            'cash_outflow' => round($cashOutflow, 2),
            'non_cash_inflow' => round($nonCashInflow, 2),
            'cash_expenses' => round($cashExpenses, 2),
            'expected_cash' => $expectedCash,
        ];
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

        $perPage = max(1, min(50, (int) $request->get('per_page', 20)));
        $shifts = $query->paginate($perPage);

        $formatted = $shifts->getCollection()->map(function ($s) {
            $isOpen = $s->status === 'open';
            $row = [
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
                'difference_reason' => $s->difference_reason,
                'status' => $s->status,
                'is_blind_close' => $isOpen,
                'closing_notes' => $s->closing_notes,
                'created_at' => $s->created_at?->toIso8601String(),
            ];

            if (!$isOpen) {
                $row['expected_cash_total'] = $s->expected_cash_total !== null ? (float)$s->expected_cash_total : null;
                $row['actual_cash_counted'] = $s->actual_cash_counted !== null ? (float)$s->actual_cash_counted : null;
                $row['cash_difference'] = $s->cash_difference !== null ? (float)$s->cash_difference : null;
            }

            return $row;
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

    /**
     * Ensure the authenticated user is authorized to inspect or operate a branch's shift.
     */
    private function authorizeBranchAccess(Request $request, int $branchId, ?int $businessId): ?JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);

        if ($user && $user->isSuperAdmin()) {
            return null;
        }

        if ($businessId) {
            $branchExists = Affiliate::withoutGlobalScopes()
                ->where('id', $branchId)
                ->where('business_id', $businessId)
                ->exists();
            if (!$branchExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki hak akses ke cabang ini.',
                ], 403);
            }
        }

        if ($tenantContext->isBranchRestricted() && (int) $tenantContext->getBranchId() !== $branchId) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses ke cabang ini.',
            ], 403);
        }

        if ($user && $businessId) {
            $membership = $user->getBusinessMembership($businessId);
            if ($membership && !$membership->isOwner()) {
                $allowedBranches = array_map('intval', $user->getBranchIdsForBusiness($businessId));
                if (!empty($allowedBranches) && !in_array($branchId, $allowedBranches, true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki hak akses ke cabang ini.',
                    ], 403);
                }
            } elseif (!$membership && $user->affiliate_id && (int) $user->affiliate_id !== $branchId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki hak akses ke cabang ini.',
                ], 403);
            }
        } elseif ($user && $user->affiliate_id && (int) $user->affiliate_id !== $branchId) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses ke cabang ini.',
            ], 403);
        }

        return null;
    }
}
