<?php

namespace App\Console\Commands;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\Booking;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckOverdueRentalsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rentcore:check-overdue-rentals 
                            {--grace-minutes=60 : Grace period in minutes before triggering notification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan active rentals for overdue returns beyond grace period and emit automated notifications';

    public function __construct(protected NotificationService $notificationService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $graceMinutes = (int) $this->option('grace-minutes');
        $cutoffTime = Carbon::now()->subMinutes($graceMinutes);

        $this->info("Scanning active bookings with return deadline earlier than {$cutoffTime->toDateTimeString()} (Grace period: {$graceMinutes}m)...");

        // 1. Scan Overdue Rentals (Chunked to prevent memory exhaustion at SaaS scale)
        $totalOverdueScanned = 0;
        $overdueAlertCount = 0;

        Booking::withoutGlobalScopes()
            ->with(['customer', 'affiliate'])
            ->where('status', 'rented')
            ->where('end_at', '<', $cutoffTime)
            ->chunkById(100, function ($overdueBookings) use (&$overdueAlertCount, &$totalOverdueScanned) {
                foreach ($overdueBookings as $booking) {
                    $totalOverdueScanned++;
                    $overdueHours = max(1, (int) ceil(Carbon::now()->diffInMinutes($booking->end_at) / 60));
                    $notifs = $this->notificationService->notifyRentalOverdue($booking, $overdueHours);
                    if (!empty($notifs)) {
                        $overdueAlertCount += count($notifs);
                        $this->line(" [OVERDUE ALERT] Booking {$booking->booking_code} ({$booking->customer?->name}) overdue by {$overdueHours}h -> Notified.");
                    }
                }
            });

        $this->info("Overdue check complete. Sent {$overdueAlertCount} notification(s) across {$totalOverdueScanned} overdue rental(s).");

        // 2. Scan Low Inventory Fleet Alerts (Single grouped aggregation query)
        $this->info("Scanning branch fleet inventory for low stock alerts...");
        $branches = Affiliate::where('is_active', true)->get();
        $lowFleetAlertCount = 0;

        $readyStockCounts = Asset::withoutGlobalScopes()
            ->where('status', 'ready')
            ->select('affiliate_id', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
            ->groupBy('affiliate_id')
            ->pluck('total', 'affiliate_id');

        foreach ($branches as $branch) {
            $readyCount = (int) ($readyStockCounts[$branch->id] ?? 0);

            if ($readyCount <= 1) {
                $notifs = $this->notificationService->notifyLowFleetStock($branch, $readyCount);
                if (!empty($notifs)) {
                    $lowFleetAlertCount += count($notifs);
                    $this->line(" [LOW FLEET ALERT] Branch {$branch->name} has only {$readyCount} ready unit(s) -> Notified.");
                }
            }
        }

        $this->info("Fleet check complete. Sent {$lowFleetAlertCount} low stock notification(s).");

        return Command::SUCCESS;
    }
}
