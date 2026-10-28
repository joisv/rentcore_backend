<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'branch_id',
        'shift_id',
        'transaction_code',
        'receipt_number',
        'external_reference',
        'idempotency_key',
        'booking_id',
        'payment_method_id',
        'processed_by',
        'reversal_of_id',
        'type',
        'status',
        'direction',
        'amount',
        'cash_received',
        'cash_change',
        'notes',
        'void_reason',
        'void_by',
        'transacted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cash_received' => 'decimal:2',
        'cash_change' => 'decimal:2',
        'transacted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Scopes\TenantScope);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'branch_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashDrawerShift::class, 'shift_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'reversal_of_id');
    }

    public function voidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_by');
    }

    public function isRefund(): bool
    {
        return $this->type === 'refund' || $this->direction === 'outflow';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
