<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'affiliate_id',
        'name',
        'email',
        'google_id',
        'password',
        'phone',
        'avatar_url',
        'role',
        'manager_override_pin',
        'is_active',
        'is_platform_admin',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'manager_override_pin',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'is_platform_admin' => 'boolean',
        'password' => 'hashed',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function businessMembers(): HasMany
    {
        return $this->hasMany(BusinessMember::class);
    }

    public function businesses()
    {
        return $this->belongsToMany(Business::class, 'business_members')
            ->withPivot(['role', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    public function ownedBusinesses(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_id');
    }

    public function bookingsCreated(): HasMany
    {
        return $this->hasMany(Booking::class, 'created_by');
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin' || $this->is_platform_admin;
    }

    public function isAffiliateAdmin(): bool
    {
        return $this->role === 'affiliate_admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function getBusinessMembership(?int $businessId): ?BusinessMember
    {
        if (!$businessId) {
            return null;
        }

        return $this->businessMembers()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->first();
    }

    public function isBusinessOwner(?int $businessId): bool
    {
        $membership = $this->getBusinessMembership($businessId);
        return $membership ? $membership->isOwner() : false;
    }

    public function getBranchIdsForBusiness(?int $businessId): array
    {
        if (!$businessId) {
            return [];
        }

        $membership = $this->getBusinessMembership($businessId);
        if (!$membership) {
            return [];
        }

        if ($membership->isOwner()) {
            return Affiliate::where('business_id', $businessId)->pluck('id')->toArray();
        }

        $branchIds = BranchMember::where('business_member_id', $membership->id)->pluck('affiliate_id')->toArray();
        if ($this->affiliate_id && !in_array((int)$this->affiliate_id, $branchIds)) {
            $branchIds[] = (int) $this->affiliate_id;
        }

        return $branchIds;
    }
}
