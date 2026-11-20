<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\InventoryTransfer;
use App\Models\StaffResignationRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ResignationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * List resignation requests.
     * Owners see all requests for the business. Staff see only their own.
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ?: $context->getBusinessId();
        $user = $request->user();

        $business = Business::findOrFail($businessId);

        $member = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$member && !$user->isPlatformAdmin() && !$user->isSuperAdmin() && $business->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda bukan anggota aktif dari bisnis rental ini.',
            ], 403);
        }

        $query = StaffResignationRequest::with(['user', 'businessMember.branchMembers.branch', 'decidedBy'])
            ->where('business_id', $businessId);

        if ($business->owner_id === $user->id || $user->isPlatformAdmin() || $user->isSuperAdmin() || ($member && $member->isAdmin())) {
            // Owner / Admin can filter by status
            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }
        } else {
            // Regular staff only see their own requests
            $query->where('user_id', $user->id);
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * Get the authenticated staff member's latest resignation status for a business.
     */
    public function status(Request $request, int $id): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ?: $context->getBusinessId();
        $user = $request->user();

        $business = Business::findOrFail($businessId);

        $member = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$member && $business->owner_id !== $user->id && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda bukan anggota aktif dari bisnis rental ini.',
            ], 403);
        }

        $latest = StaffResignationRequest::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $latest ? [
                'id' => $latest->id,
                'business_id' => $latest->business_id,
                'status' => $latest->status,
                'reason' => $latest->reason,
                'decision_notes' => $latest->decision_notes,
                'rejection_reason' => $latest->decision_notes,
                'created_at' => $latest->created_at,
                'decided_at' => $latest->decided_at,
                'reviewed_at' => $latest->decided_at,
            ] : null,
        ]);
    }

    /**
     * Submit a staff resignation request.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ?: $context->getBusinessId();
        $user = $request->user();

        $business = Business::findOrFail($businessId);

        if ($business->owner_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Owner bisnis tidak dapat mengajukan resign dari bisnis miliknya sendiri.',
            ], 422);
        }

        $member = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Anda bukan staf aktif pada bisnis rental ini.',
            ], 403);
        }

        if ($member->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Owner bisnis tidak dapat mengajukan resign dari bisnis miliknya sendiri.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        // Check if there is already an active pending request
        $existingPending = StaffResignationRequest::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existingPending) {
            return response()->json([
                'success' => false,
                'message' => 'Anda masih memiliki permohonan resign yang sedang menunggu persetujuan Owner.',
                'data' => $existingPending,
            ], 422);
        }

        // Prevent resign submission while user still has an active open cash drawer shift
        $hasOpenShift = CashDrawerShift::where('business_id', $businessId)
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenShift) {
            return response()->json([
                'success' => false,
                'message' => 'Tutup shift kasir aktif Anda terlebih dahulu sebelum mengajukan resign.',
            ], 422);
        }

        $resignation = StaffResignationRequest::create([
            'business_id' => $businessId,
            'business_member_id' => $member->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'reason' => $validated['reason'] ?? null,
        ]);

        AuditLogService::log(
            $user,
            'staff_resignation.submitted',
            'StaffResignationRequest',
            $resignation->id,
            ['reason' => $resignation->reason],
            null,
            $request->ip(),
            null,
            $businessId
        );

        $this->notificationService->notifyResignationSubmitted(
            $resignation->load(['user.affiliate', 'businessMember.branchMembers.branch'])
        );

        return response()->json([
            'success' => true,
            'message' => 'Permohonan resign berhasil diajukan dan menunggu persetujuan Owner.',
            'data' => $resignation->load(['user', 'businessMember.branchMembers.branch']),
        ], 201);
    }

    /**
     * Cancel a pending resignation request (Staff self-service).
     */
    public function cancel(Request $request, int $id, int $requestId): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ?: $context->getBusinessId();
        $user = $request->user();

        $resignation = StaffResignationRequest::where('id', $requestId)
            ->where('business_id', $businessId)
            ->firstOrFail();

        if ($resignation->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya staf yang mengajukan yang berwenang membatalkan permohonan resign ini.',
            ], 403);
        }

        if ($resignation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya permohonan resign berstatus pending yang dapat dibatalkan.',
            ], 422);
        }

        $resignation->update(['status' => 'cancelled']);

        AuditLogService::log(
            $user,
            'staff_resignation.cancelled',
            'StaffResignationRequest',
            $resignation->id,
            null,
            null,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Permohonan resign berhasil dibatalkan. Anda tetap aktif sebagai staf.',
            'data' => $resignation->fresh(['user', 'businessMember.branchMembers.branch']),
        ]);
    }

    /**
     * Approve a staff resignation request (Owner only).
     */
    public function approve(Request $request, int $id, int $requestId): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ?: $context->getBusinessId();
        $user = $request->user();

        $business = Business::findOrFail($businessId);

        // Security check: strictly Owner of the business
        if (!$this->isAuthorizedOwner($user, $business)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner bisnis yang berwenang menyetujui permohonan resign staf.',
            ], 403);
        }

        $resignation = StaffResignationRequest::where('id', $requestId)
            ->where('business_id', $businessId)
            ->firstOrFail();

        if ($resignation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Permohonan resign ini sudah diproses sebelumnya.',
            ], 422);
        }

        $validated = $request->validate([
            'decision_notes' => 'nullable|string|max:1000',
        ]);

        // Operational Guardrail 1: Open cash drawer shifts
        $hasOpenShift = CashDrawerShift::where('business_id', $businessId)
            ->where('opened_by', $resignation->user_id)
            ->where('status', 'open')
            ->exists();

        if ($hasOpenShift) {
            throw ValidationException::withMessages([
                'shift' => [
                    'Staf masih memiliki shift kasir yang sedang aktif. ' .
                    'Tutup dan rekonsiliasi shift kasir terlebih dahulu sebelum menyetujui pengunduran diri.'
                ],
            ]);
        }

        // Operational Guardrail 2: In-transit inventory transfers
        $hasInTransitTransfer = InventoryTransfer::where('business_id', $businessId)
            ->where(function ($q) use ($resignation) {
                $q->where('sent_by', $resignation->user_id)
                  ->orWhere('received_by', $resignation->user_id);
            })
            ->whereIn('status', ['pending', 'in_transit'])
            ->exists();

        if ($hasInTransitTransfer) {
            throw ValidationException::withMessages([
                'transfer' => [
                    'Staf masih terlibat dalam mutasi unit berstatus pending atau in-transit. ' .
                    'Selesaikan penerimaan atau batalkan mutasi unit terlebih dahulu sebelum menyetujui pengunduran diri.'
                ],
            ]);
        }

        return DB::transaction(function () use ($resignation, $validated, $user, $request, $businessId) {
            $stillOpenShift = CashDrawerShift::where('business_id', $businessId)
                ->where('opened_by', $resignation->user_id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->exists();

            if ($stillOpenShift) {
                throw ValidationException::withMessages([
                    'shift' => [
                        'Staf masih memiliki shift kasir yang sedang aktif. ' .
                        'Tutup dan rekonsiliasi shift kasir terlebih dahulu sebelum menyetujui pengunduran diri.'
                    ],
                ]);
            }

            $resignation->update([
                'status' => 'approved',
                'decision_notes' => $validated['decision_notes'] ?? null,
                'decided_by' => $user->id,
                'decided_at' => Carbon::now(),
            ]);

            $member = BusinessMember::find($resignation->business_member_id);
            if ($member) {
                // Remove all branch assignments
                BranchMember::where('business_member_id', $member->id)->delete();
                // Deactivate business membership cleanly
                $memberUpdates = ['is_active' => false];
                if (Schema::hasColumn('business_members', 'left_at')) {
                    $memberUpdates['left_at'] = Carbon::now();
                }
                $member->update($memberUpdates);
            }

            // Clear legacy affiliate_id if it belonged to this business
            $staffUser = $resignation->user;
            if ($staffUser && $staffUser->affiliate_id) {
                $belongsToBusiness = Affiliate::withoutGlobalScopes()
                    ->where('id', $staffUser->affiliate_id)
                    ->where('business_id', $businessId)
                    ->exists();
                if ($belongsToBusiness) {
                    $staffUser->update(['affiliate_id' => null]);
                }
            }

            AuditLogService::log(
                $user,
                'staff_resignation.approved',
                'StaffResignationRequest',
                $resignation->id,
                [
                    'target_user' => $resignation->user?->name ?? $resignation->user_id,
                    'decision_notes' => $resignation->decision_notes,
                ],
                null,
                $request->ip(),
                null,
                $businessId
            );

            $this->notificationService->markResignationNotificationDecided($resignation);
            $this->notificationService->notifyResignationDecided($resignation);

            return response()->json([
                'success' => true,
                'message' => 'Permohonan resign disetujui. Akses staf ke bisnis rental dan cabang telah dicabut.',
                'data' => $resignation->fresh(['user', 'businessMember', 'decidedBy']),
            ]);
        });
    }

    /**
     * Reject a staff resignation request (Owner only).
     */
    public function reject(Request $request, int $id, int $requestId): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ?: $context->getBusinessId();
        $user = $request->user();

        $business = Business::findOrFail($businessId);

        // Security check: strictly Owner of the business
        if (!$this->isAuthorizedOwner($user, $business)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner bisnis yang berwenang menolak permohonan resign staf.',
            ], 403);
        }

        $resignation = StaffResignationRequest::where('id', $requestId)
            ->where('business_id', $businessId)
            ->firstOrFail();

        if ($resignation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Permohonan resign ini sudah diproses sebelumnya.',
            ], 422);
        }

        $validated = $request->validate([
            'decision_notes' => 'nullable|string|max:1000',
            'rejection_reason' => 'nullable|string|max:1000',
        ]);
        $decisionNotes = $validated['decision_notes'] ?? $validated['rejection_reason'] ?? null;

        $resignation->update([
            'status' => 'rejected',
            'decision_notes' => $decisionNotes,
            'decided_by' => $user->id,
            'decided_at' => Carbon::now(),
        ]);

        AuditLogService::log(
            $user,
            'staff_resignation.rejected',
            'StaffResignationRequest',
            $resignation->id,
            [
                'target_user' => $resignation->user?->name ?? $resignation->user_id,
                'decision_notes' => $resignation->decision_notes,
            ],
            null,
            $request->ip(),
            null,
            $businessId
        );

        $this->notificationService->markResignationNotificationDecided($resignation);
        $this->notificationService->notifyResignationDecided($resignation);

        return response()->json([
            'success' => true,
            'message' => 'Permohonan resign ditolak. Keanggotaan staf tetap aktif.',
            'data' => $resignation->fresh(['user', 'businessMember', 'decidedBy']),
        ]);
    }

    /**
     * Approve a resignation request directly by ID (used from owner notifications).
     */
    public function approveById(Request $request, int $requestId): JsonResponse
    {
        $resignation = StaffResignationRequest::findOrFail($requestId);
        return $this->approve($request, (int)$resignation->business_id, $requestId);
    }

    /**
     * Reject a resignation request directly by ID (used from owner notifications).
     */
    public function rejectById(Request $request, int $requestId): JsonResponse
    {
        $resignation = StaffResignationRequest::findOrFail($requestId);
        return $this->reject($request, (int)$resignation->business_id, $requestId);
    }

    private function isAuthorizedOwner(User $user, Business $business): bool
    {
        if ($business->owner_id === $user->id || $user->isPlatformAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        return BusinessMember::where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->exists();
    }
}
