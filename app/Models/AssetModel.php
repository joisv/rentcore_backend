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
        'late_fee_per_hour',
    ];

    public function getLateFeePerHourAttribute(): ?float
    {
        return $this->hourly_late_fee !== null ? round((float) $this->hourly_late_fee, 2) : null;
    }

    public function setLateFeePerHourAttribute($value): void
    {
        $this->attributes['hourly_late_fee'] = $value !== null && $value !== '' ? (float) $value : null;
    }

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image_path)) {
            return null;
        }

        $rawPath = str_replace('\\', '/', trim((string) $this->image_path));
        if ($rawPath === '' || str_starts_with($rawPath, 'data:image')) {
            return null;
        }

        if (str_starts_with($rawPath, 'http://') || str_starts_with($rawPath, 'https://')) {
            $parsed = parse_url($rawPath);
            $host = strtolower((string) ($parsed['host'] ?? ''));
            $path = (string) ($parsed['path'] ?? '');

            $isStaleOrLocalHost = in_array($host, [
                'localhost',
                '127.0.0.1',
                '10.0.2.2',
                'rentcore_backend.test',
                'sewakasir_backend.test',
            ], true)
                || str_contains($host, 'rentcore_backend')
                || str_ends_with($host, '.test')
                || str_ends_with($host, '.local');

            if ($isStaleOrLocalHost && $path !== '' && str_contains($path, '/storage/')) {
                $storagePos = strpos($path, '/storage/');
                $relative = ltrim(substr($path, $storagePos + strlen('/storage/')), '/');
                return $relative !== '' ? '/storage/' . $relative : null;
            }

            return $rawPath;
        }

        $cleanPath = ltrim($rawPath, '/');
        if (str_starts_with($cleanPath, 'public/')) {
            $cleanPath = substr($cleanPath, strlen('public/'));
        }
        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, strlen('storage/'));
        }

        return $cleanPath !== '' ? '/storage/' . ltrim($cleanPath, '/') : null;
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
        return (bool) $this->is_addon;
    }

    public function scopeAddons($query)
    {
        return $query->where('asset_models.is_addon', true);
    }

    public function scopeDevices($query)
    {
        return $query->where('asset_models.is_addon', false);
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
