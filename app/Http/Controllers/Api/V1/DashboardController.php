<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Retrieve counter KPI statistics and alert queues for the mobile home dashboard.
     * Respects multi-tenant franchise isolation.
     */
    public function kpi(Request $request): JsonResponse
    {
        $user = $request->user();
        $affiliateId = $user ? $user->affiliate_id : null;
        $isSuperAdmin = $user && $user->isSuperAdmin();

        $now = Carbon::now();
        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();

        // 1. Asset Inventory Breakdown (Mutually Exclusive Statuses)
        // Assets are automatically scoped by FranchiseTenantScope
        $unitsTotal = Asset::count();
        $unitsReady = Asset::where('status', 'ready')->count();
        $unitsRented = Asset::where('status', 'rented')->count();
        $unitsMaintenance = Asset::where('status', 'maintenance')->count();
        $unitsInTransit = Asset::where('status', 'in_transit')->count();
        $utilizationRate = $unitsTotal > 0 ? round(($unitsRented / $unitsTotal) * 100, 1) : 0.0;

        // 2. Operational Bookings Metrics
        // Bookings are automatically scoped by FranchiseTenantScope
        $dueToday = Booking::where('status', 'rented')
            ->whereBetween('end_at', [$todayStart, $todayEnd])
            ->count();

        $todayRentalsCreated = Booking::whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $activeRentals = $unitsRented;

        $overdueBookings = Booking::with(['customer', 'items.asset.model'])
            ->where('status', 'rented')
            ->where('end_at', '<', $now)
            ->orderBy('end_at', 'asc')
            ->get();

        $overdueCount = $overdueBookings->count();

        // Format overdue alerts with calculated late duration and running fee
        $overdueList = $overdueBookings->map(function ($booking) use ($now) {
            $endAt = Carbon::parse($booking->end_at);
            $lateMinutes = $endAt->diffInMinutes($now);
            $hours = intdiv($lateMinutes, 60);
            $mins = $lateMinutes % 60;

            // Penalty running: 60-min tolerance
            $billableHours = max(0, (int)ceil(($lateMinutes - 60) / 60));
            $runningFee = $billableHours * 15000;

            $firstItem = $booking->items->first();
            $unitName = $firstItem && $firstItem->asset ? $firstItem->asset->model->name : 'Unit Gadget';

            return [
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'customer_name' => $booking->customer->name,
                'customer_phone' => $booking->customer->phone,
                'unit_name' => $unitName,
                'late_duration_text' => sprintf('%02d jam %02d mnt', $hours, $mins),
                'late_minutes' => $lateMinutes,
                'running_penalty' => $runningFee,
                'end_at' => $booking->end_at->format('d M Y, H:i'),
                'collateral_storage_slot' => $booking->collateral_storage_slot,
            ];
        });

        // 3. Imminent Returns (within next 4 hours)
        $imminentReturns = Booking::with(['customer', 'items.asset.model'])
            ->where('status', 'rented')
            ->whereBetween('end_at', [$now, (clone $now)->addHours(4)])
            ->orderBy('end_at', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($booking) {
                $firstItem = $booking->items->first();
                $unitName = $firstItem && $firstItem->asset ? $firstItem->asset->model->name : 'Unit Gadget';

                return [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'customer_name' => $booking->customer->name,
                    'customer_phone' => $booking->customer->phone,
                    'unit_name' => $unitName,
                    'expected_return_at' => $booking->end_at->format('H:i \W\I\B'),
                    'collateral_storage_slot' => $booking->collateral_storage_slot,
                ];
            });

        // 4. Financial Summary Today (Multi-tenant scoped)
        $paymentQuery = PaymentTransaction::whereBetween('transacted_at', [$todayStart, $todayEnd]);
        if ($affiliateId && !$isSuperAdmin) {
            $paymentQuery->whereHas('booking', function ($bq) use ($affiliateId) {
                $bq->where('affiliate_id', $affiliateId);
            });
        }
        $todayRevenue = (float)$paymentQuery->sum('amount');
        $todayTransactionsCount = $paymentQuery->count();

        return response()->json([
            'success' => true,
            'data' => [
                'kpi' => [
                    // Inventory overview
                    'units_total' => $unitsTotal,
                    'units_ready' => $unitsReady,
                    'units_rented' => $unitsRented,
                    'units_maintenance' => $unitsMaintenance,
                    'units_in_transit' => $unitsInTransit,
                    'utilization_rate' => $utilizationRate,
                    // Operational metrics
                    'active_rentals' => $activeRentals,
                    'due_today' => $dueToday,
                    'overdue' => $overdueCount,
                    'today_rentals_created' => $todayRentalsCreated,
                    // Financial summary
                    'today_revenue' => $todayRevenue,
                    'today_transactions_count' => $todayTransactionsCount,
                ],
                'overdue_alerts' => $overdueList,
                'imminent_returns' => $imminentReturns,
            ],
        ]);
    }
}
