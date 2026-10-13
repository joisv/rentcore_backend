<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnInspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'inspected_by',
        'returned_at',
        'condition_grade',
        'battery_health_final',
        'checklist_results',
        'accessories_returned',
        'late_minutes',
        'late_fee',
        'damage_fee',
        'total_penalty',
        'deposit_refunded',
        'staff_notes',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
        'battery_health_final' => 'integer',
        'checklist_results' => 'array',
        'accessories_returned' => 'array',
        'late_minutes' => 'integer',
        'late_fee' => 'decimal:2',
        'damage_fee' => 'decimal:2',
        'total_penalty' => 'decimal:2',
        'deposit_refunded' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }
}
