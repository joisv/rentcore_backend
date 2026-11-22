<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Business;
use App\Models\PricingTier;
use Carbon\Carbon;

class LateFeeCalculatorService
{
    public const VALID_ROUNDING_MODES = ['hourly_ceil', 'half_hourly_ceil'];

    /**
     * Resolve effective hourly late fee via cascading hierarchy:
     * Unit override -> Model default -> Business default -> 0.
     */
    public function resolveEffectiveHourlyLateFee(
        ?Asset $asset = null,
        ?AssetModel $model = null,
        ?Business $business = null
    ): float {
        $resolvedModel = $model ?? $asset?->model ?? $asset?->assetModel;
        $resolvedBusiness = $business
            ?? ($asset?->business_id ? Business::find($asset->business_id) : null)
            ?? ($resolvedModel?->business_id ? Business::find($resolvedModel->business_id) : null);

        if ($asset && $asset->hourly_late_fee !== null) {
            return round(max(0.0, (float) $asset->hourly_late_fee), 2);
        }

        if ($resolvedModel && $resolvedModel->hourly_late_fee !== null) {
            return round(max(0.0, (float) $resolvedModel->hourly_late_fee), 2);
        }

        if ($resolvedBusiness && $resolvedBusiness->default_hourly_late_fee !== null) {
            return round(max(0.0, (float) $resolvedBusiness->default_hourly_late_fee), 2);
        }

        return 0.0;
    }

    /**
     * Resolve the daily rate cap (1-day = 24h tariff) for a model when tenant capping is active.
     */
    public function resolveDailyRateCap(
        ?AssetModel $model = null,
        ?PricingTier $tier = null,
        ?Business $business = null
    ): ?float {
        if ($business && !$business->cap_late_fee_at_daily_rate) {
            return null;
        }

        if ($model) {
            $dailyTier = PricingTier::where('model_id', $model->id)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where(function ($dq) {
                        $dq->where('duration_unit', 'day')->where('duration_value', 1);
                    })->orWhere('duration_hours', 24);
                })
                ->orderByRaw("CASE WHEN duration_unit = 'day' AND duration_value = 1 THEN 0 ELSE 1 END")
                ->first();

