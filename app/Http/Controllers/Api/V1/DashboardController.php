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
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();
        $isSuperAdmin = $user && $user->isSuperAdmin();

        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwner = $member ? $member->isOwner() : ($isSuperAdmin || ($user && $user->isPlatformAdmin()));
        $isStaff = $member ? $member->isStaff() : ($user && $user->isStaff());

        // Resolve requested branch from query parameter or header
        $rawBranchId = $request->get('branch_id') ?? $request->get('affiliate_id') ?? $request->header('X-Branch-Id');
        $requestedBranchId = $rawBranchId !== null ? (int)$rawBranchId : null;
        $targetBranchId = null;

        // Authorize branch scope
        if ($user && ($isStaff || !$isOwner)) {
            $allowedBranches = $user->getBranchIdsForBusiness($businessId);
            if (empty($allowedBranches) && $user->affiliate_id) {
                $allowedBranches = [(int)$user->affiliate_id];
            }

            if ($requestedBranchId !== null) {
                if (!in_array($requestedBranchId, $allowedBranches, true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Akses cabang ditolak. Anda tidak berwenang melihat data cabang ini.',
                        'code' => 'BRANCH_ACCESS_DENIED',
                    ], 403);
                }
                $targetBranchId = $requestedBranchId;
            } else {
                $targetBranchId = !empty($allowedBranches) ? $allowedBranches[0] : ($user->affiliate_id ? (int)$user->affiliate_id : null);
            }
        } else {
            // Business Owner / Platform Admin: specific branch or consolidated (null)
            if ($requestedBranchId !== null) {
                if ($businessId) {
                    $branchValid = \App\Models\Affiliate::where('id', $requestedBranchId)
                        ->where('business_id', $businessId)
                        ->where('is_active', true)
                        ->exists();

                    if (!$branchValid) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cabang tidak valid untuk bisnis rental ini.',
                            'code' => 'INVALID_BRANCH',
                        ], 422);
                    }
                }
                $targetBranchId = $requestedBranchId;
            } else {
                // If legacy user without business context
                if (!$businessId && $user && $user->affiliate_id && !$isSuperAdmin) {
                    $targetBranchId = (int)$user->affiliate_id;
                } else {
                    $targetBranchId = null; // Consolidated
                }
            }
        }

        $now = Carbon::now();
        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();

        // 1. Asset Inventory Breakdown (Scoped to target branch or consolidated)
        $assetQuery = Asset::query();
        if ($businessId) {
            $assetQuery->where('business_id', $businessId);
        }
        if ($targetBranchId !== null) {
            $assetQuery->where('affiliate_id', $targetBranchId);
        }

        $unitsTotal = (clone $assetQuery)->whereNotIn('status', ['retired', 'lost'])->count();
        $unitsReady = (clone $assetQuery)->where('status', 'ready')->count();
        $unitsRented = (clone $assetQuery)->where('status', 'rented')->count();
        $unitsMaintenance = (clone $assetQuery)->where('status', 'maintenance')->count();
        $unitsInTransit = (clone $assetQuery)->where('status', 'in_transit')->count();
        $utilizationRate = $unitsTotal > 0 ? round(($unitsRented / $unitsTotal) * 100, 1) : 0.0;

        // 2. Operational Bookings Metrics (Scoped to target branch or consolidated)
        $bookingQuery = Booking::query();
        if ($businessId) {
            $bookingQuery->where('business_id', $businessId);
        }
        if ($targetBranchId !== null) {
            $bookingQuery->where('affiliate_id', $targetBranchId);
        }

        $dueToday = (clone $bookingQuery)->where('status', 'rented')
            ->whereBetween('end_at', [$todayStart, $todayEnd])
            ->count();

        $todayRentalsCreated = (clone $bookingQuery)->whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $activeRentals = $unitsRented;

        $overdueBookings = (clone $bookingQuery)->with(['customer', 'items.asset.model'])
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
        $imminentReturns = (clone $bookingQuery)->with(['customer', 'items.asset.model'])
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

        // 4. Financial Summary Today (Scoped by original transaction branch)
        $paymentQuery = PaymentTransaction::whereBetween('transacted_at', [$todayStart, $todayEnd]);
        if ($businessId) {
            $paymentQuery->where('business_id', $businessId);
        }
        if ($targetBranchId !== null) {
            $paymentQuery->whereHas('booking', function ($bq) use ($targetBranchId) {
                $bq->where('affiliate_id', $targetBranchId);
            });
        }
        $todayRevenue = (float)$paymentQuery->sum('amount');
        $todayTransactionsCount = (int)$paymentQuery->count();

        return response()->json([
            'success' => true,
            'data' => [
                'branch_id' => $targetBranchId,
                'is_consolidated' => $targetBranchId === null,
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
