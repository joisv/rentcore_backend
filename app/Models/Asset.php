<?php

namespace App\Models;

use App\Scopes\FranchiseTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'affiliate_id',
        'model_id',
        'asset_code',
        'serial_number',
        'imei_1',
        'imei_2',
        'status',
        'condition_grade',
        'battery_health',
        'notes',
    ];

    protected $casts = [
        'battery_health' => 'integer',
    ];

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

    public function model(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'model_id')->withoutGlobalScope(\App\Scopes\TenantScope::class);
    }

    public function bookingItems(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function exchangesAsOld(): HasMany
    {
        return $this->hasMany(RentalUnitExchange::class, 'old_asset_id');
    }

    public function exchangesAsNew(): HasMany
    {
        return $this->hasMany(RentalUnitExchange::class, 'new_asset_id');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }

    public function scopeRented(Builder $query): Builder
    {
        return $query->where('status', 'rented');
    }
}
