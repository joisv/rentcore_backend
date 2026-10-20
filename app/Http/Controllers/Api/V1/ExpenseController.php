<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\BranchExpense;
use App\Models\BusinessMember;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // Date filtering
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $dateParam = $request->get('date');

        if ($startDate && $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
        } elseif ($dateParam) {
            $start = Carbon::parse($dateParam)->startOfDay();
            $end = Carbon::parse($dateParam)->endOfDay();
        } else {
            $start = Carbon::today()->startOfDay();
            $end = Carbon::today()->endOfDay();
        }

        $query = BranchExpense::with(['branch', 'requestedBy', 'approvedBy'])
            ->whereBetween('created_at', [$start, $end]);

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
            if ($exp->status === 'rejected') {
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
                'summary' => [
                    'total_expenses' => $totalExpenses,
                    'cash_drawer_expenses' => $cashDrawerExpenses,
                    'count' => $expenses->count(),
                    'by_category' => $categoryBreakdown,
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
                        'receipt_image_url' => $exp->receipt_image_path ? Storage::disk('public')->url($exp->receipt_image_path) : null,
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

        if ($isOwnerOrAdmin) {
            $approvedBy = $user->id;
        } else {
            // Staff limit: <= Rp 100.000 auto-approved
            if ($amount <= 100000.00) {
                $approvedBy = $user->id;
            } else {
                // > 100k requires manager PIN
                if (empty($validated['manager_pin'])) {
                    throw ValidationException::withMessages([
                        'manager_pin' => [
                            'Pengeluaran kas cabang di atas Rp 100.000 (Rp ' . number_format($amount, 0, ',', '.') . 
                            ') wajib diverifikasi menggunakan PIN Manager Cabang.'
                        ],
                    ]);
                }

                $manager = User::where(function ($q) use ($branchId, $businessId) {
                        $q->where('affiliate_id', $branchId);
                        if ($businessId) {
                            $q->orWhereHas('businessMembers', function ($bm) use ($businessId) {
                                $bm->where('business_id', $businessId)->whereIn('role', ['owner', 'admin']);
                            });
                        }
                    })
                    ->whereNotNull('manager_override_pin')
                    ->first();

                if (!$manager || !password_verify($validated['manager_pin'], $manager->manager_override_pin)) {
                    throw ValidationException::withMessages([
                        'manager_pin' => ['PIN Manager Cabang tidak valid.'],
                    ]);
                }
                $approvedBy = $manager->id;
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
                Storage::disk('public')->put($imagePath, $decoded);
            }
        }

        $datePrefix = Carbon::now()->format('ymd');
        $countToday = BranchExpense::withoutGlobalScopes()
            ->whereDate('created_at', Carbon::today())
            ->count() + 1;
        $expenseNumber = sprintf('EXP-%s-%s-%04d', $branch->code ?? 'CBG', $datePrefix, $countToday);

        $disbursedAt = null;
        if ($status === 'approved' && $validated['payment_source'] === 'cash_drawer') {
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
}
