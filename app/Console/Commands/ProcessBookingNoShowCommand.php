<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessBookingNoShowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:process-no-show';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send grace-period pickup warnings and process expired scheduled bookings (auto no-show or overdue pickup flag)';

    public function __construct(
        protected BookingService $bookingService,
        protected NotificationService $notificationService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $warningCutoff = (clone $now)->addMinutes(15);

        $warningCount = 0;
        $autoNoShowCount = 0;
        $overdueFlaggedCount = 0;

        // 1. Grace Period Warning (within 15 minutes before grace_expires_at)
        Booking::withoutGlobalScopes()
            ->with(['customer', 'assetModel', 'items.asset.model', 'affiliate'])
            ->whereIn('status', ['booked', 'reserved', 'confirmed'])
            ->whereNotNull('grace_expires_at')
            ->where('grace_expires_at', '>', $now)
            ->where('grace_expires_at', '<=', $warningCutoff)
            ->chunkById(100, function ($bookings) use (&$warningCount) {
                foreach ($bookings as $booking) {
                    $notifs = $this->notificationService->notifyBookingGraceWarning($booking);
                    if (!empty($notifs)) {
                        $warningCount += count($notifs);
                    }
                }
            });

        // 2. Expired Grace Period (grace_expires_at < now())
        Booking::withoutGlobalScopes()
            ->with(['affiliate', 'customer', 'assetModel', 'items.asset.model', 'payments'])
            ->whereIn('status', ['booked', 'reserved', 'confirmed'])
            ->whereNotNull('grace_expires_at')
            ->where('grace_expires_at', '<', $now)
            ->chunkById(100, function ($expiredBookings) use (&$autoNoShowCount, &$overdueFlaggedCount) {
                foreach ($expiredBookings as $booking) {
                    $branch = $booking->affiliate;
                    $autoNoShow = $branch ? (bool) ($branch->booking_auto_noshow ?? true) : true;

                    if ($autoNoShow) {
                        $updated = $this->bookingService->markNoShow(
                            $booking,
                            null,
                            'Otomatis ditandai No-Show karena melewati masa tenggang pengambilan unit.',
                            true
                        );
                        $this->notificationService->notifyBookingAutoNoShow($updated);
                        $autoNoShowCount++;
                    } elseif (!$booking->is_overdue_pickup) {
                        $booking->update(['is_overdue_pickup' => true]);
                        $overdueFlaggedCount++;
                    }
                }
            });

        $this->info("Process no-show complete. Warnings: {$warningCount}, Auto No-Show: {$autoNoShowCount}, Flagged Overdue Pickup: {$overdueFlaggedCount}.");

        return Command::SUCCESS;
    }
}
