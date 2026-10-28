<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\BranchExpense;
use App\Models\BusinessMember;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ShiftEnforcementService;
use App\Services\TimezoneService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    /**
     * Retrieve paginated list of branch operating expenses with date range and summary.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();
        $isSuperAdmin = $user && $user->isSuperAdmin();

        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwner = $member ? $member->isOwner() : ($isSuperAdmin || ($user && $user->isPlatformAdmin()));

        // Resolve requested branch
        $isAllBranches = $request->boolean('all_branches')
            || $request->get('branch_id') === 'all'
            || $request->get('branch_id') === '-1';

        $rawBranchId = $isAllBranches ? null : ($request->get('branch_id') ?? $request->get('affiliate_id') ?? $request->header('X-Branch-Id'));
        $requestedBranchId = ($rawBranchId !== null && $rawBranchId !== '' && $rawBranchId !== 'all') ? (int)$rawBranchId : null;
        $targetBranchId = null;

        $isCentralBranchStaff = false;
        if ($user && $user->affiliate_id) {
            $userAffiliate = Affiliate::find($user->affiliate_id);
            $isCentralBranchStaff = (bool)($userAffiliate?->is_central ?? false);
        }
        $canAccessConsolidated = $isOwner || $isSuperAdmin || $isCentralBranchStaff;

        if (!$canAccessConsolidated) {
            $allowedBranches = $user ? $user->getBranchIdsForBusiness($businessId) : [];
            if (empty($allowedBranches) && $user && $user->affiliate_id) {
                $allowedBranches = [(int)$user->affiliate_id];
            }

            if ($isAllBranches) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses cabang ditolak. Anda tidak berwenang melihat pengeluaran konsolidasi seluruh cabang.',
                    'code' => 'BRANCH_ACCESS_DENIED',
                ], 403);
            }

            if ($requestedBranchId !== null) {
                if (!in_array($requestedBranchId, $allowedBranches, true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Akses cabang ditolak. Anda tidak berwenang melihat pengeluaran cabang lain.',
                        'code' => 'BRANCH_ACCESS_DENIED',
                    ], 403);
                }
                $targetBranchId = $requestedBranchId;
            } else {
                $targetBranchId = !empty($allowedBranches) ? $allowedBranches[0] : ($user && $user->affiliate_id ? (int)$user->affiliate_id : null);
            }
        } else {
            if (!$isAllBranches && $requestedBranchId !== null) {
                if ($businessId) {
                    $branchValid = Affiliate::where('id', $requestedBranchId)
                        ->where('business_id', $businessId)
                        ->where('is_active', true)
                        ->exists();

                    if (!$branchValid) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cabang tidak valid untuk bisnis ini.',
                            'code' => 'INVALID_BRANCH',
                        ], 422);
                    }
                }
                $targetBranchId = $requestedBranchId;
            } else {
                $targetBranchId = null; // Consolidated
            }
        }

        // Date filtering with timezone-awareness
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $dateParam = $request->get('date');

        $business = $businessId ? \App\Models\Business::find($businessId) : null;
        $tz = $business?->timezone ?? 'Asia/Jakarta';
        $bounds = TimezoneService::resolveQueryBounds($startDate, $endDate, $dateParam, $tz);

        $query = BranchExpense::with(['branch', 'requestedBy', 'approvedBy', 'voidBy'])
            ->whereBetween(DB::raw('COALESCE(branch_expenses.disbursed_at, branch_expenses.created_at)'), [$bounds['start_utc'], $bounds['end_utc']]);

        if ($businessId) {
            $query->where('business_id', $businessId);
        }
        if ($targetBranchId !== null) {
            $query->where('branch_id', $targetBranchId);
        }

        $expenses = $query->latest('created_at')->get();

        $totalExpenses = 0.00;
        $cashDrawerExpenses = 0.00;
        $categoryBreakdown = [];

        foreach ($expenses as $exp) {
            if ($exp->status === 'rejected' || $exp->status === 'void') {
                continue;
            }
            $amt = (float)$exp->amount;
            $totalExpenses += $amt;
            if ($exp->payment_source === 'cash_drawer' && in_array($exp->status, ['approved', 'disbursed'])) {
                $cashDrawerExpenses += $amt;
            }

            $cat = $exp->category ?? 'other';
            if (!isset($categoryBreakdown[$cat])) {
                $categoryBreakdown[$cat] = ['count' => 0, 'total' => 0.00];
            }
            $categoryBreakdown[$cat]['count']++;
            $categoryBreakdown[$cat]['total'] += $amt;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'branch_id' => $targetBranchId,
                'is_consolidated' => $targetBranchId === null,
                'start_date' => $bounds['start_date'],
                'end_date' => $bounds['end_date'],
                'date' => $bounds['date_label'],
                'timezone' => $tz,
                'summary' => [
                    'total_expenses' => $totalExpenses,
                    'cash_drawer_expenses' => $cashDrawerExpenses,
                    'count' => $expenses->count(),
                    'by_category' => empty($categoryBreakdown) ? (object)[] : $categoryBreakdown,
                    'categories' => empty($categoryBreakdown) ? (object)[] : $categoryBreakdown,
                ],
                'expenses' => $expenses->map(function ($exp) {
                    return [
                        'id' => $exp->id,
                        'expense_number' => $exp->expense_number,
                        'branch_id' => $exp->branch_id,
                        'branch_name' => $exp->branch?->name ?? 'Cabang',
                        'category' => $exp->category,
                        'title' => $exp->title,
                        'amount' => (float)$exp->amount,
                        'payment_source' => $exp->payment_source,
                        'status' => $exp->status,
                        'requested_by_name' => $exp->requestedBy?->name ?? 'Staff',
                        'approved_by_name' => $exp->approvedBy?->name,
                        'void_by_name' => $exp->voidBy?->name,
                        'void_reason' => $exp->void_reason,
                        'voided_at' => $exp->voided_at?->toIso8601String(),
                        'receipt_image_url' => $exp->receipt_image_path ? url("/api/v1/cashier/expenses/{$exp->id}/receipt-file") : null,
                        'notes' => $exp->notes,
                        'disbursed_at' => $exp->disbursed_at?->toIso8601String(),
                        'created_at' => $exp->created_at?->toIso8601String(),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Record a new branch operating expense.
     * Enforces role authority: Staff > 100k requires Manager PIN.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:100',
            'category' => 'required|in:operational,maintenance,supplies,utilities,marketing,other',
            'payment_source' => 'required|in:cash_drawer,bank_transfer,petty_cash',
            'branch_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'manager_pin' => 'nullable|string',
            'receipt_image' => 'nullable',
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
                'branch_id' => ['Cabang/outlet tidak dapat ditentukan. Harap tentukan cabang aktif.'],
            ]);
        }

        $branch = Affiliate::find($branchId);
        if (!$branch || ($businessId && (int)$branch->business_id !== (int)$businessId)) {
            throw ValidationException::withMessages([
                'branch_id' => ['Cabang tidak valid untuk bisnis ini.'],
            ]);
        }

        $amount = (float)$validated['amount'];
        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->role === 'admin') : ($user->isSuperAdmin() || $user->isOwner());

        $status = 'approved';
        $approvedBy = null;

        $business = $businessId ? \App\Models\Business::find($businessId) : null;
        $threshold = (float)($branch->expense_pin_threshold_override ?? $business?->expense_pin_threshold ?? 100000.00);

        if ($isOwnerOrAdmin) {
            $approvedBy = $user->id;
        } else {
            // Check dynamic threshold for staff
            $requirePin = ($business?->require_expense_pin ?? true) && ($amount > $threshold);

            if (!$requirePin) {
                $approvedBy = $user->id;
            } else {
                if (empty($validated['manager_pin'])) {
                    throw ValidationException::withMessages([
                        'manager_pin' => [
                            'Pengeluaran kas cabang di atas Rp ' . number_format($threshold, 0, ',', '.') . 
                            ' (Rp ' . number_format($amount, 0, ',', '.') . 
                            ') wajib diverifikasi menggunakan PIN Manager Cabang.'
                        ],
                    ]);
                }

                $throttleKey = 'manager_pin_verify:biz_' . ($businessId ?? 0) . ':user_' . $user->id;
                if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                    $seconds = RateLimiter::availableIn($throttleKey);
                    AuditLogService::log(
                        $user,
                        'security.pin_brute_force_lockout',
                        'BranchExpense',
                        'LOCKOUT',
                        ['seconds_remaining' => $seconds, 'ip' => $request->ip()],
                        null,
                        $request->ip(),
                        $branchId
                    );
                    return response()->json([
                        'success' => false,
                        'message' => 'Terlalu banyak percobaan PIN Manager yang salah. Akses PIN dikunci sementara. Silakan coba lagi dalam ' . ceil($seconds / 60) . ' menit.',
                        'code' => 'PIN_RATE_LIMITED',
                        'retry_after' => $seconds,
                    ], 429);
                }

                $managers = User::where(function ($q) use ($branchId, $businessId) {
                        $q->where('affiliate_id', $branchId);
                        if ($businessId) {
                            $q->orWhereHas('businessMembers', function ($bm) use ($businessId) {
                                $bm->where('business_id', $businessId)->whereIn('role', ['owner', 'admin']);
                            });
                        }
                    })
                    ->whereNotNull('manager_override_pin')
                    ->get();

                $matchingManager = $managers->first(function ($mgr) use ($validated) {
                    return password_verify($validated['manager_pin'], $mgr->manager_override_pin);
                });

                if (!$matchingManager) {
                    RateLimiter::hit($throttleKey, 900);
                    $remaining = 5 - RateLimiter::attempts($throttleKey);
                    throw ValidationException::withMessages([
                        'manager_pin' => ['PIN Manager Cabang tidak valid.' . ($remaining > 0 ? " Sisa percobaan: {$remaining} kali." : ' Akun Anda dikunci sementara.')],
                    ]);
                }

                RateLimiter::clear($throttleKey);
                $approvedBy = $matchingManager->id;
            }
        }

        // Handle image upload if provided
        $imagePath = null;
        if ($request->hasFile('receipt_image')) {
            $imagePath = $request->file('receipt_image')->store('receipt_expenses', 'public');
        } elseif (!empty($validated['receipt_image']) && is_string($validated['receipt_image'])) {
            $photoData = $validated['receipt_image'];
            $ext = 'jpg';
            if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $matches)) {
                $photoData = substr($photoData, strpos($photoData, ',') + 1);
                $ext = strtolower($matches[1]);
            }
            $decoded = base64_decode($photoData);
            if ($decoded !== false) {
                $filename = 'exp_' . time() . '_' . Str::random(8) . '.' . $ext;
                $imagePath = 'receipt_expenses/' . $filename;
                Storage::disk('local')->put($imagePath, $decoded);
            }
        }

        $datePrefix = Carbon::now()->format('ymd');
        $countToday = BranchExpense::where('business_id', $businessId)
            ->whereDate('created_at', Carbon::today())
            ->count() + 1;
        $expenseNumber = sprintf('EXP-%s-%s-%04d-%s', $branch->code ?? 'CBG', $datePrefix, $countToday, strtoupper(Str::random(3)));

        $disbursedAt = null;
        if ($status === 'approved' && $validated['payment_source'] === 'cash_drawer') {
            ShiftEnforcementService::requireActiveShift(
                (int)$branch->id,
                $user,
                true,
                'mencairkan kas pengeluaran dari laci kasir'
            );
            $status = 'disbursed';
            $disbursedAt = Carbon::now();
        }

        $expense = BranchExpense::create([
            'business_id' => $businessId ?? $branch->business_id,
            'branch_id' => $branch->id,
            'expense_number' => $expenseNumber,
            'category' => $validated['category'],
            'title' => $validated['title'],
            'amount' => $amount,
            'payment_source' => $validated['payment_source'],
            'status' => $status,
            'requested_by' => $user->id,
            'approved_by' => $approvedBy,
            'disbursed_at' => $disbursedAt,
            'receipt_image_path' => $imagePath,
            'notes' => $validated['notes'] ?? null,
        ]);

        AuditLogService::log(
            $user,
            'branch_expense.created',
            'BranchExpense',
            $expense->expense_number,
            [
                'title' => $expense->title,
                'amount' => $expense->amount,
                'category' => $expense->category,
                'source' => $expense->payment_source,
                'status' => $expense->status,
                'branch' => $branch->name,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Pengeluaran cabang berhasil dicatat.',
            'data' => [
                'id' => $expense->id,
                'expense_number' => $expense->expense_number,
                'amount' => (float)$expense->amount,
                'status' => $expense->status,
                'payment_source' => $expense->payment_source,
                'disbursed_at' => $expense->disbursed_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Approve pending branch expense.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $expense = BranchExpense::findOrFail($id);

        $member = app(TenantContext::class)->getMember() ?? $user->getBusinessMembership($expense->business_id);
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->role === 'admin') : ($user->isSuperAdmin() || $user->isOwner());

        if (!$isOwnerOrAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Manager atau Owner yang berwenang menyetujui pengeluaran cabang.',
            ], 403);
        }

        if ($expense->payment_source === 'cash_drawer') {
            ShiftEnforcementService::requireActiveShift(
                (int)$expense->branch_id,
                $user,
                true,
                'mencairkan kas pengeluaran dari laci kasir'
            );
        }

        $newStatus = ($expense->payment_source === 'cash_drawer') ? 'disbursed' : 'approved';
        $disbursedAt = ($newStatus === 'disbursed') ? Carbon::now() : $expense->disbursed_at;

        $expense->update([
            'status' => $newStatus,
            'approved_by' => $user->id,
            'disbursed_at' => $disbursedAt,
        ]);

        AuditLogService::log(
            $user,
            'branch_expense.approved',
            'BranchExpense',
            $expense->expense_number,
            ['approved_by' => $user->name, 'new_status' => $newStatus]
        );

        return response()->json([
            'success' => true,
            'message' => 'Pengeluaran cabang berhasil disetujui.',
            'data' => $expense,
        ]);
    }

    /**
     * Void a branch expense.
     * Enforces authority (Manager PIN or Owner/Admin role), marks status void,
     * records void metadata, and writes security audit log.
     */
    public function voidExpense(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:5|max:255',
            'manager_pin' => 'nullable|string',
        ]);

        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? $user->current_business_id ?? $user->business_id;

        $expense = BranchExpense::where('id', $id)
            ->where(function ($q) use ($businessId) {
                if ($businessId) {
                    $q->where('business_id', $businessId);
                }
            })
            ->firstOrFail();

        if ($expense->status === 'void') {
            return response()->json([
                'success' => false,
                'message' => 'Pengeluaran ini sudah dibatalkan (void) sebelumnya.',
            ], 422);
        }

        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->role === 'admin') : ($user->isSuperAdmin() || $user->isOwner());

        $voidedByUserId = $user->id;

        if (!$isOwnerOrAdmin) {
            if (empty($request->manager_pin)) {
                throw ValidationException::withMessages([
                    'manager_pin' => ['Pembatalan pengeluaran kas wajib disetujui menggunakan PIN Manager Cabang.'],
                ]);
            }

            $branchId = $expense->branch_id ?? $user->affiliate_id;
            $throttleKey = 'manager_pin_verify:biz_' . ($businessId ?? 0) . ':user_' . $user->id;

            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan PIN salah. Silakan coba lagi dalam ' . ceil($seconds / 60) . ' menit.',
                    'code' => 'PIN_RATE_LIMITED',
                    'retry_after' => $seconds,
                ], 429);
            }

            $managers = User::where(function ($q) use ($branchId, $businessId) {
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

            $matchingManager = $managers->first(function ($mgr) use ($request) {
                return password_verify($request->manager_pin, $mgr->manager_override_pin);
            });

            if (!$matchingManager) {
                RateLimiter::hit($throttleKey, 900);
                $remaining = 5 - RateLimiter::attempts($throttleKey);
                throw ValidationException::withMessages([
                    'manager_pin' => ['PIN Manager Cabang tidak valid.' . ($remaining > 0 ? " Sisa percobaan: {$remaining}." : '')],
                ]);
            }

            RateLimiter::clear($throttleKey);
            $voidedByUserId = $matchingManager->id;
        }

        $expense->update([
            'status' => 'void',
            'void_reason' => $request->reason,
            'void_by' => $voidedByUserId,
            'voided_at' => Carbon::now(),
        ]);

        AuditLogService::log(
            $user,
            'branch_expense.voided',
            'BranchExpense',
            $expense->expense_number,
            [
                'reason' => $request->reason,
                'amount' => (float)$expense->amount,
                'void_by' => $voidedByUserId,
            ],
            null,
            request()?->ip(),
            $expense->branch_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Pengeluaran ' . $expense->expense_number . ' berhasil dibatalkan (void).',
            'data' => $expense->fresh(['requestedBy', 'approvedBy', 'voidBy']),
        ]);
    }

    /**
     * Retrieve expense receipt photo document securely under tenant authorization.
     */
    public function getReceiptFile(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        $expense = BranchExpense::findOrFail($id);
        if (empty($expense->receipt_image_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Foto bukti pengeluaran tidak ditemukan.',
            ], 404);
        }

        $disk = 'local';
        if (!Storage::disk('local')->exists($expense->receipt_image_path) && Storage::disk('public')->exists($expense->receipt_image_path)) {
            $disk = 'public'; // Backward compatibility for legacy uploads
        }

        if (!Storage::disk($disk)->exists($expense->receipt_image_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File bukti fisik tidak ditemukan pada server.',
            ], 404);
        }

        return Storage::disk($disk)->response($expense->receipt_image_path);
    }
}
