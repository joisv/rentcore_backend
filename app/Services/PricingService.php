<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\PricingTier;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PricingService
{
    /**
     * Supported duration units.
     */
    public const UNITS = ['hour', 'day', 'week', 'month'];

    /**
     * Resolve tier, calculate rental duration, end time, and immutable rate snapshot.
     */
    public function calculate(
        AssetModel $model,
        string $durationUnit,
        int $durationValue,
        ?Carbon $startAt = null
    ): array {
        $startAt = $startAt ? (clone $startAt) : Carbon::now();
        $durationUnit = strtolower(trim($durationUnit));

        if (!in_array($durationUnit, self::UNITS)) {
            throw ValidationException::withMessages([
                'duration_unit' => ["Satuan durasi '{$durationUnit}' tidak didukung. Pilihan: hour, day, week, month."],
            ]);
        }

        if ($durationValue < 1) {
            throw ValidationException::withMessages([
                'duration_value' => ['Nilai durasi harus minimal 1.'],
            ]);
        }

        // 1. Find matching active tier
        $tier = PricingTier::where('model_id', $model->id)
            ->where('is_active', true)
            ->where(function ($q) use ($durationUnit, $durationValue) {
                $q->where(function ($uq) use ($durationUnit, $durationValue) {
                    $uq->where('duration_unit', $durationUnit)
                       ->where('duration_value', $durationValue);
                });
            })
            ->first();

        // Fallback for legacy hourly tiers
        if (!$tier && $durationUnit === 'hour') {
            $tier = PricingTier::where('model_id', $model->id)
                ->where('is_active', true)
                ->where('duration_hours', $durationValue)
                ->first();
        }

        // Fallback: if day unit requested and day tier not found, check if 24h * day exists in hours
        if (!$tier && $durationUnit === 'day') {
            $tier = PricingTier::where('model_id', $model->id)
                ->where('is_active', true)
                ->where('duration_hours', $durationValue * 24)
                ->first();
        }

        if (!$tier) {
            $unitLabel = $this->unitLabel($durationUnit, $durationValue);
            throw ValidationException::withMessages([
                'duration' => ["Paket durasi '{$unitLabel}' tidak tersedia untuk produk {$model->name}."],
            ]);
        }

        // 2. Compute exact duration_hours and end_at
        $durationHours = $this->computeDurationHours($durationUnit, $durationValue);
        $endAt = $this->computeEndAt($startAt, $durationUnit, $durationValue);

        $price = (float)$tier->price;
        $extensionHourlyRate = (float)$tier->extension_hourly_rate;

        $snapshot = [
            'tier_id' => $tier->id,
            'duration_unit' => $durationUnit,
            'duration_value' => $durationValue,
            'duration_hours' => $durationHours,
            'price' => $price,
            'extension_hourly_rate' => $extensionHourlyRate,
            'model_id' => $model->id,
            'model_name' => $model->name,
            'brand' => $model->brand,
            'category_name' => $model->category?->name,
            'calculated_at' => Carbon::now()->toIso8601String(),
        ];

        return [
            'tier' => $tier,
            'price' => $price,
            'extension_hourly_rate' => $extensionHourlyRate,
            'duration_unit' => $durationUnit,
            'duration_value' => $durationValue,
            'duration_hours' => $durationHours,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'rate_snapshot' => $snapshot,
        ];
    }

    /**
     * Compute equivalent hours for duration unit and value.
     */
    public function computeDurationHours(string $unit, int $value): int
    {
        return match ($unit) {
            'hour' => $value,
            'day' => $value * 24,
            'week' => $value * 168,
            'month' => $value * 720,
            default => $value,
        };
    }

    /**
     * Compute end datetime given localized start time.
     */
    public function computeEndAt(Carbon $startAt, string $unit, int $value): Carbon
    {
        $end = clone $startAt;

        return match ($unit) {
            'hour' => $end->addHours($value),
            'day' => $end->addDays($value),
            'week' => $end->addWeeks($value),
            'month' => $end->addMonthsNoOverflow($value),
            default => $end->addHours($value),
        };
    }

    /**
     * Helper for friendly Indonesian duration labels.
     */
    public function unitLabel(string $unit, int $value): string
    {
        $label = match ($unit) {
            'hour' => 'Jam',
            'day' => 'Hari',
            'week' => 'Minggu',
            'month' => 'Bulan',
            default => $unit,
        };

        return "{$value} {$label}";
    }

    /**
     * Check asset time-based availability over interval [startAt, endAt].
     */
    public function isAssetAvailable(Asset $asset, Carbon $startAt, Carbon $endAt, ?int $excludeBookingId = null): bool
    {
        if (!in_array($asset->status, ['ready', 'reserved', 'rented'])) {
            return false;
        }

        // Assets with an active stock transfer (pending approval or in transit) are unavailable
        $hasActiveTransfer = \App\Models\InventoryTransfer::withoutGlobalScopes()
            ->where('asset_id', $asset->id)
            ->whereIn('status', ['pending', 'in_transit'])
            ->exists();

        if ($hasActiveTransfer) {
            return false;
        }

        $collisionQuery = Booking::withoutGlobalScopes()
            ->whereIn('status', ['reserved', 'rented'])
            ->whereHas('items', function ($iq) use ($asset) {
                $iq->where('asset_id', $asset->id);
            })
            ->where(function ($tq) use ($startAt, $endAt) {
                $tq->where('start_at', '<', $endAt)
                   ->where('end_at', '>', $startAt);
            });

        if ($excludeBookingId) {
            $collisionQuery->where('id', '!=', $excludeBookingId);
        }

        return !$collisionQuery->exists();
    }
}
