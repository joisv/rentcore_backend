<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'category_id',
        'brand',
        'name',
        'slug',
        'image_path',
        'description',
        'storage_capacity',
        'color',
        'standard_accessories',
        'has_serial_number',
        'requires_frp_check',
        'specifications',
        'is_addon',
        'is_active',
        'hourly_late_fee',
    ];

    protected $appends = [
        'image_url',
    ];

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }
        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return $this->image_path;
        }
        return url('/storage/' . ltrim($this->image_path, '/'));
    }

    protected $casts = [
        'standard_accessories' => 'array',
        'specifications' => 'array',
        'has_serial_number' => 'boolean',
        'requires_frp_check' => 'boolean',
        'is_addon' => 'boolean',
        'is_active' => 'boolean',
        'hourly_late_fee' => 'decimal:2',
    ];

    public function isAddon(): bool
    {
        return (bool)($this->is_addon || ($this->category && $this->category->is_addon));
    }

    public function scopeAddons($query)
    {
        return $query->where(function ($q) {
            $q->where('asset_models.is_addon', true)
              ->orWhereHas('category', function ($cq) {
                  $cq->where('is_addon', true);
              });
        });
    }

    public function scopeDevices($query)
    {
        return $query->where('asset_models.is_addon', false)
            ->where(function ($q) {
                $q->whereDoesntHave('category')
                  ->orWhereHas('category', function ($cq) {
                      $cq->where('is_addon', false);
                  });
            });
    }

    public function scopeOwnedByBusiness($query, int $businessId)
    {
        return $query->where(function ($q) use ($businessId) {
            $q->where('asset_models.business_id', $businessId)
              ->orWhere(function ($cq) use ($businessId) {
                  $cq->whereNull('asset_models.business_id')
                     ->whereHas('category', fn ($catQ) => $catQ->where('asset_categories.business_id', $businessId));
              });
        });
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\TenantScope);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'model_id');
    }

    public function pricingTiers(): HasMany
    {
        return $this->hasMany(PricingTier::class, 'model_id');
    }
}
