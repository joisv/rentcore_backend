<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\InventoryTransfer;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BookingAvailabilityService
{
    public const ACTIVE_RESERVATION_STATUSES = [
        'pending',
        'booked',
        'reserved',
        'confirmed',
        'rented',
        'active',
    ];

    public const FLOATING_RESERVATION_STATUSES = [
        'pending',
        'booked',
        'reserved',
        'confirmed',
    ];

    public const RENTABLE_ASSET_STATUSES = [
        'ready',
        'reserved',
        'rented',
    ];

    /**
     * Compute grace_expires_at from scheduled start time and branch grace period setting.
     */
    public function computeGraceExpiresAt(Carbon $startAt, Affiliate $branch): Carbon
    {
        $graceMinutes = max(1, (int) ($branch->booking_grace_period_minutes ?? 60));
        return $startAt->copy()->addMinutes($graceMinutes);
    }

    /**
     * Resolve and verify that the given active branch belongs to the expected business.
     */
    protected function resolveBranchBusinessId(int $branchId, ?int $businessId = null): ?int
    {
        if ($branchId <= 0) {
            return null;
        }

        $branch = Affiliate::withoutGlobalScopes()
            ->where('id', $branchId)
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
            ->where('is_active', true)
            ->first(['id', 'business_id']);

        if (!$branch || !$branch->business_id) {
            return null;
        }

        return (int) $branch->business_id;
    }

    /**
     * Calculate model-level capacity for a branch over [startAt, endAt].
     * Counts total rentable physical units minus overlapping pinned and floating reservations.
     */
    public function getBranchModelCapacity(
        int $branchId,
        int $modelId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeBookingId = null,
        ?int $businessId = null
    ): array {
        $effectiveBusinessId = $this->resolveBranchBusinessId($branchId, $businessId);
        if (!$effectiveBusinessId) {
            return [
                'model_id' => $modelId,
                'branch_id' => $branchId,
                'total_units' => 0,
                'pinned_booked_units' => 0,
                'floating_booked_units' => 0,
                'booked_units' => 0,
                'available_slots' => 0,
                'available_asset_ids' => [],
            ];
        }

        $activeTransferAssetIds = InventoryTransfer::withoutGlobalScopes()
            ->where('business_id', $effectiveBusinessId)
            ->whereIn('status', ['pending', 'in_transit'])
            ->pluck('asset_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $rentableAssets = Asset::withoutGlobalScopes()
            ->where('business_id', $effectiveBusinessId)
            ->where('affiliate_id', $branchId)
            ->where('model_id', $modelId)
            ->whereIn('status', self::RENTABLE_ASSET_STATUSES)
            ->when(!empty($activeTransferAssetIds), fn ($q) => $q->whereNotIn('id', $activeTransferAssetIds))
            ->get(['id', 'model_id', 'status']);

        $totalUnits = $rentableAssets->count();
        $rentableAssetIds = $rentableAssets->pluck('id')->map(fn ($id) => (int) $id)->all();

        $overlappingBookings = $this->queryOverlappingBookings($branchId, $startAt, $endAt, $excludeBookingId, $effectiveBusinessId);

        $pinnedAssetIds = [];
        $floatingCount = 0;

        foreach ($overlappingBookings as $booking) {
            $bookingPinnedForModel = $this->extractPinnedAssetIdsForModel($booking, $modelId, $rentableAssetIds);

            if (!empty($bookingPinnedForModel)) {
                foreach ($bookingPinnedForModel as $aid) {
                    $pinnedAssetIds[$aid] = true;
                }
                continue;
            }

            if ($this->isFloatingReservationForModel($booking, $modelId)) {
                $floatingCount++;
            }
        }

        $pinnedCount = count($pinnedAssetIds);
        $bookedUnits = $pinnedCount + $floatingCount;
        $availableSlots = max(0, $totalUnits - $bookedUnits);

        $availableAssetIds = array_values(array_filter(
            $rentableAssetIds,
            fn (int $aid) => !isset($pinnedAssetIds[$aid])
        ));

        return [
            'model_id' => $modelId,
            'branch_id' => $branchId,
            'total_units' => $totalUnits,
            'pinned_booked_units' => $pinnedCount,
            'floating_booked_units' => $floatingCount,
            'booked_units' => $bookedUnits,
            'available_slots' => $availableSlots,
            'available_asset_ids' => $availableAssetIds,
        ];
    }

    /**
     * Return active device models enriched with per-model available slot counts for [startAt, endAt].
     * Strictly scoped to the branch's owning business and excludes models with 0 physical units at that branch.
     */
    public function getAvailableModelsForBranch(
        int $branchId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $businessId = null,
        ?int $categoryId = null,
        ?string $search = null
    ): Collection {
        $effectiveBusinessId = $this->resolveBranchBusinessId($branchId, $businessId);
        if (!$effectiveBusinessId) {
            return collect();
        }

        $activeTransferAssetIds = InventoryTransfer::withoutGlobalScopes()
            ->where('business_id', $effectiveBusinessId)
            ->whereIn('status', ['pending', 'in_transit'])
            ->pluck('asset_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $branchAssets = Asset::withoutGlobalScopes()
            ->where('business_id', $effectiveBusinessId)
            ->where('affiliate_id', $branchId)
            ->whereIn('status', self::RENTABLE_ASSET_STATUSES)
            ->when(!empty($activeTransferAssetIds), fn ($q) => $q->whereNotIn('id', $activeTransferAssetIds))
            ->get(['id', 'model_id', 'status']);

        if ($branchAssets->isEmpty()) {
            return collect();
        }

        $branchModelIds = $branchAssets->pluck('model_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (empty($branchModelIds)) {
            return collect();
        }

        $query = AssetModel::withoutGlobalScopes()
            ->with(['category', 'pricingTiers' => function ($q) {
                $q->where('is_active', true)->orderBy('duration_hours', 'asc');
            }])
            ->ownedByBusiness($effectiveBusinessId)
            ->whereIn('id', $branchModelIds)
            ->devices()
            ->where('is_active', true);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('brand', 'like', "%{$term}%");
            });
        }

        $models = $query->orderBy('name', 'asc')->get();
        if ($models->isEmpty()) {
            return $models;
        }

        $assetsByModel = $branchAssets->groupBy('model_id');
        $overlappingBookings = $this->queryOverlappingBookings($branchId, $startAt, $endAt, null, $effectiveBusinessId);

        return $models->map(function (AssetModel $model) use ($assetsByModel, $overlappingBookings, $branchId) {
            $modelAssets = $assetsByModel->get($model->id, collect());
            $totalUnits = $modelAssets->count();
            $rentableAssetIds = $modelAssets->pluck('id')->map(fn ($id) => (int) $id)->all();

            $pinnedAssetIds = [];
            $floatingCount = 0;

            foreach ($overlappingBookings as $booking) {
                $bookingPinnedForModel = $this->extractPinnedAssetIdsForModel($booking, (int) $model->id, $rentableAssetIds);
                if (!empty($bookingPinnedForModel)) {
                    foreach ($bookingPinnedForModel as $aid) {
                        $pinnedAssetIds[$aid] = true;
                    }
                    continue;
                }

                if ($this->isFloatingReservationForModel($booking, (int) $model->id)) {
                    $floatingCount++;
                }
            }

            $bookedUnits = count($pinnedAssetIds) + $floatingCount;
            $availableSlots = max(0, $totalUnits - $bookedUnits);
            $availableAssetIds = array_values(array_filter(
                $rentableAssetIds,
                fn (int $aid) => !isset($pinnedAssetIds[$aid])
            ));

            $model->setAttribute('branch_id', $branchId);
            $model->setAttribute('total_units', $totalUnits);
            $model->setAttribute('booked_units', $bookedUnits);
            $model->setAttribute('available_slots', $availableSlots);
            $model->setAttribute('ready_units', $modelAssets->where('status', 'ready')->count());
            $model->setAttribute('available_asset_ids', $availableAssetIds);

            return $model;
        })->filter(fn (AssetModel $model) => (int) $model->getAttribute('total_units') > 0)->values();
    }

    /**
     * Get ready physical units in the booking's branch matching its model with zero schedule overlap.
     */
    public function getReadyPhysicalUnitsForBooking(
        Booking $booking,
        ?Carbon $startOverride = null,
        ?Carbon $endOverride = null
    ): Collection {
        $activeTransferAssetIds = InventoryTransfer::withoutGlobalScopes()
            ->when($booking->business_id, fn ($q) => $q->where('business_id', $booking->business_id))
            ->whereIn('status', ['pending', 'in_transit'])
            ->pluck('asset_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = Asset::withoutGlobalScopes()
            ->with(['model.category', 'affiliate'])
            ->when($booking->business_id, fn ($q) => $q->where('business_id', $booking->business_id))
            ->where('affiliate_id', $booking->affiliate_id)
            ->where('status', 'ready')
            ->when(!empty($activeTransferAssetIds), fn ($q) => $q->whereNotIn('id', $activeTransferAssetIds));

        $modelId = $booking->device_model_id;
        if ($modelId) {
            $query->where('model_id', $modelId);
        }

        $candidates = $query->orderBy('asset_code', 'asc')->get();
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $startAt = $startOverride ? $startOverride->copy() : Carbon::parse($booking->start_at);
        $endAt = $endOverride ? $endOverride->copy() : Carbon::parse($booking->end_at);
        $overlappingBookings = $this->queryOverlappingBookings(
            (int) $booking->affiliate_id,
            $startAt,
            $endAt,
            (int) $booking->id,
            $booking->business_id ? (int) $booking->business_id : null
        );

        $blockedAssetIds = [];
        foreach ($overlappingBookings as $other) {
            if ($other->asset_unit_id) {
                $blockedAssetIds[(int) $other->asset_unit_id] = true;
            }
            foreach ($other->items as $item) {
                if ($item->asset_id) {
                    $blockedAssetIds[(int) $item->asset_id] = true;
                }
            }
        }

        return $candidates->filter(fn (Asset $asset) => !isset($blockedAssetIds[(int) $asset->id]))->values();
    }

    /**
     * Check whether a specific physical asset has zero active transfers and zero
     * pinned overlapping bookings/rentals over [startAt, endAt).
     */
    public function isAssetAvailableForWindow(
        Asset $asset,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeBookingId = null
    ): bool {
        if (!in_array($asset->status, self::RENTABLE_ASSET_STATUSES, true)) {
            return false;
        }

        $hasActiveTransfer = InventoryTransfer::withoutGlobalScopes()
            ->when($asset->business_id, fn ($q) => $q->where('business_id', $asset->business_id))
            ->where('asset_id', $asset->id)
            ->whereIn('status', ['pending', 'in_transit'])
            ->exists();

        if ($hasActiveTransfer) {
            return false;
        }

        return $this->findEarliestConflictForAsset($asset, $startAt, $endAt, $excludeBookingId) === null;
    }

    /**
     * Evaluate full window availability for a physical unit, accounting for
     * active transfers, pinned unit bookings, and model-level floating bookings.
     */
    public function evaluateAssetWindowAvailability(
        Asset $asset,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeBookingId = null
    ): array {
        $modelName = $asset->model?->name ?? 'Unit';

        if (!in_array($asset->status, self::RENTABLE_ASSET_STATUSES, true)) {
            return [
                'is_available' => false,
                'conflict_reason' => "Perangkat {$asset->asset_code} ({$modelName}) sedang tidak siap (status: {$asset->status}).",
                'conflict_start' => null,
                'conflict_end' => null,
            ];
        }

        $hasActiveTransfer = InventoryTransfer::withoutGlobalScopes()
            ->when($asset->business_id, fn ($q) => $q->where('business_id', $asset->business_id))
            ->where('asset_id', $asset->id)
            ->whereIn('status', ['pending', 'in_transit'])
            ->exists();

        if ($hasActiveTransfer) {
            return [
                'is_available' => false,
                'conflict_reason' => "Perangkat {$asset->asset_code} ({$modelName}) sedang dalam proses mutasi antar cabang.",
                'conflict_start' => null,
                'conflict_end' => null,
            ];
        }

        $pinnedConflict = $this->findEarliestConflictForAsset($asset, $startAt, $endAt, $excludeBookingId);
        if ($pinnedConflict) {
            [$cStart, $cEnd] = $this->formatConflictWindow($pinnedConflict, (int) $asset->affiliate_id);
            return [
                'is_available' => false,
                'conflict_reason' => $this->buildConflictMessage($modelName, $pinnedConflict, (int) $asset->affiliate_id),
                'conflict_start' => $cStart,
                'conflict_end' => $cEnd,
            ];
        }

        if ($asset->affiliate_id && $asset->model_id) {
            $capacity = $this->getBranchModelCapacity(
                (int) $asset->affiliate_id,
                (int) $asset->model_id,
                $startAt,
                $endAt,
                $excludeBookingId,
                $asset->business_id ? (int) $asset->business_id : null
            );

            if ($capacity['available_slots'] < 1) {
                $modelConflict = $this->findEarliestConflictForModel(
                    (int) $asset->affiliate_id,
                    (int) $asset->model_id,
                    $startAt,
                    $endAt,
                    $excludeBookingId,
                    $asset->business_id ? (int) $asset->business_id : null
                );
                [$cStart, $cEnd] = $modelConflict
                    ? $this->formatConflictWindow($modelConflict, (int) $asset->affiliate_id)
                    : [null, null];

                return [
                    'is_available' => false,
                    'conflict_reason' => $this->buildConflictMessage($modelName, $modelConflict, (int) $asset->affiliate_id),
                    'conflict_start' => $cStart,
                    'conflict_end' => $cEnd,
                ];
            }
        }

        return [
            'is_available' => true,
            'conflict_reason' => null,
            'conflict_start' => null,
            'conflict_end' => null,
        ];
    }

    /**
     * Assert that every requested asset (plus overall model capacity including
     * floating bookings) is available over [startAt, endAt). Throws HTTP 422 on conflict.
     */
    public function assertAssetsAvailableForWindow(
        int $branchId,
        int $businessId,
        iterable $assets,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeBookingId = null,
        string $errorField = 'asset_ids'
    ): void {
        $assetCollection = collect($assets);
        if ($assetCollection->isEmpty()) {
            return;
        }

        $requestedCountByModel = [];
        $modelNamesById = [];

        foreach ($assetCollection as $asset) {
            $modelName = $asset->model?->name ?? 'Unit';
            if ($asset->model_id) {
                $mId = (int) $asset->model_id;
                $requestedCountByModel[$mId] = ($requestedCountByModel[$mId] ?? 0) + 1;
                $modelNamesById[$mId] = $modelName;
            }

            $hasActiveTransfer = InventoryTransfer::withoutGlobalScopes()
                ->when($businessId > 0, fn ($q) => $q->where('business_id', $businessId))
                ->where('asset_id', $asset->id)
                ->whereIn('status', ['pending', 'in_transit'])
                ->exists();
            if ($hasActiveTransfer) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $errorField => ["Perangkat {$asset->asset_code} ({$modelName}) sedang dalam proses mutasi antar cabang."],
                ]);
            }

            $pinnedConflict = $this->findEarliestConflictForAsset($asset, $startAt, $endAt, $excludeBookingId);
            if ($pinnedConflict) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $errorField => [$this->buildConflictMessage($modelName, $pinnedConflict, $branchId)],
                ]);
            }
        }

        foreach ($requestedCountByModel as $modelId => $requestedQty) {
            $capacity = $this->getBranchModelCapacity(
                $branchId,
                $modelId,
                $startAt,
                $endAt,
                $excludeBookingId,
                $businessId > 0 ? $businessId : null
            );

            if ($capacity['total_units'] < $requestedQty || $capacity['available_slots'] < $requestedQty) {
                $conflict = $this->findEarliestConflictForModel(
                    $branchId,
                    $modelId,
                    $startAt,
                    $endAt,
                    $excludeBookingId,
                    $businessId > 0 ? $businessId : null
                );
                $modelName = $modelNamesById[$modelId] ?? 'Model';
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $errorField => [$this->buildConflictMessage($modelName, $conflict, $branchId)],
                ]);
            }
        }
    }

    public function findEarliestConflictForAsset(
        Asset $asset,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeBookingId = null
    ): ?Booking {
        $now = Carbon::now()->utc();

        $query = Booking::withoutGlobalScopes()
            ->when($asset->business_id, fn ($q) => $q->where('business_id', $asset->business_id))
            ->when($asset->affiliate_id, fn ($q) => $q->where('affiliate_id', $asset->affiliate_id))
            ->whereIn('status', self::ACTIVE_RESERVATION_STATUSES)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where(function ($bq) use ($asset) {
                $bq->where('asset_unit_id', $asset->id)
                    ->orWhereHas('items', function ($iq) use ($asset) {
                        $iq->where('asset_id', $asset->id)
                            ->where(function ($sq) {
                                $sq->whereNull('status')->orWhere('status', 'active');
                            });
                    });
            })
            ->where('start_at', '<', $endAt)
            ->where(function ($q) use ($startAt, $now) {
                $q->where('end_at', '>', $startAt);
                if ($now->gte($startAt)) {
                    $q->orWhere(function ($oq) {
                        $oq->whereIn('status', ['rented', 'active']);
                    });
                }
            })
            ->orderBy('start_at', 'asc');

        return $query->first();
    }

    public function findEarliestConflictForModel(
        int $branchId,
        int $modelId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeBookingId = null,
        ?int $businessId = null
    ): ?Booking {
        $effectiveBusinessId = $this->resolveBranchBusinessId($branchId, $businessId);
        if (!$effectiveBusinessId) {
            return null;
        }

        $rentableAssetIds = Asset::withoutGlobalScopes()
            ->where('business_id', $effectiveBusinessId)
            ->where('affiliate_id', $branchId)
            ->where('model_id', $modelId)
            ->whereIn('status', self::RENTABLE_ASSET_STATUSES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $overlapping = $this->queryOverlappingBookings(
            $branchId,
            $startAt,
            $endAt,
            $excludeBookingId,
            $effectiveBusinessId
        );

        foreach ($overlapping as $booking) {
            if (!empty($this->extractPinnedAssetIdsForModel($booking, $modelId, $rentableAssetIds))) {
                return $booking;
            }
            if ($this->isFloatingReservationForModel($booking, $modelId)) {
                return $booking;
            }
        }

        return null;
    }

    public function formatConflictWindow(Booking $booking, ?int $branchId = null): array
    {
        $tz = config('app.timezone', 'Asia/Jakarta');
        if ($tz === 'UTC') {
            $tz = 'Asia/Jakarta';
        }
        $startLocal = Carbon::parse($booking->start_at)->setTimezone($tz);
        $rawEnd = Carbon::parse($booking->end_at);
        if (in_array($booking->status, ['rented', 'active'], true) && $rawEnd->lt(Carbon::now())) {
            $rawEnd = Carbon::now();
        }
        $endLocal = $rawEnd->setTimezone($tz);

        return [
            $startLocal->format('d M H:i'),
            $endLocal->format('d M H:i'),
        ];
    }

    public function buildConflictMessage(string $modelName, ?Booking $conflict, ?int $branchId = null): string
    {
        if (!$conflict) {
            return "Stok {$modelName} tidak mencukupi untuk durasi tersebut karena seluruh unit sedang terikat jadwal sewa/booking.";
        }

        [$startStr, $endStr] = $this->formatConflictWindow($conflict, $branchId);
        return "Stok {$modelName} tidak mencukupi untuk durasi tersebut karena sudah terikat jadwal sewa/booking pada {$startStr} - {$endStr}.";
    }

    /**
     * Query active/booked bookings overlapping [startAt, endAt] for a branch.
     */
    protected function queryOverlappingBookings(
        int $branchId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeBookingId = null,
        ?int $businessId = null
    ): Collection {
        $now = Carbon::now()->utc();

        return Booking::withoutGlobalScopes()
            ->when($businessId !== null, fn ($q) => $q->where('business_id', $businessId))
            ->where('affiliate_id', $branchId)
            ->whereIn('status', self::ACTIVE_RESERVATION_STATUSES)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where('start_at', '<', $endAt)
            ->where(function ($q) use ($startAt, $now) {
                $q->where('end_at', '>', $startAt);
                if ($now->gte($startAt)) {
                    $q->orWhere(function ($oq) use ($now) {
                        $oq->whereIn('status', ['rented', 'active'])
                           ->where('end_at', '<=', $now);
                    });
                }
            })
            ->with([
                'items' => function ($iq) {
                    $iq->where(function ($sq) {
                        $sq->whereNull('status')->orWhere('status', 'active');
                    });
                },
                'items.asset:id,model_id',
            ])
            ->orderBy('start_at', 'asc')
            ->get();
    }

    protected function extractPinnedAssetIdsForModel(Booking $booking, int $modelId, array $rentableAssetIds): array
    {
        $ids = [];

        if ($booking->asset_unit_id && in_array((int) $booking->asset_unit_id, $rentableAssetIds, true)) {
            $ids[] = (int) $booking->asset_unit_id;
        }

        foreach ($booking->items as $item) {
            $isPrimary = $item->item_type === 'primary' || $item->item_type === null;
            if (!$isPrimary || !$item->asset_id) {
                continue;
            }
            $assetModelId = $item->asset?->model_id ?? $item->asset_model_id;
            if ((int) $assetModelId === $modelId) {
                $ids[] = (int) $item->asset_id;
            }
        }

        return array_values(array_unique($ids));
    }

    protected function isFloatingReservationForModel(Booking $booking, int $modelId): bool
    {
        if (!in_array($booking->status, self::FLOATING_RESERVATION_STATUSES, true)) {
            return false;
        }
        if ($booking->asset_unit_id !== null) {
            return false;
        }
        if ((int) $booking->asset_model_id === $modelId) {
            return true;
        }

        foreach ($booking->items as $item) {
            $isPrimary = $item->item_type === 'primary' || $item->item_type === null;
            if ($isPrimary && $item->asset_id === null && (int) $item->asset_model_id === $modelId) {
                return true;
            }
        }

        return false;
    }
}
