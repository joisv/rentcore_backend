<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\InventoryTransfer;
use App\Models\StaffResignationRequest;
use App\Models\TenantNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Dispatch a notification to a specific user within a business.
     */
    public function notify(
        int $businessId,
        ?int $affiliateId,
        string $userId,
        string $category,
        string $type,
        string $title,
        string $body,
        ?string $actionRoute = null,
        ?array $actionPayload = null,
        ?string $dedupKey = null
    ): ?TenantNotification {
        try {
            if ($dedupKey) {
                return TenantNotification::firstOrCreate(
                    ['dedup_key' => $dedupKey],
                    [
                        'business_id' => $businessId,
                        'affiliate_id' => $affiliateId,
                        'user_id' => $userId,
                        'category' => $category,
                        'type' => $type,
                        'title' => $title,
                        'body' => $body,
                        'action_route' => $actionRoute,
                        'action_payload' => $actionPayload,
                    ]
                );
            }

            return TenantNotification::create([
                'business_id' => $businessId,
                'affiliate_id' => $affiliateId,
                'user_id' => $userId,
                'category' => $category,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'action_route' => $actionRoute,
                'action_payload' => $actionPayload,
                'dedup_key' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch tenant notification: ' . $e->getMessage(), [
                'business_id' => $businessId,
                'user_id' => $userId,
                'type' => $type,
            ]);
            return null;
        }
    }

    /**
     * Notify multiple users.
     */
    public function notifyUsers(
        int $businessId,
        ?int $affiliateId,
        array $userIds,
        string $category,
        string $type,
        string $title,
        string $body,
        ?string $actionRoute = null,
        ?array $actionPayload = null,
        ?string $dedupKeyPrefix = null
    ): array {
        $notifications = [];
        $uniqueUserIds = array_unique(array_filter($userIds));

        foreach ($uniqueUserIds as $userId) {
            $dedupKey = $dedupKeyPrefix ? "{$dedupKeyPrefix}:{$userId}" : null;
            $notif = $this->notify(
                $businessId,
                $affiliateId,
                $userId,
                $category,
                $type,
                $title,
                $body,
                $actionRoute,
                $actionPayload,
                $dedupKey
            );
            if ($notif) {
                $notifications[] = $notif;
            }
        }

        return $notifications;
    }

    /**
     * Triggered when a staff member submits a transfer request for owner approval.
     */
    public function notifyTransferRequested(InventoryTransfer $transfer): ?TenantNotification
    {
        $business = Business::find($transfer->business_id);
        if (!$business || !$business->owner_id) {
            return null;
        }

        $senderName = $transfer->sender?->name ?? 'Staf';
        $assetName = $transfer->asset?->model?->name ?? $transfer->asset?->asset_code ?? 'Unit';
        $toBranch = $transfer->toAffiliate?->name ?? 'Cabang Tujuan';

        return $this->notify(
            $transfer->business_id,
            $transfer->from_affiliate_id,
            $business->owner_id,
            'transfer',
            'transfer.requested',
            'Pengajuan Mutasi Unit',
            "{$senderName} mengajukan mutasi {$assetName} ({$transfer->transfer_code}) menuju {$toBranch}. Menunggu persetujuan Anda.",
            '/transfers',
            [
                'transfer_id' => $transfer->id,
                'transfer_code' => $transfer->transfer_code,
                'action' => 'review',
            ],
            "transfer.requested:{$transfer->id}"
        );
    }

    /**
     * Triggered when owner approves transfer (now in transit).
     */
    public function notifyTransferApproved(InventoryTransfer $transfer): array
    {
        $assetName = $transfer->asset?->model?->name ?? $transfer->asset?->asset_code ?? 'Unit';
        $notifications = [];

        // 1. Notify destination recipient that unit is coming
        if ($transfer->recipient_id) {
            $notifRecipient = $this->notify(
                $transfer->business_id,
                $transfer->to_affiliate_id,
                $transfer->recipient_id,
                'transfer',
                'transfer.approved',
                'Unit Mutasi Dalam Pengiriman',
                "Unit {$assetName} ({$transfer->transfer_code}) telah disetujui Owner dan sedang dikirim ke cabang Anda. Harap konfirmasi penerimaan saat unit tiba.",
                '/transfers',
                [
                    'transfer_id' => $transfer->id,
                    'transfer_code' => $transfer->transfer_code,
                    'action' => 'receive',
                ],
                "transfer.in_transit:{$transfer->id}:{$transfer->recipient_id}"
            );
            if ($notifRecipient) {
                $notifications[] = $notifRecipient;
            }
        }

        // 2. Notify origin sender that request was approved
        if ($transfer->sent_by && $transfer->sent_by !== $transfer->recipient_id) {
            $notifSender = $this->notify(
                $transfer->business_id,
                $transfer->from_affiliate_id,
                $transfer->sent_by,
                'transfer',
                'transfer.approved',
                'Mutasi Disetujui Owner',
                "Pengajuan mutasi {$transfer->transfer_code} ({$assetName}) telah disetujui oleh Owner dan kini berstatus Dalam Pengiriman.",
                '/transfers',
                [
                    'transfer_id' => $transfer->id,
                    'transfer_code' => $transfer->transfer_code,
                ],
                "transfer.approved_sender:{$transfer->id}:{$transfer->sent_by}"
            );
            if ($notifSender) {
                $notifications[] = $notifSender;
            }
        }

        return $notifications;
    }

    /**
     * Triggered when owner rejects transfer.
     */
    public function notifyTransferRejected(InventoryTransfer $transfer, string $reason): ?TenantNotification
    {
        if (!$transfer->sent_by) {
            return null;
        }

        $assetName = $transfer->asset?->model?->name ?? $transfer->asset?->asset_code ?? 'Unit';

        return $this->notify(
            $transfer->business_id,
            $transfer->from_affiliate_id,
            $transfer->sent_by,
            'transfer',
            'transfer.rejected',
            'Pengajuan Mutasi Ditolak',
            "Pengajuan mutasi {$transfer->transfer_code} ({$assetName}) ditolak oleh Owner. Alasan: {$reason}. Status unit telah dikembalikan ke Siap Sewa.",
            '/transfers',
            [
                'transfer_id' => $transfer->id,
                'transfer_code' => $transfer->transfer_code,
            ],
            "transfer.rejected:{$transfer->id}"
        );
    }

    /**
     * Triggered when recipient confirms unit receipt.
     */
    public function notifyTransferReceived(InventoryTransfer $transfer): array
    {
        $notifications = [];
        $assetName = $transfer->asset?->model?->name ?? $transfer->asset?->asset_code ?? 'Unit';
        $receiverName = $transfer->receiver?->name ?? 'Staf Cabang Tujuan';
        $toBranchName = $transfer->toAffiliate?->name ?? 'cabang tujuan';

        // 1. Notify sender
        if ($transfer->sent_by && $transfer->sent_by !== $transfer->received_by) {
            $notifSender = $this->notify(
                $transfer->business_id,
                $transfer->from_affiliate_id,
                $transfer->sent_by,
                'transfer',
                'transfer.received',
                'Unit Mutasi Berhasil Diterima',
                "Unit {$assetName} ({$transfer->transfer_code}) telah berhasil diterima oleh {$receiverName} di {$toBranchName}.",
                '/transfers',
                [
                    'transfer_id' => $transfer->id,
                    'transfer_code' => $transfer->transfer_code,
                ],
                "transfer.received:{$transfer->id}:{$transfer->sent_by}"
            );
            if ($notifSender) {
                $notifications[] = $notifSender;
            }
        }

        // 2. Notify Owner
        $business = Business::find($transfer->business_id);
        if ($business && $business->owner_id && $business->owner_id !== $transfer->received_by) {
            $notifOwner = $this->notify(
                $transfer->business_id,
                $transfer->to_affiliate_id,
                $business->owner_id,
                'transfer',
                'transfer.received',
                'Mutasi Unit Selesai',
                "Mutasi {$transfer->transfer_code} ({$assetName}) telah selesai dan diterima di {$toBranchName}.",
                '/transfers',
                [
                    'transfer_id' => $transfer->id,
                    'transfer_code' => $transfer->transfer_code,
                ],
                "transfer.received_owner:{$transfer->id}"
            );
            if ($notifOwner) {
                $notifications[] = $notifOwner;
            }
        }

        return $notifications;
    }

    /**
     * Triggered when transfer is cancelled.
     */
    public function notifyTransferCancelled(InventoryTransfer $transfer, User $canceller): array
    {
        $notifications = [];
        $assetName = $transfer->asset?->model?->name ?? $transfer->asset?->asset_code ?? 'Unit';

        // Notify recipient if designated
        if ($transfer->recipient_id && $transfer->recipient_id !== $canceller->id) {
            $notifRecipient = $this->notify(
                $transfer->business_id,
                $transfer->to_affiliate_id,
                $transfer->recipient_id,
                'transfer',
                'transfer.cancelled',
                'Mutasi Unit Dibatalkan',
                "Mutasi unit {$assetName} ({$transfer->transfer_code}) telah dibatalkan oleh {$canceller->name}.",
                '/transfers',
                ['transfer_id' => $transfer->id],
                "transfer.cancelled:{$transfer->id}:{$transfer->recipient_id}"
            );
            if ($notifRecipient) {
                $notifications[] = $notifRecipient;
            }
        }

        // Notify sender if not canceller
        if ($transfer->sent_by && $transfer->sent_by !== $canceller->id) {
            $notifSender = $this->notify(
                $transfer->business_id,
                $transfer->from_affiliate_id,
                $transfer->sent_by,
                'transfer',
                'transfer.cancelled',
                'Mutasi Unit Dibatalkan',
                "Pengajuan mutasi {$transfer->transfer_code} ({$assetName}) telah dibatalkan oleh {$canceller->name}.",
                '/transfers',
                ['transfer_id' => $transfer->id],
                "transfer.cancelled:{$transfer->id}:{$transfer->sent_by}"
            );
            if ($notifSender) {
                $notifications[] = $notifSender;
            }
        }

        return $notifications;
    }

    /**
     * Triggered when a staff member submits a resignation request.
     */
    public function notifyResignationSubmitted(StaffResignationRequest $resignation): ?TenantNotification
    {
        $business = Business::find($resignation->business_id);
        if (!$business || !$business->owner_id) {
            return null;
        }

        $staffName = $resignation->user?->name ?? 'Staf';
        $reasonSnippet = $resignation->reason ? " Catatan: \"{$resignation->reason}\"" : '';

        return $this->notify(
            $resignation->business_id,
            null,
            $business->owner_id,
            'membership',
            'staff.resignation_submitted',
            'Permohonan Resign Staf',
            "{$staffName} mengajukan permohonan pengunduran diri dari bisnis rental Anda.{$reasonSnippet}",
            '/organization',
            [
                'resignation_id' => $resignation->id,
                'action' => 'review_resignation',
            ],
            "resignation.submitted:{$resignation->id}"
        );
    }

    /**
     * Triggered when owner approves or rejects a resignation request.
     */
    public function notifyResignationDecided(StaffResignationRequest $resignation): ?TenantNotification
    {
        $isApproved = $resignation->status === 'approved';
        $title = $isApproved ? 'Permohonan Resign Disetujui' : 'Permohonan Resign Ditolak';
        $notesText = $resignation->decision_notes ? " Catatan Owner: {$resignation->decision_notes}." : '';
        $body = $isApproved
            ? "Permohonan pengunduran diri Anda telah disetujui oleh Owner. Akses ke cabang telah dinonaktifkan.{$notesText}"
            : "Permohonan pengunduran diri Anda ditolak oleh Owner. Anda tetap aktif sebagai staf.{$notesText}";

        return $this->notify(
            $resignation->business_id,
            null,
            $resignation->user_id,
            'membership',
            $isApproved ? 'staff.resignation_approved' : 'staff.resignation_rejected',
            $title,
            $body,
            '/organization',
            [
                'resignation_id' => $resignation->id,
                'status' => $resignation->status,
            ],
            "resignation.decided:{$resignation->id}:{$resignation->status}"
        );
    }

    /**
     * Triggered when a rental booking exceeds its scheduled return time plus grace period.
     */
    public function notifyRentalOverdue(Booking $booking, int $overdueHours): array
    {
        $notifications = [];
        $business = Business::find($booking->business_id);
        if (!$business) {
            return [];
        }

        $customerName = $booking->customer?->name ?? 'Pelanggan';
        $customerPhone = $booking->customer?->phone ?? '-';
        $todayDate = Carbon::today()->format('Y-m-d');
        $dedupPrefix = "rental.overdue:{$booking->id}:{$todayDate}";

        $title = "Unit Sewa Overdue ({$overdueHours} Jam)";
        $body = "Booking {$booking->booking_code} oleh {$customerName} ({$customerPhone}) telah melewati jadwal kembali selama {$overdueHours} jam. Segera hubungi pelanggan untuk proses pengembalian atau perpanjangan sewa.";

        $actionPayload = [
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'overdue_hours' => $overdueHours,
        ];

        // 1. Notify Owner
        if ($business->owner_id) {
            $notifOwner = $this->notify(
                $booking->business_id,
                $booking->affiliate_id,
                $business->owner_id,
                'rental',
                'rental.overdue',
                $title,
                $body,
                '/bookings',
                $actionPayload,
                "{$dedupPrefix}:{$business->owner_id}"
            );
            if ($notifOwner) {
                $notifications[] = $notifOwner;
            }
        }

        // 2. Notify assigned branch staff / creator
        if ($booking->created_by && $booking->created_by !== $business->owner_id) {
            $notifCreator = $this->notify(
                $booking->business_id,
                $booking->affiliate_id,
                $booking->created_by,
                'rental',
                'rental.overdue',
                $title,
                $body,
                '/bookings',
                $actionPayload,
                "{$dedupPrefix}:{$booking->created_by}"
            );
            if ($notifCreator) {
                $notifications[] = $notifCreator;
            }
        }

        return $notifications;
    }

    /**
     * Triggered when a branch's ready inventory drops to zero or critically low.
     */
    public function notifyLowFleetStock(\App\Models\Affiliate $branch, int $readyCount): array
    {
        $business = Business::find($branch->business_id);
        if (!$business || !$business->owner_id) {
            return [];
        }

        $todayDate = Carbon::today()->format('Y-m-d');
        $dedupKey = "inventory.low_stock:{$branch->id}:{$todayDate}:{$business->owner_id}";

        $title = "Stok Siap Sewa Menipis ({$branch->name})";
        $body = "Cabang {$branch->name} ({$branch->code}) saat ini hanya memiliki {$readyCount} unit siap sewa. Pertimbangkan mutasi unit dari cabang lain untuk memenuhi permintaan sewa.";

        $notif = $this->notify(
            $branch->business_id,
            $branch->id,
            $business->owner_id,
            'system',
            'inventory.low_stock',
            $title,
            $body,
            '/inventory',
            ['branch_id' => $branch->id, 'ready_count' => $readyCount],
            $dedupKey
        );

        return $notif ? [$notif] : [];
    }
}
