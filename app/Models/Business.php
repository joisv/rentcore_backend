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
        'slogan',
        'slug',
        'email',
        'phone',
        'address',
        'city',
        'business_categories',
        'currency',
        'timezone',
        'logo_path',
        'owner_id',
        'is_active',
        'id_document_limit_enabled',
        'max_primary_devices_per_id',
        'addons_enabled',
        'max_addons_per_rental',
        'expense_pin_threshold',
        'require_expense_pin',
        'shift_enforcement_enabled',
        'shift_opening_time',
        'shift_closing_time',
        'shift_grace_period_minutes',
        'payment_methods_config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'business_categories' => 'array',
        'id_document_limit_enabled' => 'boolean',
        'addons_enabled' => 'boolean',
        'require_expense_pin' => 'boolean',
        'max_primary_devices_per_id' => 'integer',
        'max_addons_per_rental' => 'integer',
        'expense_pin_threshold' => 'decimal:2',
        'shift_enforcement_enabled' => 'boolean',
        'shift_grace_period_minutes' => 'integer',
        'payment_methods_config' => 'array',
    ];

    public function getPaymentMethodsConfig(?int $affiliateId = null): array
    {
        $default = [
            'ewallet_enabled' => true,
            'ewallet_providers' => [
                'shopeepay' => true,
                'dana' => true,
                'gopay' => true,
                'linkaja' => true,
                'ovo' => true,
            ],
            'qris_statis_enabled' => true,
            'qris_image_path' => null,
            'cash_enabled' => true,
            'bank_transfer_enabled' => true,
        ];

        $config = array_replace_recursive($default, $this->payment_methods_config ?? []);

        if ($affiliateId) {
            $branch = $this->branches()->find($affiliateId);
            if ($branch && !empty($branch->payment_methods_config_override)) {
                $config = array_replace_recursive($config, $branch->payment_methods_config_override);
            }
        }

        return $config;
    }

    public function isPaymentMethodEnabled(PaymentMethod|int|string $method, ?int $affiliateId = null): bool
    {
        if (is_numeric($method)) {
            $methodModel = PaymentMethod::find($method);
        } elseif (is_string($method)) {
            $methodModel = PaymentMethod::where('code', strtoupper($method))->first();
        } else {
            $methodModel = $method;
        }

        if (!$methodModel || !$methodModel->is_active) {
            return false;
        }

        $code = strtoupper($methodModel->code);
        $config = $this->getPaymentMethodsConfig($affiliateId);

        if ($code === 'CASH') {
            return (bool)($config['cash_enabled'] ?? true);
        }

        if ($code === 'QRIS') {
            return (bool)($config['qris_statis_enabled'] ?? true);
        }

        if (in_array($code, ['BCA', 'MANDIRI', 'BRI', 'BNI', 'TRANSFER', 'BANK_TRANSFER'], true)) {
            return (bool)($config['bank_transfer_enabled'] ?? true);
        }

        // E-Wallet providers
        $eWalletMap = [
            'SHOPEEPAY' => 'shopeepay',
            'DANA' => 'dana',
            'GOPAY' => 'gopay',
            'LINKAJA' => 'linkaja',
            'OVO' => 'ovo',
        ];

        if (isset($eWalletMap[$code])) {
            $providerKey = $eWalletMap[$code];
            $masterEnabled = (bool)($config['ewallet_enabled'] ?? false);
            $providerEnabled = (bool)($config['ewallet_providers'][$providerKey] ?? false);
            return $masterEnabled && $providerEnabled;
        }

        return true;
    }

    public function getShiftSchedule(?int $affiliateId = null): array
    {
        $openingTime = $this->shift_opening_time ?: '08:00';
        $closingTime = $this->shift_closing_time ?: '22:00';

        if ($affiliateId) {
            $branch = $this->branches()->find($affiliateId);
            if ($branch && !empty($branch->shift_opening_time_override)) {
                $openingTime = $branch->shift_opening_time_override;
            }
            if ($branch && !empty($branch->shift_closing_time_override)) {
                $closingTime = $branch->shift_closing_time_override;
            }
        }

        return [
            'shift_enforcement_enabled' => $this->shift_enforcement_enabled !== null ? (bool)$this->shift_enforcement_enabled : true,
            'shift_opening_time' => $openingTime,
            'shift_closing_time' => $closingTime,
            'shift_grace_period_minutes' => (int)($this->shift_grace_period_minutes ?? 30),
            'timezone' => $this->timezone ?: 'Asia/Jakarta',
            'is_overnight' => $closingTime < $openingTime,
        ];
    }

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

    public function resignationRequests(): HasMany
    {
        return $this->hasMany(StaffResignationRequest::class);
    }
}
