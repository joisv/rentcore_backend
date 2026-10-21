<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'asset_id',
        'item_type',
        'unit_price',
        'duration_unit',
        'duration_value',
        'rate_snapshot',
        'status',
        'exchanged_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'duration_value' => 'integer',
        'rate_snapshot' => 'array',
        'exchanged_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePrimary($query)
    {
        return $query->where(function ($q) {
            $q->where('item_type', 'primary')->orWhereNull('item_type');
        });
    }

    public function scopeAddons($query)
    {
        return $query->where('item_type', 'addon');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withoutGlobalScope(\App\Scopes\TenantScope::class);
    }
}
