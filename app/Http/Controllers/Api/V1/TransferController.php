<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\InventoryTransfer;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    /**
     * List inventory transfers involving the authenticated user's branch.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $affiliateId = $user->affiliate_id;

        $query = InventoryTransfer::with([
            'asset.model.category',
            'fromAffiliate',
            'toAffiliate',
            'sender',
            'receiver'
        ]);

        if ($affiliateId && !$user->isSuperAdmin()) {
            $query->where(function ($q) use ($affiliateId) {
                $q->where('from_affiliate_id', $affiliateId)
                  ->orWhere('to_affiliate_id', $affiliateId);
            });

            if ($request->get('direction') === 'outbound') {
                $query->where('from_affiliate_id', $affiliateId);
            } elseif ($request->get('direction') === 'inbound') {
                $query->where('to_affiliate_id', $affiliateId);
            }
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $transfers = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $transfers,
        ]);
    }

    /**
     * List available destination branches.
     */
    public function destinationAffiliates(Request $request): JsonResponse
    {
        $currentAffiliateId = $request->user()->affiliate_id;

        $affiliates = Affiliate::where('is_active', true)
            ->when($currentAffiliateId, fn($q) => $q->where('id', '!=', $currentAffiliateId))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $affiliates,
        ]);
    }

    /**
     * Initiate an inter-affiliate stock transfer.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $fromAffiliateId = $user->affiliate_id;

        $validated = $request->validate([
            'asset_id' => 'required|integer|exists:assets,id',
            'to_affiliate_id' => 'required|integer|exists:affiliates,id',
            'notes' => 'nullable|string',
        ]);

        if ($fromAffiliateId && $fromAffiliateId === (int)$validated['to_affiliate_id']) {
            throw ValidationException::withMessages([
                'to_affiliate_id' => ['Cabang tujuan mutasi tidak boleh sama dengan cabang asal.'],
            ]);
        }

        $transfer = DB::transaction(function () use ($validated, $user, $fromAffiliateId, $request) {
            // Lock asset with SELECT ... FOR UPDATE
            $asset = Asset::withoutGlobalScopes()
                ->where('id', $validated['asset_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($fromAffiliateId && $asset->affiliate_id !== $fromAffiliateId) {
                throw ValidationException::withMessages([
                    'asset_id' => ['Perangkat ini tidak berada di inventaris cabang Anda.'],
                ]);
            }

            if ($asset->status !== 'ready') {
                throw ValidationException::withMessages([
                    'asset_id' => ["Perangkat tidak dapat dimutasi karena sedang berstatus: {$asset->status}."],
                ]);
            }

            $originAffiliateId = $fromAffiliateId ?? $asset->affiliate_id;

            // Generate Transfer Code: TRF-YYMMDD-XXXX
            $datePrefix = Carbon::now()->format('ymd');
            $countToday = InventoryTransfer::whereDate('created_at', Carbon::today())->count() + 1;
            $transferCode = sprintf('TRF-%s-%04d', $datePrefix, $countToday);

            // 1. Create transfer record in_transit
            $transferRecord = InventoryTransfer::create([
                'transfer_code' => $transferCode,
                'asset_id' => $asset->id,
                'from_affiliate_id' => $originAffiliateId,
                'to_affiliate_id' => $validated['to_affiliate_id'],
                'sent_by' => $user->id,
                'status' => 'in_transit',
                'notes' => $validated['notes'] ?? null,
                'sent_at' => Carbon::now(),
            ]);

            // 2. Atomically transition asset status to in_transit
            $asset->update(['status' => 'in_transit']);

            // 3. Immutable audit log
            AuditLogService::log(
                $user,
                'transfer.initiated',
                'InventoryTransfer',
                $transferRecord->id,
                [
                    'transfer_code' => $transferCode,
                    'asset_code' => $asset->asset_code,
                    'from_affiliate_id' => $originAffiliateId,
                    'to_affiliate_id' => $validated['to_affiliate_id'],
                ],
                [
                    'previous_asset_status' => 'ready',
                ],
                $request->ip(),
                $originAffiliateId
            );

            return $transferRecord->load(['asset.model.category', 'fromAffiliate', 'toAffiliate', 'sender']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Mutasi unit antar cabang berhasil diinisiasi dan status perangkat diubah menjadi Dalam Pengiriman.',
            'data' => $transfer,
        ], 201);
    }

    /**
     * Destination branch accepts and receives transferred unit.
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $affiliateId = $user->affiliate_id;

        $transfer = InventoryTransfer::with('asset')->findOrFail($id);

        if ($affiliateId && !$user->isSuperAdmin() && $transfer->to_affiliate_id !== $affiliateId) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya staf cabang tujuan yang berwenang menerima unit mutasi ini.',
            ], 403);
        }

        if ($transfer->status !== 'in_transit') {
            return response()->json([
                'success' => false,
                'message' => "Unit tidak dapat diterima karena status saat ini: {$transfer->status}.",
            ], 422);
        }

        DB::transaction(function () use ($transfer, $user, $request) {
            $transfer->update([
                'status' => 'received',
                'received_by' => $user->id,
                'received_at' => Carbon::now(),
            ]);

            // Atomically transfer ownership & return asset to ready status
            Asset::withoutGlobalScopes()->where('id', $transfer->asset_id)->update([
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
                ],
                [
                    'previous_transfer_status' => 'in_transit',
                ],
                $request->ip(),
                $transfer->to_affiliate_id
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Unit mutasi berhasil diterima dan kini siap disewakan di cabang Anda.',
            'data' => $transfer->fresh(['asset.model.category', 'fromAffiliate', 'toAffiliate', 'receiver']),
        ]);
    }

    /**
     * Source branch cancels an in-transit transfer before arrival.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $affiliateId = $user->affiliate_id;

        $transfer = InventoryTransfer::findOrFail($id);

        if ($affiliateId && !$user->isSuperAdmin() && $transfer->from_affiliate_id !== $affiliateId) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya staf cabang pengirim yang dapat membatalkan mutasi ini.',
            ], 403);
        }

        if ($transfer->status !== 'in_transit') {
            return response()->json([
                'success' => false,
                'message' => "Mutasi tidak dapat dibatalkan karena berstatus: {$transfer->status}.",
            ], 422);
        }

        DB::transaction(function () use ($transfer, $user, $request) {
            $transfer->update([
                'status' => 'cancelled',
            ]);

            // Restore asset to ready in origin branch
            Asset::withoutGlobalScopes()->where('id', $transfer->asset_id)->update([
                'status' => 'ready',
            ]);

            AuditLogService::log(
                $user,
                'transfer.cancelled',
                'InventoryTransfer',
                $transfer->id,
                [
                    'transfer_code' => $transfer->transfer_code,
                ],
                [
                    'previous_transfer_status' => 'in_transit',
                ],
                $request->ip(),
                $transfer->from_affiliate_id
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Mutasi berhasil dibatalkan dan status unit dikembalikan ke Siap Sewa di cabang asal.',
            'data' => $transfer->fresh(),
        ]);
    }
}
