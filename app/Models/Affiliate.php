<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Affiliate extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'slug',
        'phone',
        'address',
        'city',
        'is_central',
        'royalty_percentage',
        'cashier_discount_limit_percent',
        'cashier_discount_limit_nominal',
        'expense_pin_threshold_override',
        'shift_opening_time_override',
        'shift_closing_time_override',
        'payment_methods_config_override',
        'qris_raw_payload',
        'is_dynamic_qris_enabled',
        'booking_grace_period_minutes',
        'booking_auto_noshow',
        'allow_floating_unit_booking',
        'is_active',
    ];

    protected $casts = [
        'is_central' => 'boolean',
        'is_active' => 'boolean',
        'royalty_percentage' => 'decimal:2',
        'cashier_discount_limit_percent' => 'decimal:2',
        'cashier_discount_limit_nominal' => 'decimal:2',
        'expense_pin_threshold_override' => 'decimal:2',
        'payment_methods_config_override' => 'array',
        'is_dynamic_qris_enabled' => 'boolean',
        'booking_grace_period_minutes' => 'integer',
        'booking_auto_noshow' => 'boolean',
        'allow_floating_unit_booking' => 'boolean',
    ];

    public function getBookingRules(): array
    {
        return [
            'branch_id' => $this->id,
            'booking_grace_period_minutes' => (int) ($this->booking_grace_period_minutes ?? 60),
            'booking_auto_noshow' => $this->booking_auto_noshow !== null ? (bool) $this->booking_auto_noshow : true,
            'allow_floating_unit_booking' => $this->allow_floating_unit_booking !== null ? (bool) $this->allow_floating_unit_booking : true,
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\TenantScope);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
