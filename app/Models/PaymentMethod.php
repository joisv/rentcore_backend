<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Return a clean human-facing label (always "QRIS" for QRIS code).
     */
    public function displayLabel(): string
    {
        if (strtoupper((string) $this->code) === 'QRIS') {
            return 'QRIS';
        }

        return (string) ($this->name ?: 'Tunai');
    }

    /**
     * Format any raw payment method model/string into a consistent display label.
     */
    public static function normalizeDisplayName(?self $method, string $fallback = 'Tunai'): string
    {
        if (!$method) {
            return $fallback;
        }

        return $method->displayLabel();
    }

    /**
     * Ensure baseline methods (CASH, QRIS, TRANSFER, BCA) exist in the lookup table.
     */
    public static function ensureCoreMethodsExist(): void
    {
        $core = [
            'CASH' => 'Tunai',
            'QRIS' => 'QRIS',
            'TRANSFER' => 'Transfer Bank',
            'BCA' => 'Transfer Bank BCA',
        ];

        foreach ($core as $code => $name) {
            static::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_active' => true]
            );
        }
    }

    /**
     * Resolve the authoritative PaymentMethod using explicit payment_method / payment_channel
     * code when provided (preventing stale numeric IDs from falling back to ShopeePay).
     */
    public static function resolveFromInput(
        mixed $methodId = null,
        ?string $methodCode = null,
        ?string $channel = null
    ): ?self {
        $rawCode = strtoupper(trim((string) ($methodCode ?: $channel ?: '')));
        if ($rawCode !== '') {
            $normalizedCode = match ($rawCode) {
                'TUNAI' => 'CASH',
                'BANK_TRANSFER' => 'TRANSFER',
                'LINKAJA!' => 'LINKAJA',
                default => $rawCode,
            };

            if ($methodId !== null && $methodId !== '') {
                $byId = static::find($methodId);
                if ($byId && strtoupper((string) $byId->code) === $normalizedCode) {
                    return $byId;
                }
            }

            $byCode = static::whereRaw('UPPER(code) = ?', [$normalizedCode])->first();
            if ($byCode) {
                return $byCode;
            }

            $defaultNames = [
                'CASH' => 'Tunai',
                'QRIS' => 'QRIS',
                'TRANSFER' => 'Transfer Bank',
                'BCA' => 'Transfer Bank BCA',
                'MANDIRI' => 'Transfer Bank Mandiri',
                'BRI' => 'Transfer Bank BRI',
                'BNI' => 'Transfer Bank BNI',
                'SHOPEEPAY' => 'ShopeePay',
                'DANA' => 'DANA',
                'GOPAY' => 'GoPay',
                'OVO' => 'OVO',
                'LINKAJA' => 'LinkAja',
            ];

            if (isset($defaultNames[$normalizedCode])) {
                return static::firstOrCreate(
                    ['code' => $normalizedCode],
                    ['name' => $defaultNames[$normalizedCode], 'is_active' => true]
                );
            }
        }

        if ($methodId !== null && $methodId !== '') {
            return static::find($methodId);
        }

        return null;
    }
}
