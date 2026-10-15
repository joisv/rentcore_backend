<?php

namespace App\Tenancy;

use App\Models\Affiliate;
use App\Models\Business;
use App\Models\BusinessMember;

class TenantContext
{
    protected ?Business $business = null;
    protected ?Affiliate $branch = null;
    protected ?BusinessMember $member = null;
    protected bool $isBranchRestricted = false;

    public function set(Business $business, BusinessMember $member, ?Affiliate $branch = null, bool $isBranchRestricted = false): self
    {
        $this->business = $business;
        $this->member = $member;
        $this->branch = $branch;
        $this->isBranchRestricted = $isBranchRestricted;
        return $this;
    }

    public function getBusiness(): ?Business
    {
        return $this->business;
    }

    public function getBusinessId(): ?int
    {
        return $this->business?->id;
    }

    public function getBranch(): ?Affiliate
    {
        return $this->branch;
    }

    public function getBranchId(): ?int
    {
        return $this->branch?->id;
    }

    public function getMember(): ?BusinessMember
    {
        return $this->member;
    }

    public function hasActiveBusiness(): bool
    {
        return $this->business !== null;
    }

    public function isBranchRestricted(): bool
    {
        return $this->isBranchRestricted && $this->branch !== null;
    }

    public function isOwner(): bool
    {
        return $this->member?->isOwner() ?? false;
    }

    public function isAdmin(): bool
    {
        return $this->member?->isAdmin() ?? false;
    }

    public function isStaff(): bool
    {
        return $this->member?->isStaff() ?? false;
    }
}
