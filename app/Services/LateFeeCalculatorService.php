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
    public const VALID_ROUNDING_MODES = ['hourly_ceil', 'half_hourly_ceil', 'exact_minute'];
    public const VALID_GRACE_MODES = ['forfeit', 'deductive'];
    public const VALID_DEFAULT_TYPES = ['fixed', 'percent_daily'];
    public const VALID_ADDON_POLICIES = ['follow_main_unit', 'per_item'];

    /**
     * Resolve effective hourly late fee and its source via cascading hierarchy:
     * Unit override -> Model rate -> Business default (fixed or % daily) -> 0.
     *
     * @return array{rate: float, source: string}
     */
    public function resolveEffectiveHourlyLateFeeWithSource(
        ?Asset $asset = null,
        ?AssetModel $model = null,
        ?Business $business = null,
        ?PricingTier $tier = null
    ): array {
        $resolvedModel = $model ?? $asset?->model ?? $asset?->assetModel;
        $resolvedBusiness = $business
            ?? ($asset?->business_id ? Business::find($asset->business_id) : null)
            ?? ($resolvedModel?->business_id ? Business::find($resolvedModel->business_id) : null);

        if ($asset && $asset->hourly_late_fee !== null && (float) $asset->hourly_late_fee > 0) {
            return [
                'rate' => round((float) $asset->hourly_late_fee, 2),
                'source' => 'unit',
            ];
        }

        if ($resolvedModel && $resolvedModel->hourly_late_fee !== null && (float) $resolvedModel->hourly_late_fee > 0) {
            return [
                'rate' => round((float) $resolvedModel->hourly_late_fee, 2),
                'source' => 'model',
            ];
        }

        if ($resolvedBusiness) {
            $policy = $resolvedBusiness->getLateFeePolicy();
            $defaultRate = (float) ($policy['late_fee_default_rate'] ?? 0.0);
            $defaultType = (string) ($policy['late_fee_default_type'] ?? 'fixed');

            if ($defaultType === 'percent_daily') {
                if ($defaultRate > 0) {
                    $dailyPrice = $this->resolveModelDailyPrice($resolvedModel, $tier);
                    if ($dailyPrice !== null && $dailyPrice > 0) {
                        return [
                            'rate' => round(($dailyPrice * $defaultRate) / 100.0, 2),
                            'source' => 'business_percent_daily',
                        ];
                    }
                }
            } elseif ($defaultRate > 0) {
                return [
                    'rate' => round(max(0.0, $defaultRate), 2),
                    'source' => 'business_default',
                ];
            }
        }

        return [
            'rate' => 0.0,
            'source' => 'none',
        ];
    }

    /**
     * Resolve effective hourly late fee via cascading hierarchy:
     * Unit override -> Model default -> Business default -> 0.
     */
    public function resolveEffectiveHourlyLateFee(
        ?Asset $asset = null,
        ?AssetModel $model = null,
        ?Business $business = null,
        ?PricingTier $tier = null
    ): float {
        return $this->resolveEffectiveHourlyLateFeeWithSource($asset, $model, $business, $tier)['rate'];
    }

    /**
     * Resolve the 1-day (24h) rental tariff for a model, independent of whether daily cap is toggled.
     */
    public function resolveModelDailyPrice(
        ?AssetModel $model = null,
        ?PricingTier $tier = null
    ): ?float {
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

        return $this->resolveModelDailyPrice($model, $tier);
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
            : $this->defaultPolicy();

        $effectiveHourlyRate = $this->resolveEffectiveHourlyLateFee($asset, $resolvedModel, $resolvedBusiness, $tier);
        $dailyRateCap = $policy['cap_late_fee_at_daily_rate']
            ? $this->resolveDailyRateCap($resolvedModel, $tier, $resolvedBusiness)
            : null;

        return [
            'applied_hourly_late_fee' => $effectiveHourlyRate,
            'applied_grace_period_minutes' => (int) $policy['return_grace_period_minutes'],
            'applied_late_fee_grace_mode' => (string) $policy['late_fee_grace_mode'],
            'applied_daily_rate_cap' => $dailyRateCap,
            'applied_late_fee_rounding_mode' => (string) $policy['late_fee_rounding_mode'],
        ];
    }

    /**
     * Single Source of Truth 4-step deterministic late fee calculation:
     * 1. raw_overdue_minutes
     * 2. grace_period_evaluation (forfeit vs deductive)
     * 3. billable_units_rounding (hourly_ceil, half_hourly_ceil, exact_minute)
     * 4. daily_cap_enforcement (per 24h cycle)
     */
    public function calculate(Booking $booking, ?Carbon $returnedAt = null): array
    {
        $booking->loadMissing(['business', 'items.asset.model', 'items.assetModel']);

        $business = $booking->business ?? ($booking->business_id ? Business::find($booking->business_id) : null);
        $businessTz = $business?->timezone ?: 'Asia/Jakarta';
        $policy = $business ? $business->getLateFeePolicy() : $this->defaultPolicy();

        $dueAt = $booking->end_at instanceof Carbon
            ? $booking->end_at->copy()->setTimezone($businessTz)
            : Carbon::parse($booking->end_at)->setTimezone($businessTz);
        $actualReturnedAt = ($returnedAt ? $returnedAt->copy() : Carbon::now($businessTz))->setTimezone($businessTz);

        // Step 1: raw_overdue_minutes (signed diff in business timezone so overdue is always positive)
        $signedOverdueMinutes = $dueAt->diffInMinutes($actualReturnedAt, false);
        $rawOverdueMinutes = $signedOverdueMinutes > 0
            ? (int) floor($signedOverdueMinutes)
            : 0;

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

        $graceMode = $primaryItem?->applied_late_fee_grace_mode
            ?? ($rateSnapshot['applied_late_fee_grace_mode'] ?? null)
            ?? $policy['late_fee_grace_mode'];
        if (!in_array($graceMode, self::VALID_GRACE_MODES, true)) {
            $graceMode = 'forfeit';
        }

        $roundingMode = $primaryItem?->applied_late_fee_rounding_mode
            ?? ($rateSnapshot['applied_late_fee_rounding_mode'] ?? null)
            ?? $policy['late_fee_rounding_mode'];
        if (!in_array($roundingMode, self::VALID_ROUNDING_MODES, true)) {
            $roundingMode = 'hourly_ceil';
        }

        $resolvedModel = $primaryItem?->asset?->model
            ?? $primaryItem?->assetModel
            ?? ($booking->asset_model_id ? AssetModel::withoutGlobalScopes()->find($booking->asset_model_id) : null);
        $tier = !empty($rateSnapshot['tier_id']) ? PricingTier::find($rateSnapshot['tier_id']) : null;

        $snapshotAppliedRate = null;
        if ($primaryItem && $primaryItem->applied_hourly_late_fee !== null && (float) $primaryItem->applied_hourly_late_fee > 0) {
            $snapshotAppliedRate = round((float) $primaryItem->applied_hourly_late_fee, 2);
        } elseif (isset($rateSnapshot['applied_hourly_late_fee']) && (float) $rateSnapshot['applied_hourly_late_fee'] > 0) {
            $snapshotAppliedRate = round((float) $rateSnapshot['applied_hourly_late_fee'], 2);
        }

        if ($snapshotAppliedRate !== null) {
            $hourlyRate = $snapshotAppliedRate;
            $rateSource = 'contract_snapshot';
        } else {
            $resolved = $this->resolveEffectiveHourlyLateFeeWithSource(
                $primaryItem?->asset,
                $resolvedModel,
                $business,
                $tier
            );
            $hourlyRate = $resolved['rate'];
            $rateSource = $resolved['source'];
        }

        if ($primaryItem && $primaryItem->applied_daily_rate_cap !== null) {
            $dailyRateCap = (float) $primaryItem->applied_daily_rate_cap;
        } elseif (array_key_exists('applied_daily_rate_cap', $rateSnapshot)) {
            $dailyRateCap = $rateSnapshot['applied_daily_rate_cap'] !== null
                ? (float) $rateSnapshot['applied_daily_rate_cap']
                : null;
        } elseif ($hasContractSnapshot) {
            $dailyRateCap = null;
        } else {
            $dailyRateCap = $policy['cap_late_fee_at_daily_rate']
                ? $this->resolveDailyRateCap($resolvedModel, $tier, $business)
                : null;
        }

        $addonPolicy = (string) ($policy['late_fee_addon_policy'] ?? 'follow_main_unit');
        $allowWaiver = (bool) ($policy['allow_cashier_late_fee_waiver'] ?? true);

        // Step 2: grace_period_evaluation
        if ($rawOverdueMinutes <= $graceMinutes) {
            return $this->formatResult(
                isLate: false,
                dueAt: $dueAt,
                evaluatedAt: $actualReturnedAt,
                rawOverdueMinutes: $rawOverdueMinutes,
                graceMinutes: $graceMinutes,
                graceMode: $graceMode,
                billableMinutes: 0,
                billedUnits: 0,
                unitLabel: $this->unitLabelForRounding($roundingMode),
                billableHours: 0.0,
                appliedRate: $hourlyRate,
                rateSource: $rateSource,
                roundingMode: $roundingMode,
                dailyRateCap: $dailyRateCap,
                capApplied: false,
                rawLateFee: 0.0,
                calculatedFee: 0.0,
                addonPolicy: $addonPolicy,
                allowWaiver: $allowWaiver
            );
        }

        $billableMinutes = $graceMode === 'deductive'
            ? max(0, $rawOverdueMinutes - $graceMinutes)
            : $rawOverdueMinutes;

        // Step 3: billable_units_rounding
        ['billed_units' => $billedUnits, 'unit_label' => $unitLabel, 'billable_hours' => $billableHours] =
            $this->roundBillableTime($billableMinutes, $roundingMode);

        // Step 4: daily_cap_enforcement (Primary unit)
        ['raw_fee' => $rawLateFee, 'calculated_fee' => $calculatedLateFee, 'cap_applied' => $capApplied] =
            $this->computeFeeWithDailyCap($billableHours, $hourlyRate, $dailyRateCap);

        // Optional add-on per-item late fees when policy is 'per_item'
        if ($addonPolicy === 'per_item') {
            $addonItems = $booking->items->filter(function ($item) use ($primaryItem) {
                if ($primaryItem && $item->id === $primaryItem->id) {
                    return false;
                }
                $isActive = $item->status === 'active' || $item->status === null;
                $isAddon = $item->item_type === 'addon'
                    || (bool) ($item->asset?->model?->is_addon ?? $item->assetModel?->is_addon ?? false);
                return $isActive && $isAddon;
            });

            foreach ($addonItems as $addonItem) {
                $addonModel = $addonItem->asset?->model ?? $addonItem->assetModel;
                $addonRate = $addonItem->applied_hourly_late_fee !== null
                    ? (float) $addonItem->applied_hourly_late_fee
                    : $this->resolveEffectiveHourlyLateFee($addonItem->asset, $addonModel, $business);
                if ($addonRate <= 0) {
                    continue;
                }
                $addonCap = $policy['cap_late_fee_at_daily_rate']
                    ? $this->resolveDailyRateCap($addonModel, null, $business)
                    : null;
                $addonResult = $this->computeFeeWithDailyCap($billableHours, $addonRate, $addonCap);
                $rawLateFee = round($rawLateFee + $addonResult['raw_fee'], 2);
                $calculatedLateFee = round($calculatedLateFee + $addonResult['calculated_fee'], 2);
                $capApplied = $capApplied || $addonResult['cap_applied'];
            }
        }

        return $this->formatResult(
            isLate: true,
            dueAt: $dueAt,
            evaluatedAt: $actualReturnedAt,
            rawOverdueMinutes: $rawOverdueMinutes,
            graceMinutes: $graceMinutes,
            graceMode: $graceMode,
            billableMinutes: $billableMinutes,
            billedUnits: $billedUnits,
            unitLabel: $unitLabel,
            billableHours: $billableHours,
            appliedRate: $hourlyRate,
            rateSource: $rateSource,
            roundingMode: $roundingMode,
            dailyRateCap: $dailyRateCap,
            capApplied: $capApplied,
            rawLateFee: $rawLateFee,
            calculatedFee: $calculatedLateFee,
            addonPolicy: $addonPolicy,
            allowWaiver: $allowWaiver
        );
    }

    /**
     * @return array{billed_units: int, unit_label: string, billable_hours: float}
     */
    private function roundBillableTime(int $billableMinutes, string $roundingMode): array
    {
        if ($roundingMode === 'half_hourly_ceil') {
            $blocks = (int) ceil($billableMinutes / 30);
            return [
                'billed_units' => $blocks,
                'unit_label' => '30 menit',
                'billable_hours' => $blocks * 0.5,
            ];
        }

        if ($roundingMode === 'exact_minute') {
            return [
                'billed_units' => $billableMinutes,
                'unit_label' => 'menit',
                'billable_hours' => $billableMinutes / 60.0,
            ];
        }

        $hours = (int) ceil($billableMinutes / 60);
        return [
            'billed_units' => $hours,
            'unit_label' => 'jam',
            'billable_hours' => (float) $hours,
        ];
    }

    private function unitLabelForRounding(string $roundingMode): string
    {
        return match ($roundingMode) {
            'half_hourly_ceil' => '30 menit',
            'exact_minute' => 'menit',
            default => 'jam',
        };
    }

    /**
     * @return array{raw_fee: float, calculated_fee: float, cap_applied: bool}
     */
    private function computeFeeWithDailyCap(float $billableHours, float $hourlyRate, ?float $dailyRateCap): array
    {
        $rawFee = round($billableHours * $hourlyRate, 2);
        $calculatedFee = $rawFee;
        $capApplied = false;

        if ($dailyRateCap !== null && $dailyRateCap > 0 && $calculatedFee > 0) {
            $fullCycles = (int) floor($billableHours / 24);
            $remainingHours = max(0.0, $billableHours - ($fullCycles * 24));

            $perFullCycleCost = min(round(24 * $hourlyRate, 2), $dailyRateCap);
            $remainingCycleCost = min(round($remainingHours * $hourlyRate, 2), $dailyRateCap);
            $cappedFee = round(($fullCycles * $perFullCycleCost) + $remainingCycleCost, 2);

            if ($cappedFee < $calculatedFee) {
                $calculatedFee = $cappedFee;
                $capApplied = true;
            }
        }

        return [
            'raw_fee' => $rawFee,
            'calculated_fee' => $calculatedFee,
            'cap_applied' => $capApplied,
        ];
    }

    private function defaultPolicy(): array
    {
        return [
            'return_grace_period_minutes' => 15,
            'late_fee_grace_minutes' => 15,
            'late_fee_grace_mode' => 'forfeit',
            'late_fee_rounding_mode' => 'hourly_ceil',
            'late_fee_rounding' => 'hourly_ceil',
            'cap_late_fee_at_daily_rate' => true,
            'late_fee_cap_daily' => true,
            'default_hourly_late_fee' => 0.0,
            'late_fee_default_rate' => 0.0,
            'late_fee_default_type' => 'fixed',
            'late_fee_addon_policy' => 'follow_main_unit',
            'allow_cashier_late_fee_waiver' => true,
        ];
    }

    private function formatResult(
        bool $isLate,
        Carbon $dueAt,
        Carbon $evaluatedAt,
        int $rawOverdueMinutes,
        int $graceMinutes,
        string $graceMode,
        int $billableMinutes,
        int $billedUnits,
        string $unitLabel,
        float $billableHours,
        float $appliedRate,
        string $rateSource,
        string $roundingMode,
        ?float $dailyRateCap,
        bool $capApplied,
        float $rawLateFee,
        float $calculatedFee,
        string $addonPolicy,
        bool $allowWaiver
    ): array {
        return [
            // Canonical breakdown keys
            'is_late' => $isLate,
            'due_at' => $dueAt->toIso8601String(),
            'evaluated_at' => $evaluatedAt->toIso8601String(),
            'raw_overdue_minutes' => $rawOverdueMinutes,
            'grace_minutes' => $graceMinutes,
            'grace_mode' => $graceMode,
            'billable_minutes' => $billableMinutes,
            'billed_units' => $billedUnits,
            'unit_label' => $unitLabel,
            'applied_rate' => round($appliedRate, 2),
            'rate_source' => $rateSource,
            'rounding_mode' => $roundingMode,
            'daily_rate_cap' => $dailyRateCap,
            'cap_applied' => $capApplied,
            'calculated_fee' => $calculatedFee,
            'addon_policy' => $addonPolicy,
            'allow_cashier_late_fee_waiver' => $allowWaiver,
            // Legacy aliases for full backward compatibility
            'overdue_minutes' => $rawOverdueMinutes,
            'grace_period_minutes' => $graceMinutes,
            'chargeable_minutes' => $billableMinutes,
            'billable_hours' => round($billableHours, 4),
            'hourly_rate' => round($appliedRate, 2),
            'was_capped' => $capApplied,
            'raw_late_fee' => $rawLateFee,
            'calculated_late_fee' => $calculatedFee,
        ];
    }
}
