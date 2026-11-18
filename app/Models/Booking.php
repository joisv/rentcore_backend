<?php

namespace App\Models;

use App\Scopes\FranchiseTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'booking_code',
        'affiliate_id',
        'asset_model_id',
        'asset_unit_id',
        'customer_id',
        'created_by',
        'start_at',
        'end_at',
        'grace_expires_at',
        'no_show_at',
        'picked_up_at',
        'forfeited_dp_amount',
        'is_overdue_pickup',
        'duration_hours',
        'duration_unit',
        'duration_value',
        'status',
        'subtotal_amount',
        'primary_subtotal',
        'addon_subtotal',
        'deposit_fee',
        'discount_type',
        'discount_amount',
        'voucher_id',
        'manual_discount_reason',
        'discount_approved_by',
        'total_bill',
        'remaining_balance',
        'payment_status',
        'collateral_type',
        'collateral_secured',
        'collateral_storage_slot',
        'notes',
    ];

    protected $casts = [
        'asset_model_id' => 'integer',
        'asset_unit_id' => 'integer',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'grace_expires_at' => 'datetime',
        'no_show_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'forfeited_dp_amount' => 'decimal:2',
        'is_overdue_pickup' => 'boolean',
        'duration_hours' => 'integer',
        'duration_value' => 'integer',
        'subtotal_amount' => 'decimal:2',
        'primary_subtotal' => 'decimal:2',
        'addon_subtotal' => 'decimal:2',
        'deposit_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_bill' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'collateral_secured' => 'boolean',
    ];

    protected $appends = [
        'active_item',
        'active_asset',
        'primary_item',
        'primary_asset',
        'addon_items',
        'addons',
        'device_model_id',
        'is_floating_unit',
        'paid_amount',
    ];

    public function getActiveItemAttribute(): ?BookingItem
    {
        if ($this->relationLoaded('items')) {
            // Priority 1: Active primary item
            $primaryActive = $this->items->first(fn ($item) => 
                ($item->item_type === 'primary' || $item->item_type === null) && 
                ($item->status === 'active' || $item->status === null)
            );
            if ($primaryActive) return $primaryActive;

            // Priority 2: Any primary item
            $primary = $this->items->first(fn ($item) => 
                $item->item_type === 'primary' || $item->item_type === null
            );
            if ($primary) return $primary;

            // Priority 3: Fallback to first active item or first item
            return $this->items->first(fn ($item) => $item->status === 'active' || $item->status === null)
                ?? $this->items->first();
        }

        return $this->activePrimaryItems()->with('asset.model.category')->first()
            ?? $this->primaryItems()->with('asset.model.category')->first()
            ?? $this->activeItems()->with('asset.model.category')->first()
            ?? $this->items()->with('asset.model.category')->first();
    }

    public function getActiveAssetAttribute(): ?Asset
    {
        if ($this->active_item?->asset) {
            return $this->active_item->asset;
        }
        if ($this->asset_unit_id && $this->relationLoaded('assetUnit')) {
            return $this->assetUnit;
        }
        return null;
    }

    public function getDeviceModelIdAttribute(): ?int
    {
        if ($this->asset_model_id !== null) {
            return (int) $this->asset_model_id;
        }
        return $this->active_asset?->model_id ? (int) $this->active_asset->model_id : null;
    }

    public function getIsFloatingUnitAttribute(): bool
    {
        return in_array($this->status, ['booked', 'reserved', 'confirmed'], true)
            && $this->asset_unit_id === null;
    }

    public function getPaidAmountAttribute(): float
    {
        $payments = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get();

        $inflow = (float) $payments
            ->filter(fn ($p) => in_array($p->type, ['dp', 'settlement', 'extension', 'exchange'], true)
                && ($p->status === null || $p->status !== 'void'))
            ->sum('amount');

        $outflow = (float) $payments
            ->filter(fn ($p) => $p->type === 'refund'
                && ($p->status === null || $p->status !== 'void'))
            ->sum('amount');

        return max(0.0, $inflow - $outflow);
    }

    public function getPrimaryItemAttribute(): ?BookingItem
    {
        return $this->active_item;
    }

    public function getPrimaryAssetAttribute(): ?Asset
    {
        return $this->active_asset;
    }

    public function getAddonItemsAttribute(): array
    {
        if ($this->relationLoaded('items')) {
            return $this->items->filter(fn ($item) => $item->item_type === 'addon')
                ->values()
                ->all();
        }

        return $this->addonItems()->with('asset.model.category')->get()->all();
    }

    public function getAddonsAttribute(): array
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->addonItems()->with(['asset.model', 'assetModel'])->get();

        $grouped = [];
        foreach ($items as $item) {
            $isAddon = $item->item_type === 'addon'
                || (bool) ($item->asset?->model?->is_addon ?? $item->assetModel?->is_addon ?? false);
            if (!$isAddon) {
                continue;
            }
            $model = $item->asset?->model ?? $item->assetModel;
            $name = $model?->name ?? $item->asset?->asset_code ?? 'Add-on';
            $key = $model?->id ? 'model_' . $model->id : 'name_' . strtolower($name);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'id' => $item->id,
                    'model_id' => $model?->id ?? $item->asset_model_id,
                    'name' => $name,
                    'quantity' => 0,
                    'item_ids' => [],
                    'is_returned' => (bool) ($item->is_returned ?? false),
                    'returned_at' => $item->returned_at?->toIso8601String(),
                ];
            }
            $grouped[$key]['quantity']++;
            $grouped[$key]['item_ids'][] = $item->id;
            if (!($item->is_returned ?? false)) {
                $grouped[$key]['is_returned'] = false;
            }
        }

        return array_values($grouped);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\TenantScope);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function assetModel(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'asset_model_id')->withoutGlobalScope(\App\Scopes\TenantScope::class);
    }

    public function assetUnit(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_unit_id')->withoutGlobalScope(\App\Scopes\TenantScope::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function discountApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class)
            ->orderByRaw("CASE WHEN item_type = 'primary' OR item_type IS NULL THEN 0 ELSE 1 END, CASE WHEN status = 'active' OR status IS NULL THEN 0 ELSE 1 END, id ASC");
    }

    public function primaryItems(): HasMany
    {
        return $this->hasMany(BookingItem::class)
            ->where(function ($q) {
                $q->where('item_type', 'primary')->orWhereNull('item_type');
            })
            ->orderByRaw("CASE WHEN status = 'active' OR status IS NULL THEN 0 ELSE 1 END, id ASC");
    }

    public function activePrimaryItems(): HasMany
    {
        return $this->primaryItems()->where('status', 'active');
    }

    public function addonItems(): HasMany
    {
        return $this->hasMany(BookingItem::class)
            ->where('item_type', 'addon')
            ->orderByRaw("CASE WHEN status = 'active' OR status IS NULL THEN 0 ELSE 1 END, id ASC");
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(RentalExtension::class);
    }

    public function returnInspection(): HasOne
    {
        return $this->hasOne(ReturnInspection::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function exchanges(): HasMany
    {
        return $this->hasMany(RentalUnitExchange::class)->orderBy('exchanged_at', 'desc');
    }

    public function activeItems(): HasMany
    {
        return $this->hasMany(BookingItem::class)->where('status', 'active');
    }
}
