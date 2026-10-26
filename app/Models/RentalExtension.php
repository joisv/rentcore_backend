<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalExtension extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'booking_item_id',
        'extended_by',
        'adjustment_type', // 'increase', 'decrease'
        'hours_delta', // signed integer (+6 or -6)
        'fee_delta', // signed decimal (+50000 or -50000)
        'reason',
        'manager_approved_by',
        'previous_end_at',
        'new_end_at',
        'additional_hours', // backward compatibility
        'additional_fee', // backward compatibility
    ];

    protected $casts = [
        'previous_end_at' => 'datetime',
        'new_end_at' => 'datetime',
        'additional_hours' => 'integer',
        'hours_delta' => 'integer',
        'additional_fee' => 'decimal:2',
        'fee_delta' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookingItem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class, 'booking_item_id');
    }

    public function extender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extended_by');
    }

    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    public function isReduction(): bool
    {
        return $this->adjustment_type === 'decrease' || ($this->hours_delta !== null && $this->hours_delta < 0);
    }
}
