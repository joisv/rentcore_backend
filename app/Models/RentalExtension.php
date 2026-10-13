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
        'extended_by',
        'previous_end_at',
        'new_end_at',
        'additional_hours',
        'additional_fee',
    ];

    protected $casts = [
        'previous_end_at' => 'datetime',
        'new_end_at' => 'datetime',
        'additional_hours' => 'integer',
        'additional_fee' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function extender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extended_by');
    }
}
