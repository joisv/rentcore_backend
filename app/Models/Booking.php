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
        'deposit_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_bill' => 'decimal:2',
        'collateral_secured' => 'boolean',
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
        return $this->hasMany(BookingItem::class);
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
}
