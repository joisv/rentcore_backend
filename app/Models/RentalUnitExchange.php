<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalUnitExchange extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'branch_id',
        'booking_id',
        'old_asset_id',
        'new_asset_id',
        'reported_issue_category',
        'customer_complaint',
        'staff_diagnosis',
        'old_unit_condition_grade',
        'icloud_signout_verified',
        'old_asset_disposition',
        'old_unit_rate',
        'new_unit_rate',
        'price_differential',
        'fee_waived',
        'waiver_reason',
        'payment_transaction_id',
        'handled_by',
        'manager_approved_by',
        'exchanged_at',
    ];

    protected $casts = [
        'old_unit_rate' => 'decimal:2',
        'new_unit_rate' => 'decimal:2',
        'price_differential' => 'decimal:2',
        'fee_waived' => 'boolean',
        'icloud_signout_verified' => 'boolean',
        'exchanged_at' => 'datetime',
    ];

    protected $appends = [
        'exchange_reference',
        'time_elapsed_hours',
    ];

    public function getExchangeReferenceAttribute(): string
    {
        return sprintf('EXC-%s-%04d', $this->created_at?->format('ymd') ?? date('ymd'), $this->id);
    }

    public function getTimeElapsedHoursAttribute(): ?float
    {
        if (!$this->booking?->start_at || !$this->exchanged_at) {
            return null;
        }

        $start = \Carbon\Carbon::parse($this->booking->start_at);
        $end = \Carbon\Carbon::parse($this->exchanged_at);

        return round($start->diffInMinutes($end) / 60, 1);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'branch_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function oldAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'old_asset_id')->withoutGlobalScope(TenantScope::class);
    }

    public function newAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'new_asset_id')->withoutGlobalScope(TenantScope::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }
}
