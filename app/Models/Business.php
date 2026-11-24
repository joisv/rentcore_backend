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
        'branch_structure',
        'has_completed_onboarding',
        'currency',
        'timezone',
        'logo_path',
        'owner_id',
        'is_active',
        'id_document_limit_enabled',
        'max_primary_devices_per_id',
        'addons_enabled',
        'max_addons_per_rental',
        'enable_collateral',
        'require_collateral',
        'collateral_types',
        'expense_pin_threshold',
        'require_expense_pin',
        'shift_enforcement_enabled',
        'shift_opening_time',
        'shift_closing_time',
        'shift_grace_period_minutes',
        'booking_grace_period_minutes',
        'auto_expire_unpaid_booking',
        'auto_expire_paid_booking',
        'late_pickup_end_time_mode',
        'return_grace_period_minutes',
        'late_fee_rounding_mode',
        'cap_late_fee_at_daily_rate',
        'default_hourly_late_fee',
        'payment_methods_config',
        'qris_raw_payload',
        'is_dynamic_qris_enabled',
    ];

    public const COLLATERAL_INPUT_MODES = [
        'vault_slot',
        'cash_deposit',
        'note_only',
    ];

    public const DEFAULT_COLLATERAL_TYPES = [
        ['id' => 'cash_deposit', 'name' => 'Deposit Tunai', 'input_mode' => 'cash_deposit', 'is_active' => true],
        ['id' => 'ektp', 'name' => 'e-KTP', 'input_mode' => 'vault_slot', 'is_active' => true],
        ['id' => 'sim', 'name' => 'SIM (A/C)', 'input_mode' => 'vault_slot', 'is_active' => true],
        ['id' => 'stnk', 'name' => 'STNK / BPKB', 'input_mode' => 'vault_slot', 'is_active' => true],
        ['id' => 'student_id', 'name' => 'Kartu Pelajar / KTM', 'input_mode' => 'note_only', 'is_active' => true],
    ];

    protected $attributes = [
        'enable_collateral' => false,
        'require_collateral' => false,
        'booking_grace_period_minutes' => 60,
        'auto_expire_unpaid_booking' => true,
        'auto_expire_paid_booking' => false,
        'late_pickup_end_time_mode' => 'keep_original_end',
        'return_grace_period_minutes' => 15,
        'late_fee_rounding_mode' => 'hourly_ceil',
        'cap_late_fee_at_daily_rate' => true,
        'default_hourly_late_fee' => 0.00,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'business_categories' => 'array',
        'has_completed_onboarding' => 'boolean',
        'id_document_limit_enabled' => 'boolean',
        'addons_enabled' => 'boolean',
        'enable_collateral' => 'boolean',
        'require_collateral' => 'boolean',
        'collateral_types' => 'array',
        'require_expense_pin' => 'boolean',
        'max_primary_devices_per_id' => 'integer',
        'max_addons_per_rental' => 'integer',
        'expense_pin_threshold' => 'decimal:2',
        'shift_enforcement_enabled' => 'boolean',
        'shift_grace_period_minutes' => 'integer',
        'booking_grace_period_minutes' => 'integer',
        'auto_expire_unpaid_booking' => 'boolean',
        'auto_expire_paid_booking' => 'boolean',
        'return_grace_period_minutes' => 'integer',
        'cap_late_fee_at_daily_rate' => 'boolean',
        'default_hourly_late_fee' => 'decimal:2',
        'payment_methods_config' => 'array',
        'is_dynamic_qris_enabled' => 'boolean',
    ];

    public function getLateFeePolicy(): array
    {
        $roundingMode = $this->late_fee_rounding_mode;
        if (!in_array($roundingMode, ['hourly_ceil', 'half_hourly_ceil'], true)) {
            $roundingMode = 'hourly_ceil';
        }

        return [
            'return_grace_period_minutes' => max(0, (int) ($this->return_grace_period_minutes ?? 15)),
            'late_fee_rounding_mode' => $roundingMode,
            'cap_late_fee_at_daily_rate' => $this->cap_late_fee_at_daily_rate !== null ? (bool) $this->cap_late_fee_at_daily_rate : true,
            'default_hourly_late_fee' => $this->default_hourly_late_fee !== null ? round((float) $this->default_hourly_late_fee, 2) : 0.0,
        ];
    }

    public function getLatePickupPolicy(): array
    {
        $mode = $this->late_pickup_end_time_mode;
        if (!in_array($mode, ['keep_original_end', 'shift_if_available'], true)) {
            $mode = 'keep_original_end';
        }

        return [
            'booking_grace_period_minutes' => max(5, (int) ($this->booking_grace_period_minutes ?? 60)),
            'auto_expire_unpaid_booking' => $this->auto_expire_unpaid_booking !== null ? (bool) $this->auto_expire_unpaid_booking : true,
            'auto_expire_paid_booking' => $this->auto_expire_paid_booking !== null ? (bool) $this->auto_expire_paid_booking : false,
            'late_pickup_end_time_mode' => $mode,
        ];
    }

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
            'is_dynamic_qris_enabled' => (bool)($this->is_dynamic_qris_enabled ?? false),
            'qris_raw_payload' => $this->qris_raw_payload,
            'qris_image_path' => null,
            'cash_enabled' => true,
            'bank_transfer_enabled' => true,
        ];

        $config = array_replace_recursive($default, $this->payment_methods_config ?? []);

        if ($affiliateId) {
            $branch = $this->branches()->find($affiliateId);
            if ($branch) {
                if (!empty($branch->payment_methods_config_override)) {
                    $config = array_replace_recursive($config, $branch->payment_methods_config_override);
                }
                if ($branch->qris_raw_payload !== null) {
                    $config['qris_raw_payload'] = $branch->qris_raw_payload;
                }
                if ($branch->is_dynamic_qris_enabled !== null) {
                    $config['is_dynamic_qris_enabled'] = (bool)$branch->is_dynamic_qris_enabled;
                }
            }
        }

        $config['has_qris_image'] = !empty($config['qris_image_path']);

        return $config;
    }

    public function hasQrisImage(?int $affiliateId = null): bool
    {
        $config = $this->getPaymentMethodsConfig($affiliateId);
        return !empty($config['qris_image_path']);
    }

    public function getQrisImageUrl(?int $affiliateId = null): ?string
    {
        $config = $this->getPaymentMethodsConfig($affiliateId);
        $path = $config['qris_image_path'] ?? null;
        if (!$path) {
            return null;
        }

        $version = time();
        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
            $version = \Illuminate\Support\Facades\Storage::disk('public')->lastModified($path);
            return asset('storage/' . $path) . "?v={$version}";
        }

        if (\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            $version = \Illuminate\Support\Facades\Storage::disk('local')->lastModified($path);
            return url("/api/v1/payment-settings/qris-image?business_id={$this->id}" . ($affiliateId ? "&branch_id={$affiliateId}" : "") . "&v={$version}");
        }

        return asset('storage/' . $path) . "?v={$version}";
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

    public function isCollateralEnabled(): bool
    {
        return $this->enable_collateral !== null ? (bool) $this->enable_collateral : false;
    }

    public function isCollateralRequired(): bool
    {
        return $this->require_collateral !== null ? (bool) $this->require_collateral : false;
    }

    /**
     * Normalize collateral type entries and fall back to default presets when empty.
     *
     * @param  mixed  $raw
     * @return array<int, array{id: string, name: string, input_mode: string, is_active: bool}>
     */
    public static function normalizeCollateralTypes(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded) || empty($decoded)) {
            return self::DEFAULT_COLLATERAL_TYPES;
        }

        $normalized = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            $id = trim((string) ($entry['id'] ?? ''));
            if ($id === '' && $name !== '') {
                $id = Str::slug($name, '_');
            }
            if ($id === '' || $name === '') {
                continue;
            }
            $mode = (string) ($entry['input_mode'] ?? 'vault_slot');
            if (!in_array($mode, self::COLLATERAL_INPUT_MODES, true)) {
                $mode = 'vault_slot';
            }
            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'input_mode' => $mode,
                'is_active' => array_key_exists('is_active', $entry) ? (bool) $entry['is_active'] : true,
            ];
        }

        return !empty($normalized) ? $normalized : self::DEFAULT_COLLATERAL_TYPES;
    }

    /**
     * Resolve configured collateral types with safe default presets when null/empty.
     *
     * @return array<int, array{id: string, name: string, input_mode: string, is_active: bool}>
     */
    public function getResolvedCollateralTypes(): array
    {
        return self::normalizeCollateralTypes($this->collateral_types);
    }

    /**
     * Override raw attribute read so null/unconfigured DB column always serializes defaults.
     */
    public function getCollateralTypesAttribute($value): array
    {
        return self::normalizeCollateralTypes($value);
    }

    public function getCollateralSettings(): array
    {
        return [
            'enable_collateral' => $this->isCollateralEnabled(),
            'require_collateral' => $this->isCollateralRequired(),
            'collateral_types' => $this->getResolvedCollateralTypes(),
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
