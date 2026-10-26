<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'user_id',
        'role',
        'is_active',
        'invited_by',
        'joined_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'joined_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function branchMembers(): HasMany
    {
        return $this->hasMany(BranchMember::class);
    }

    public function resignationRequests(): HasMany
    {
        return $this->hasMany(StaffResignationRequest::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin']);
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    /**
     * Branches this member may operate in, central branch first.
     *
     * - Owner: every branch of the business.
     * - Non-owner with branch assignments: only the assigned branches
     *   (plus the legacy users.affiliate_id if it belongs to this business).
     * - Non-owner without any assignment: every branch (mirrors TenantContextMiddleware,
     *   which does not restrict such members).
     *
     * This mirrors the authorization enforced by TenantContextMiddleware so that the
     * client never selects a default branch the backend will reject.
     */
    public function accessibleBranches(): \Illuminate\Support\Collection
    {
        // Explicitly constrained to this membership's business, so the request-level
        // TenantScope (bound to a possibly different active business) is not needed.
        $query = Affiliate::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('business_id', $this->business_id)
            ->where('is_active', true)
            ->orderBy('is_central', 'desc')
            ->orderBy('id');

        if (!$this->isOwner()) {
            $assigned = $this->branchMembers()->pluck('affiliate_id')->map(fn($id) => (int) $id)->all();
            $legacyAffiliateId = $this->user?->affiliate_id;
            if (empty($assigned) && $legacyAffiliateId) {
                $assigned = [(int) $legacyAffiliateId];
            }
            if (!empty($assigned)) {
                $query->whereIn('id', $assigned);
            }
        }

        return $query->get();
    }

    /**
     * Serialized branch list for API payloads.
     */
    public function serializeAccessibleBranches(): array
    {
        return $this->accessibleBranches()->map(fn($b) => [
            'id' => $b->id,
            'code' => $b->code,
            'name' => $b->name,
            'city' => $b->city,
            'is_central' => (bool) $b->is_central,
        ])->values()->all();
    }
}
