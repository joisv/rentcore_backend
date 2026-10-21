<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'description',
        'is_addon',
        'is_active',
    ];

    protected $casts = [
        'is_addon' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeAddons($query)
    {
        return $query->where('is_addon', true);
    }

    public function scopeDevices($query)
    {
        return $query->where('is_addon', false);
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant_or_global', function (\Illuminate\Database\Eloquent\Builder $builder) {
            $context = app(\App\Tenancy\TenantContext::class);
            if ($context->hasActiveBusiness()) {
                $builder->where(function ($q) use ($context) {
                    $q->where('asset_categories.business_id', $context->getBusinessId())
                      ->orWhereNull('asset_categories.business_id');
                });
            } else {
                $user = \Illuminate\Support\Facades\Auth::user();
                if (!$user || !$user->isSuperAdmin()) {
                    $builder->whereNull('asset_categories.business_id');
                }
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function models(): HasMany
    {
        return $this->hasMany(AssetModel::class, 'category_id');
    }
}
