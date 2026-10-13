<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'affiliate_id',
        'code',
        'name',
        'description',
        'discount_percentage',
        'max_discount_cap',
        'min_transaction_amount',
        'barcode_type',
        'start_at',
        'end_at',
        'usage_limit_total',
        'usage_limit_per_customer',
        'usage_count',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'discount_percentage' => 'float',
        'max_discount_cap' => 'float',
        'min_transaction_amount' => 'float',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'usage_limit_total' => 'integer',
        'usage_limit_per_customer' => 'integer',
        'usage_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();
        return $query->where('is_active', true)
                     ->where('start_at', '<=', $now)
                     ->where('end_at', '>=', $now);
    }
}
