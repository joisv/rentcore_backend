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
        'customer_id',
        'created_by',
        'start_at',
        'end_at',
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
        'payment_status',
        'collateral_type',
        'collateral_secured',
        'collateral_storage_slot',
        'notes',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'duration_hours' => 'integer',
        'duration_value' => 'integer',
        'subtotal_amount' => 'decimal:2',
        'primary_subtotal' => 'decimal:2',
        'addon_subtotal' => 'decimal:2',
        'deposit_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_bill' => 'decimal:2',
        'collateral_secured' => 'boolean',
    ];

    protected $appends = [
        'active_item',
        'active_asset',
        'primary_item',
        'primary_asset',
        'addon_items',
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
        return $this->active_item?->asset;
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
