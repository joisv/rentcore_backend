<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDrawerShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'branch_id',
        'shift_number',
        'opened_by',
        'closed_by',
        'opened_at',
        'closed_at',
        'opening_cash_float',
        'expected_cash_total',
        'actual_cash_counted',
        'cash_difference',
        'difference_reason',
        'status',
        'closing_notes',
    ];

    protected $casts = [
        'opening_cash_float' => 'decimal:2',
        'expected_cash_total' => 'decimal:2',
        'actual_cash_counted' => 'decimal:2',
        'cash_difference' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'branch_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
