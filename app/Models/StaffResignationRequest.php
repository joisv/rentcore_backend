<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffResignationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'business_member_id',
        'user_id',
        'status',
        'reason',
        'decision_notes',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function businessMember(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Fetch the latest resignation request per business for a given user in one query.
     *
     * @param  array<int>  $businessIds
     * @return array<int, self>
     */
    public static function latestByBusinessForUser(string $userId, array $businessIds): array
    {
        if (empty($businessIds)) {
            return [];
        }

        return static::where('user_id', $userId)
            ->whereIn('business_id', $businessIds)
            ->orderByDesc('id')
            ->get()
            ->unique('business_id')
            ->keyBy('business_id')
            ->all();
    }
}
