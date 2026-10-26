<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\InventoryTransfer;
use App\Models\StaffResignationRequest;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $request->ip()
        );

        $this->notificationService->notifyResignationSubmitted($resignation->load('user'));

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
        if ($business->owner_id !== $user->id && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
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

        return DB::transaction(function () use ($resignation, $validated, $user, $request) {
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
                // Deactivate business membership
                $member->update(['is_active' => false]);
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
                $request->ip()
            );

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
        if ($business->owner_id !== $user->id && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
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
        ]);

        $resignation->update([
            'status' => 'rejected',
            'decision_notes' => $validated['decision_notes'] ?? null,
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
            $request->ip()
        );

        $this->notificationService->notifyResignationDecided($resignation);

        return response()->json([
            'success' => true,
            'message' => 'Permohonan resign ditolak. Keanggotaan staf tetap aktif.',
            'data' => $resignation->fresh(['user', 'businessMember', 'decidedBy']),
        ]);
    }
}
