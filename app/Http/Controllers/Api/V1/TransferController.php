<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\BusinessMember;
use App\Models\InventoryTransfer;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    /**
     * List inventory transfers involving the authenticated user's branch / business.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $membership = $user->getBusinessMembership($businessId);
        if (!$membership && !$user->isPlatformAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke bisnis rental ini.',
            ], 403);
        }

        $isOwner = $membership ? $membership->isOwner() : false;
        $userBranchIds = $user->getBranchIdsForBusiness($businessId);

        $query = InventoryTransfer::with([
            'asset.model.category',
            'fromAffiliate',
            'toAffiliate',
            'sender',
            'receiver',
            'recipient',
            'approver',
            'rejecter',
        ]);

        // Non-owners can only see transfers for their assigned branches or transfers they created/received
        if (!$isOwner) {
            $query->where(function ($q) use ($user, $userBranchIds) {
                if (!empty($userBranchIds)) {
                    $q->whereIn('from_affiliate_id', $userBranchIds)
                      ->orWhereIn('to_affiliate_id', $userBranchIds);
                }
                $q->orWhere('sent_by', $user->id)
                  ->orWhere('recipient_id', $user->id);
            });
        }

        if ($request->has('direction')) {
            if ($request->get('direction') === 'outbound' && !empty($userBranchIds)) {
                $query->whereIn('from_affiliate_id', $userBranchIds);
            } elseif ($request->get('direction') === 'inbound' && !empty($userBranchIds)) {
                $query->whereIn('to_affiliate_id', $userBranchIds);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transfers = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $transfers,
        ]);
    }

    /**
     * List available destination branches within the active business.
     */
    public function destinationAffiliates(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $excludeBranchId = $request->get('from_affiliate_id')
            ?? $request->header('X-Branch-Id')
            ?? $tenantContext->getBranchId();

        $affiliates = Affiliate::where('business_id', $businessId)
            ->where('is_active', true)
            ->when($excludeBranchId, fn($q) => $q->where('id', '!=', (int)$excludeBranchId))
            ->get(['id', 'code', 'name', 'city', 'address', 'is_central']);

        return response()->json([
            'success' => true,
            'data' => $affiliates,
        ]);
    }

    /**
     * List eligible recipients for a specific destination branch.
     */
    public function recipients(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $branchId = (int) $request->get('branch_id');
        if (!$branchId) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter branch_id wajib disertakan.',
            ], 422);
        }

        // Validate branch belongs to current business
        $branch = Affiliate::where('business_id', $businessId)->findOrFail($branchId);

        // Fetch business members assigned to this branch or owners
        $members = BusinessMember::with('user')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($q) use ($branchId) {
                $q->whereHas('branchMembers', fn($bm) => $bm->where('affiliate_id', $branchId))
                  ->orWhere('role', 'owner');
            })
            ->get();

        $recipients = $members->map(function ($m) {
            return [
                'id' => $m->user->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'role' => $m->role,
            ];
        })->values();

        // Also include any active legacy user tied to this affiliate
        $existingIds = $recipients->pluck('id')->toArray();
        $legacyUsers = User::where('affiliate_id', $branchId)
            ->where('is_active', true)
            ->whereNotIn('id', $existingIds)
            ->get(['id', 'name', 'email', 'role']);

        $allRecipients = $recipients->concat($legacyUsers);

        return response()->json([
            'success' => true,
            'data' => $allRecipients,
        ]);
    }

    /**
     * Initiate or request an inter-branch stock transfer.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $membership = $user->getBusinessMembership($businessId);
        if (!$membership && !$user->isPlatformAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki wewenang dalam bisnis rental ini.',
            ], 403);
        }

        $isOwner = $membership ? $membership->isOwner() : false;
        $userBranchIds = $user->getBranchIdsForBusiness($businessId);

        $validated = $request->validate([
            'asset_id' => 'required|integer',
            'to_affiliate_id' => 'required|integer',
            'recipient_id' => 'required|string',
            'notes' => 'nullable|string|max:500',
        ]);

        $toAffiliate = Affiliate::where('business_id', $businessId)
            ->where('id', $validated['to_affiliate_id'])
            ->firstOrFail();

        $recipient = User::where('id', $validated['recipient_id'])->where('is_active', true)->firstOrFail();
        $recipientBranchIds = $recipient->getBranchIdsForBusiness($businessId);
        if (!in_array($toAffiliate->id, $recipientBranchIds)) {
            throw ValidationException::withMessages([
                'recipient_id' => ['Penerima mutasi yang dipilih tidak terdaftar di cabang tujuan.'],
            ]);
        }

        $transfer = DB::transaction(function () use ($validated, $user, $isOwner, $userBranchIds, $toAffiliate, $recipient, $businessId, $request) {
            // Lock asset strictly within the current business context
            $asset = Asset::where('business_id', $businessId)
                ->where('id', $validated['asset_id'])
                ->lockForUpdate()
                ->first();

            if (!$asset) {
                throw ValidationException::withMessages([
                    'asset_id' => ['Perangkat tidak ditemukan di inventaris bisnis rental Anda.'],
                ]);
            }

            $fromAffiliateId = $asset->affiliate_id;

            if (!$isOwner && !in_array($fromAffiliateId, $userBranchIds)) {
                throw ValidationException::withMessages([
                    'asset_id' => ['Anda tidak memiliki akses untuk memutasi aset dari cabang asal ini.'],
                ]);
            }

            if ($fromAffiliateId === (int)$toAffiliate->id) {
                throw ValidationException::withMessages([
                    'to_affiliate_id' => ['Cabang tujuan mutasi tidak boleh sama dengan cabang asal.'],
                ]);
            }

            if ($asset->status !== 'ready') {
                throw ValidationException::withMessages([
                    'asset_id' => ["Perangkat tidak dapat dimutasi karena sedang berstatus: {$asset->status}."],
                ]);
            }

            $hasActiveTransfer = InventoryTransfer::where('business_id', $businessId)
                ->where('asset_id', $asset->id)
                ->whereIn('status', ['pending', 'in_transit'])
                ->exists();

            if ($hasActiveTransfer) {
                throw ValidationException::withMessages([
                    'asset_id' => ['Perangkat ini sedang dalam proses mutasi aktif lain.'],
                ]);
            }

            $datePrefix = Carbon::now()->format('ymd');
            $countToday = InventoryTransfer::withoutGlobalScopes()
                ->where('business_id', $businessId)
                ->whereDate('created_at', Carbon::today())
                ->count() + 1;
            $transferCode = sprintf('TRF-%s-%04d', $datePrefix, $countToday);

            // Owner-initiated transfers auto-approve to 'in_transit'
            // Staff / Admin requests enter 'pending' requiring Owner approval
            $initialStatus = $isOwner ? 'in_transit' : 'pending';
            $newAssetStatus = $isOwner ? 'in_transit' : 'reserved';

            $transferRecord = InventoryTransfer::create([
                'business_id' => $businessId,
                'transfer_code' => $transferCode,
                'asset_id' => $asset->id,
                'from_affiliate_id' => $fromAffiliateId,
                'to_affiliate_id' => $toAffiliate->id,
                'sent_by' => $user->id,
                'recipient_id' => $recipient->id,
                'approved_by' => $isOwner ? $user->id : null,
                'approved_at' => $isOwner ? Carbon::now() : null,
                'status' => $initialStatus,
                'notes' => $validated['notes'] ?? null,
                'sent_at' => Carbon::now(),
            ]);

            $asset->update(['status' => $newAssetStatus]);

            AuditLogService::log(
                $user,
                $isOwner ? 'transfer.initiated_by_owner' : 'transfer.requested',
                'InventoryTransfer',
                $transferRecord->id,
                [
                    'transfer_code' => $transferCode,
                    'asset_code' => $asset->asset_code,
                    'from_affiliate_id' => $fromAffiliateId,
                    'to_affiliate_id' => $toAffiliate->id,
                    'recipient_id' => $recipient->id,
                    'status' => $initialStatus,
                ],
                [
                    'previous_asset_status' => 'ready',
                    'new_asset_status' => $newAssetStatus,
                ],
                $request->ip(),
                $fromAffiliateId
            );

            return $transferRecord->load([
                'asset.model.category',
                'fromAffiliate',
                'toAffiliate',
                'sender',
                'recipient',
                'approver',
            ]);
        });

        $message = $isOwner
            ? 'Mutasi unit antar cabang berhasil diinisiasi dan langsung dalam pengiriman.'
            : 'Pengajuan mutasi berhasil dikirim dan menunggu persetujuan Owner.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $transfer,
        ], 201);
    }

    /**
     * Business Owner approves a pending transfer.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        if (!$user->isBusinessOwner($businessId) && !$user->isPlatformAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Business Owner yang berwenang menyetujui mutasi unit.',
            ], 403);
        }

        return DB::transaction(function () use ($id, $businessId, $user, $request) {
            $transfer = InventoryTransfer::where('business_id', $businessId)
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => "Mutasi tidak dapat disetujui karena berstatus: {$transfer->status}.",
                ], 422);
            }

            $asset = Asset::where('business_id', $businessId)
                ->where('id', $transfer->asset_id)
                ->lockForUpdate()
                ->firstOrFail();

            $transfer->update([
                'status' => 'in_transit',
                'approved_by' => $user->id,
                'approved_at' => Carbon::now(),
            ]);

            $asset->update(['status' => 'in_transit']);

            AuditLogService::log(
                $user,
                'transfer.approved',
                'InventoryTransfer',
                $transfer->id,
                [
                    'transfer_code' => $transfer->transfer_code,
                    'approved_by' => $user->id,
                ],
                [
                    'previous_status' => 'pending',
                    'new_status' => 'in_transit',
                ],
                $request->ip(),
                $transfer->from_affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Mutasi unit telah disetujui dan kini berstatus Dalam Pengiriman.',
                'data' => $transfer->fresh([
                    'asset.model.category',
                    'fromAffiliate',
                    'toAffiliate',
                    'sender',
                    'recipient',
                    'approver',
                ]),
            ]);
        });
    }

    /**
     * Business Owner rejects a pending transfer.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        if (!$user->isBusinessOwner($businessId) && !$user->isPlatformAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Business Owner yang berwenang menolak mutasi unit.',
            ], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        return DB::transaction(function () use ($id, $businessId, $user, $validated, $request) {
            $transfer = InventoryTransfer::where('business_id', $businessId)
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => "Mutasi tidak dapat ditolak karena berstatus: {$transfer->status}.",
                ], 422);
            }

            $asset = Asset::where('business_id', $businessId)
                ->where('id', $transfer->asset_id)
                ->lockForUpdate()
                ->firstOrFail();

            $transfer->update([
                'status' => 'rejected',
                'rejected_by' => $user->id,
                'rejected_at' => Carbon::now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            // Revert asset status from reserved back to ready
            $asset->update(['status' => 'ready']);

            AuditLogService::log(
                $user,
                'transfer.rejected',
                'InventoryTransfer',
                $transfer->id,
                [
                    'transfer_code' => $transfer->transfer_code,
                    'rejected_by' => $user->id,
                    'rejection_reason' => $validated['rejection_reason'],
                ],
                [
                    'previous_status' => 'pending',
                    'new_status' => 'rejected',
                ],
                $request->ip(),
                $transfer->from_affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Mutasi unit telah ditolak dan unit telah dikembalikan ke status Siap Sewa.',
                'data' => $transfer->fresh([
                    'asset.model.category',
                    'fromAffiliate',
                    'toAffiliate',
                    'sender',
                    'recipient',
                    'rejecter',
                ]),
            ]);
        });
    }

    /**
     * Assigned recipient accepts and receives transferred unit.
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        return DB::transaction(function () use ($id, $businessId, $user, $request) {
            $transfer = InventoryTransfer::where('business_id', $businessId)
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            // Strict authorization: Only the explicitly assigned recipient may confirm receipt!
            if ($transfer->recipient_id && $transfer->recipient_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya staf penerima yang ditugaskan secara khusus yang berwenang mengonfirmasi penerimaan unit ini.',
                ], 403);
            }

            if ($transfer->status !== 'in_transit') {
                return response()->json([
                    'success' => false,
                    'message' => "Unit tidak dapat diterima karena status saat ini: {$transfer->status}.",
                ], 422);
            }

            $membership = $user->getBusinessMembership($businessId);
            if (!$membership && !$user->isPlatformAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun penerima sudah tidak aktif dalam bisnis rental ini.',
                ], 403);
            }

            // Verify that the confirming recipient remains actively assigned to the destination branch
            if (!$user->isPlatformAdmin() && !$user->isBusinessOwner($businessId)) {
                $recipientBranchIds = $user->getBranchIdsForBusiness($businessId);
                if (!in_array($transfer->to_affiliate_id, $recipientBranchIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Penerima tidak lagi terdaftar atau berwenang di cabang tujuan mutasi ini.',
                    ], 403);
                }
            }

            $asset = Asset::withoutGlobalScopes()
                ->where('business_id', $businessId)
                ->where('id', $transfer->asset_id)
                ->lockForUpdate()
                ->firstOrFail();

            $transfer->update([
                'status' => 'received',
                'received_by' => $user->id,
                'received_at' => Carbon::now(),
            ]);

            // Update physical location and set status to ready
            $asset->update([
                'affiliate_id' => $transfer->to_affiliate_id,
                'status' => 'ready',
            ]);

            AuditLogService::log(
                $user,
                'transfer.received',
                'InventoryTransfer',
                $transfer->id,
                [
                    'transfer_code' => $transfer->transfer_code,
                    'new_affiliate_id' => $transfer->to_affiliate_id,
                    'new_asset_status' => 'ready',
                    'received_by' => $user->id,
                ],
                [
                    'previous_transfer_status' => 'in_transit',
                ],
                $request->ip(),
                $transfer->to_affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Unit mutasi berhasil diterima dan kini siap disewakan di cabang Anda.',
                'data' => $transfer->fresh([
                    'asset.model.category',
                    'fromAffiliate',
                    'toAffiliate',
                    'receiver',
                    'recipient',
                    'approver',
                ]),
            ]);
        });
    }

    /**
     * Sender or Owner cancels a pending or in-transit transfer.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        return DB::transaction(function () use ($id, $businessId, $user, $request) {
            $transfer = InventoryTransfer::where('business_id', $businessId)
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            $isOwner = $user->isBusinessOwner($businessId);
            $isSender = $transfer->sent_by === $user->id;

            if (!$isOwner && !$isSender && !$user->isPlatformAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya pengirim atau Business Owner yang berwenang membatalkan mutasi ini.',
                ], 403);
            }

            if (!in_array($transfer->status, ['pending', 'in_transit'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Mutasi tidak dapat dibatalkan karena berstatus: {$transfer->status}.",
                ], 422);
            }

            $asset = Asset::withoutGlobalScopes()
                ->where('business_id', $businessId)
                ->where('id', $transfer->asset_id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = $transfer->status;

            $transfer->update([
                'status' => 'cancelled',
            ]);

            // Revert asset back to ready
            $asset->update(['status' => 'ready']);

            AuditLogService::log(
                $user,
                'transfer.cancelled',
                'InventoryTransfer',
                $transfer->id,
                [
                    'transfer_code' => $transfer->transfer_code,
                    'cancelled_by' => $user->id,
                ],
                [
                    'previous_transfer_status' => $previousStatus,
                ],
                $request->ip(),
                $transfer->from_affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Mutasi berhasil dibatalkan dan status unit dikembalikan ke Siap Sewa di cabang asal.',
                'data' => $transfer->fresh([
                    'asset.model.category',
                    'fromAffiliate',
                    'toAffiliate',
                    'sender',
                ]),
            ]);
        });
    }
}
