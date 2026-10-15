<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_member_id',
        'affiliate_id',
    ];

    public function businessMember(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id');
    }
}
