<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessLatePickupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:process-late-pickups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process late pickup bookings, auto-expire unpaid or fully past-window reservations, and alert branch staff';

    public function __construct(
        protected BookingService $bookingService,
        protected NotificationService $notificationService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command idempotently.
     */
    public function handle(): int
    {
        $now = Carbon::now();

        $expiredCount = 0;
        $autoNoShowCount = 0;
        $latePickupCount = 0;

        Booking::withoutGlobalScopes()
            ->with(['business', 'affiliate', 'customer', 'assetModel', 'items.asset.model', 'payments'])
            ->whereIn('status', ['booked', 'reserved', 'confirmed', 'late_pickup'])
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->chunkById(100, function ($bookings) use ($now, &$expiredCount, &$autoNoShowCount, &$latePickupCount) {
                foreach ($bookings as $booking) {
                    $startAt = Carbon::parse($booking->start_at);
                    $endAt = Carbon::parse($booking->end_at);

                    // 1. Past full scheduled window (now() >= end_at) -> expire and release stock
                    if ($now->gte($endAt)) {
                        $this->bookingService->expireUncollectedBooking(
                            $booking,
                            'Otomatis kedaluwarsa karena melewati batas akhir jadwal sewa.'
                        );
                        $expiredCount++;
                        continue;
                    }

                    $policy = $booking->business
                        ? $booking->business->getLatePickupPolicy()
                        : [
                            'booking_grace_period_minutes' => 60,
                            'auto_expire_unpaid_booking' => true,
                            'auto_expire_paid_booking' => false,
                        ];

                    $graceMinutes = max(1, (int) ($policy['booking_grace_period_minutes'] ?? 60));
                    $defaultDeadline = $startAt->copy()->addMinutes($graceMinutes);
                    $extendedDeadline = $booking->grace_extended_until
                        ? Carbon::parse($booking->grace_extended_until)
                        : ($booking->grace_expires_at ? Carbon::parse($booking->grace_expires_at) : null);

                    $effectiveDeadline = ($extendedDeadline && $extendedDeadline->gt($defaultDeadline))
                        ? $extendedDeadline
                        : $defaultDeadline;

                    // Still within grace period
                    if ($now->lte($effectiveDeadline)) {
                        continue;
                    }

                    // 2. Past grace deadline and before end_at
                    $paidAmount = (float) $booking->paid_amount;

                    if ($paidAmount <= 0 && ($policy['auto_expire_unpaid_booking'] ?? true)) {
                        $updated = $this->bookingService->markNoShow(
                            $booking,
                            null,
                            'Otomatis ditandai No-Show karena booking belum dibayar melewati batas waktu pengambilan.',
                            true
                        );
                        $this->notificationService->notifyBookingAutoNoShow($updated);
                        $autoNoShowCount++;
                        continue;
                    }

                    if ($paidAmount > 0 && ($policy['auto_expire_paid_booking'] ?? false)) {
                        $updated = $this->bookingService->markNoShow(
                            $booking,
                            null,
                            'Otomatis ditandai No-Show karena melewati batas waktu pengambilan.',
                            true
                        );
                        $this->notificationService->notifyBookingAutoNoShow($updated);
                        $autoNoShowCount++;
                        continue;
                    }

                    // Retain capacity under late_pickup status & dispatch idempotent branch notification once
                    if ($booking->status !== 'late_pickup' || !$booking->is_late_pickup) {
                        $booking->update([
                            'status' => 'late_pickup',
                            'is_late_pickup' => true,
                            'is_overdue_pickup' => true,
                        ]);
                        $this->notificationService->notifyBookingLatePickup($booking->fresh(['customer', 'affiliate', 'business']), $effectiveDeadline);
                        $latePickupCount++;
                    }
                }
            });

        $this->info("Late pickups processed. Expired: {$expiredCount}, Auto No-Show: {$autoNoShowCount}, Late Pickup Flagged: {$latePickupCount}.");

        return Command::SUCCESS;
    }
}
