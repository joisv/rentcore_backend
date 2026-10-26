<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantNotification extends Model
{
    use HasFactory;

    protected $table = 'tenant_notifications';

    protected $fillable = [
        'business_id',
        'affiliate_id',
        'user_id',
        'category',
        'type',
        'title',
        'body',
        'action_route',
        'action_payload',
        'dedup_key',
        'read_at',
    ];

    protected $casts = [
        'action_payload' => 'array',
        'read_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function markAsRead(): bool
    {
        if ($this->read_at === null) {
            $this->read_at = Carbon::now();
            return $this->save();
        }
        return true;
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