            if ($dailyTier && (float) $dailyTier->price > 0) {
                return round((float) $dailyTier->price, 2);
            }
        }

        if ($tier && (float) $tier->price > 0 && (int) $tier->duration_hours > 0) {
            if ((int) $tier->duration_hours === 24) {
                return round((float) $tier->price, 2);
            }
            if ((int) $tier->duration_hours > 24) {
                $days = max(1.0, (float) $tier->duration_hours / 24.0);
                return round((float) $tier->price / $days, 2);
            }
        }

        return null;
    }

    /**
     * Snapshot active late-fee policy parameters at rental checkout / handover start.
     */
    public function buildContractSnapshot(
        ?Asset $asset = null,
        ?AssetModel $model = null,
        ?Business $business = null,
        ?PricingTier $tier = null
    ): array {
        $resolvedModel = $model ?? $asset?->model ?? $asset?->assetModel;
        $resolvedBusiness = $business
            ?? ($asset?->business_id ? Business::find($asset->business_id) : null)
            ?? ($resolvedModel?->business_id ? Business::find($resolvedModel->business_id) : null);

        $policy = $resolvedBusiness
            ? $resolvedBusiness->getLateFeePolicy()
            : [
                'return_grace_period_minutes' => 15,
                'late_fee_rounding_mode' => 'hourly_ceil',
                'cap_late_fee_at_daily_rate' => true,
                'default_hourly_late_fee' => 0.0,
            ];

        $effectiveHourlyRate = $this->resolveEffectiveHourlyLateFee($asset, $resolvedModel, $resolvedBusiness);
        $dailyRateCap = $policy['cap_late_fee_at_daily_rate']
            ? $this->resolveDailyRateCap($resolvedModel, $tier, $resolvedBusiness)
            : null;

        return [
            'applied_hourly_late_fee' => $effectiveHourlyRate,
            'applied_grace_period_minutes' => (int) $policy['return_grace_period_minutes'],
            'applied_daily_rate_cap' => $dailyRateCap,
            'applied_late_fee_rounding_mode' => $policy['late_fee_rounding_mode'],
        ];
    }

    /**
     * Compute late minutes, billable hours, and calculated late fee for a booking.
     */
    public function calculate(Booking $booking, ?Carbon $returnedAt = null): array
    {
        $booking->loadMissing(['business', 'items.asset.model', 'items.assetModel']);

        $dueAt = Carbon::parse($booking->end_at)->setTimezone('UTC');
        $actualReturnedAt = ($returnedAt ?? Carbon::now('UTC'))->copy()->setTimezone('UTC');

        $overdueMinutes = $actualReturnedAt->gt($dueAt)
            ? (int) floor($dueAt->diffInMinutes($actualReturnedAt))
            : 0;

        $business = $booking->business ?? ($booking->business_id ? Business::find($booking->business_id) : null);
        $policy = $business
            ? $business->getLateFeePolicy()
            : [
                'return_grace_period_minutes' => 15,
                'late_fee_rounding_mode' => 'hourly_ceil',
                'cap_late_fee_at_daily_rate' => true,
                'default_hourly_late_fee' => 0.0,
            ];

        /** @var BookingItem|null $primaryItem */
        $primaryItem = $booking->items->first(function ($item) {
            return ($item->item_type === 'primary' || $item->item_type === null)
                && ($item->status === 'active' || $item->status === null);
        }) ?? $booking->items->first();

        $rateSnapshot = [];
        if ($primaryItem) {
            $rateSnapshot = is_array($primaryItem->rate_snapshot)
                ? $primaryItem->rate_snapshot
                : (json_decode($primaryItem->rate_snapshot ?? '[]', true) ?: []);
        }

        $hasContractSnapshot = $primaryItem && (
            $primaryItem->applied_hourly_late_fee !== null
            || $primaryItem->applied_grace_period_minutes !== null
            || $primaryItem->applied_daily_rate_cap !== null
            || $primaryItem->applied_late_fee_rounding_mode !== null
        );

        $graceMinutes = $primaryItem && $primaryItem->applied_grace_period_minutes !== null
            ? (int) $primaryItem->applied_grace_period_minutes
            : (isset($rateSnapshot['applied_grace_period_minutes'])
                ? (int) $rateSnapshot['applied_grace_period_minutes']
                : (int) $policy['return_grace_period_minutes']);

        $roundingMode = $primaryItem?->applied_late_fee_rounding_mode
            ?? ($rateSnapshot['applied_late_fee_rounding_mode'] ?? null)
            ?? $policy['late_fee_rounding_mode'];
        if (!in_array($roundingMode, self::VALID_ROUNDING_MODES, true)) {
            $roundingMode = 'hourly_ceil';
        }

        if ($primaryItem && $primaryItem->applied_hourly_late_fee !== null) {
            $hourlyRate = (float) $primaryItem->applied_hourly_late_fee;
        } elseif (isset($rateSnapshot['applied_hourly_late_fee'])) {
            $hourlyRate = (float) $rateSnapshot['applied_hourly_late_fee'];
        } else {
            $resolvedModel = $primaryItem?->asset?->model
                ?? $primaryItem?->assetModel
                ?? ($booking->asset_model_id ? AssetModel::withoutGlobalScopes()->find($booking->asset_model_id) : null);
            $hourlyRate = $this->resolveEffectiveHourlyLateFee($primaryItem?->asset, $resolvedModel, $business);
        }

        if ($primaryItem && $primaryItem->applied_daily_rate_cap !== null) {
            $dailyRateCap = (float) $primaryItem->applied_daily_rate_cap;
        } elseif (array_key_exists('applied_daily_rate_cap', $rateSnapshot)) {
            $dailyRateCap = $rateSnapshot['applied_daily_rate_cap'] !== null
                ? (float) $rateSnapshot['applied_daily_rate_cap']
                : null;
        } elseif ($hasContractSnapshot) {
            // Contract snapshot was already captured at checkout with capping disabled.
            $dailyRateCap = null;
        } else {
            $resolvedModel = $primaryItem?->asset?->model
                ?? $primaryItem?->assetModel
                ?? ($booking->asset_model_id ? AssetModel::withoutGlobalScopes()->find($booking->asset_model_id) : null);
            $tier = !empty($rateSnapshot['tier_id']) ? PricingTier::find($rateSnapshot['tier_id']) : null;
            $dailyRateCap = $policy['cap_late_fee_at_daily_rate']
                ? $this->resolveDailyRateCap($resolvedModel, $tier, $business)
                : null;
        }

        if ($overdueMinutes <= $graceMinutes) {
            return [
                'overdue_minutes' => $overdueMinutes,
                'grace_period_minutes' => $graceMinutes,
                'chargeable_minutes' => 0,
                'billable_hours' => 0.0,
                'hourly_rate' => round($hourlyRate, 2),
                'rounding_mode' => $roundingMode,
                'daily_rate_cap' => $dailyRateCap,
                'was_capped' => false,
                'raw_late_fee' => 0.0,
                'calculated_late_fee' => 0.0,
            ];
        }

        $chargeableMinutes = $overdueMinutes - $graceMinutes;

        if ($roundingMode === 'half_hourly_ceil') {
            $billableHours = (int) ceil($chargeableMinutes / 30) * 0.5;
        } else {
            $billableHours = (float) ceil($chargeableMinutes / 60);
        }

        $rawLateFee = round($billableHours * $hourlyRate, 2);
        $calculatedLateFee = $rawLateFee;
        $wasCapped = false;

        if ($dailyRateCap !== null && $dailyRateCap > 0 && $calculatedLateFee > 0) {
            $fullCycles = (int) floor($billableHours / 24);
            $remainingHours = max(0.0, $billableHours - ($fullCycles * 24));

            $perFullCycleCost = min(round(24 * $hourlyRate, 2), $dailyRateCap);
            $remainingCycleCost = min(round($remainingHours * $hourlyRate, 2), $dailyRateCap);
            $cappedFee = round(($fullCycles * $perFullCycleCost) + $remainingCycleCost, 2);

            if ($cappedFee < $calculatedLateFee) {
                $calculatedLateFee = $cappedFee;
                $wasCapped = true;
            }
        }

        return [
            'overdue_minutes' => $overdueMinutes,
            'grace_period_minutes' => $graceMinutes,
            'chargeable_minutes' => $chargeableMinutes,
            'billable_hours' => $billableHours,
            'hourly_rate' => round($hourlyRate, 2),
            'rounding_mode' => $roundingMode,
            'daily_rate_cap' => $dailyRateCap,
            'was_capped' => $wasCapped,
            'raw_late_fee' => $rawLateFee,
            'calculated_late_fee' => $calculatedLateFee,
        ];
    }
}
