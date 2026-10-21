<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'city',
        'currency',
        'timezone',
        'logo_path',
        'owner_id',
        'is_active',
        'id_document_limit_enabled',
        'max_primary_devices_per_id',
        'addons_enabled',
        'max_addons_per_rental',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'id_document_limit_enabled' => 'boolean',
        'addons_enabled' => 'boolean',
        'max_primary_devices_per_id' => 'integer',
        'max_addons_per_rental' => 'integer',
    ];

    public function getRentalRules(): array
    {
        return [
            'id_document_limit_enabled' => $this->id_document_limit_enabled !== null ? (bool)$this->id_document_limit_enabled : true,
            'max_primary_devices_per_id' => (int)($this->max_primary_devices_per_id ?? 1),
            'addons_enabled' => $this->addons_enabled !== null ? (bool)$this->addons_enabled : true,
            'max_addons_per_rental' => $this->max_addons_per_rental !== null ? (int)$this->max_addons_per_rental : 5,
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->slug)) {
                $baseSlug = Str::slug($model->name);
                $slug = $baseSlug;
                $counter = 1;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                $model->slug = $slug;
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BusinessMember::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Affiliate::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(AssetModel::class);
    }
}
