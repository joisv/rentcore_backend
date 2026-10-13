<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_code',
        'asset_id',
        'from_affiliate_id',
        'to_affiliate_id',
        'sent_by',
        'received_by',
        'status',
        'notes',
        'sent_at',
        'received_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withoutGlobalScopes();
    }

    public function fromAffiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'from_affiliate_id');
    }

    public function toAffiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'to_affiliate_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
