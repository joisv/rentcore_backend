<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Affiliate extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'slug',
        'phone',
        'address',
        'city',
        'is_central',
        'royalty_percentage',
        'cashier_discount_limit_percent',
        'cashier_discount_limit_nominal',
        'is_active',
    ];

    protected $casts = [
        'is_central' => 'boolean',
        'is_active' => 'boolean',
        'royalty_percentage' => 'decimal:2',
        'cashier_discount_limit_percent' => 'decimal:2',
        'cashier_discount_limit_nominal' => 'decimal:2',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
