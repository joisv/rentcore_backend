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
        'category_id',
        'brand',
        'name',
        'slug',
        'description',
        'storage_capacity',
        'color',
        'standard_accessories',
        'has_serial_number',
        'requires_frp_check',
        'specifications',
        'is_active',
    ];

    protected $casts = [
        'standard_accessories' => 'array',
        'specifications' => 'array',
        'has_serial_number' => 'boolean',
        'requires_frp_check' => 'boolean',
        'is_active' => 'boolean',
    ];

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
