<?php

namespace App\Services;

use App\Models\AssetCategory;
use Illuminate\Support\Str;

class BusinessPresetService
{
    /**
     * Normalized niche preset definitions with starter categories and default policies.
     */
    public const PRESETS = [
        'iphone_gadget' => [
            'business_categories' => ['iphone_smartphone'],
            'categories' => [
                [
                    'name' => 'iPhone',
                    'description' => 'Unit iPhone berbagai seri untuk sewa harian dan mingguan',
                    'is_addon' => false,
                ],
                [
                    'name' => 'Android & Tablet',
                    'description' => 'Perangkat Android flagship, iPad, dan tablet kerja',
                    'is_addon' => false,
                ],
                [
                    'name' => 'Powerbank & Charger',
                    'description' => 'Aksesoris pengisian daya, MagSafe, dan adaptor tambahan',
                    'is_addon' => true,
                ],
            ],
        ],
        'playstation_console' => [
            'business_categories' => ['gadget_electronics'],
            'categories' => [
                [
                    'name' => 'PlayStation 5 & 4',
                    'description' => 'Konsol PS5, PS4 Pro, dan paket rental game siap main',
                    'is_addon' => false,
                ],
                [
                    'name' => 'Stik & Controller',
                    'description' => 'DualSense, DualShock 4, dan controller tambahan',
                    'is_addon' => true,
                ],
                [
                    'name' => 'TV / Monitor & VR',
                    'description' => 'Smart TV, monitor gaming, dan headset VR pendukung',
                    'is_addon' => false,
                ],
            ],
        ],
        'camera_lens' => [
            'business_categories' => ['camera'],
            'categories' => [
                [
                    'name' => 'Kamera Mirrorless & DSLR',
                    'description' => 'Body kamera mirrorless, DSLR, dan action cam',
                    'is_addon' => false,
                ],
                [
                    'name' => 'Lensa & Gimbal',
                    'description' => 'Lensa prime, zoom, stabilizer gimbal, dan tripod',
                    'is_addon' => false,
                ],
                [
                    'name' => 'Lighting & Audio',
                    'description' => 'Flash, LED video light, dan mikrofon wireless',
                    'is_addon' => true,
                ],
            ],
        ],
        'custom' => [
            'business_categories' => ['other'],
            'categories' => [],
        ],
    ];

    /**
     * Resolve business category tags for a preset while respecting explicit categories.
     *
     * @param  array<int, string>|null  $explicitCategories
     * @return array<int, string>|null
     */
    public static function resolveBusinessCategories(?string $preset, ?array $explicitCategories): ?array
    {
        if (!empty($explicitCategories)) {
            return array_values(array_unique(array_filter($explicitCategories)));
        }

        if ($preset && isset(self::PRESETS[$preset])) {
            return self::PRESETS[$preset]['business_categories'];
        }

        return null;
    }

    /**
     * Seed starter categories for a newly onboarded business based on the selected niche preset.
     *
     * @return array<int, AssetCategory>
     */
    public static function seedStarterCategories(int $businessId, ?string $preset): array
    {
        if (!$preset || !isset(self::PRESETS[$preset])) {
            return [];
        }

        $seeded = [];
        foreach (self::PRESETS[$preset]['categories'] as $entry) {
            $baseSlug = Str::slug($entry['name']);
            $slug = $baseSlug . '-' . $businessId;

            $seeded[] = AssetCategory::withoutGlobalScopes()->firstOrCreate(
                [
                    'business_id' => $businessId,
                    'slug' => $slug,
                ],
                [
                    'name' => $entry['name'],
                    'description' => $entry['description'],
                    'is_addon' => (bool) $entry['is_addon'],
                    'is_active' => true,
                ]
            );
        }

        return $seeded;
    }
}
