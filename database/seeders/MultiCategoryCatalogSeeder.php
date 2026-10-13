<?php

namespace Database\Seeders;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\PricingTier;
use Illuminate\Database\Seeder;

class MultiCategoryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $central = Affiliate::where('is_central', true)->first() ?? Affiliate::first();
        if (!$central) {
            return;
        }

        // 1. Categories
        $cameraCat = AssetCategory::firstOrCreate(
            ['slug' => 'kamera'],
            [
                'name' => 'Kamera & Lensa',
                'description' => 'Kamera mirrorless, DSLR, action cam, dan lensa profesional',
                'is_active' => true,
            ]
        );

        $laptopCat = AssetCategory::firstOrCreate(
            ['slug' => 'laptop'],
            [
                'name' => 'Laptop & Komputer',
                'description' => 'MacBook dan laptop gaming/editing performa tinggi',
                'is_active' => true,
            ]
        );

        $projectorCat = AssetCategory::firstOrCreate(
            ['slug' => 'proyektor'],
            [
                'name' => 'Proyektor & Audio Visual',
                'description' => 'Proyektor presentasi, layar standing, dan sound portable',
                'is_active' => true,
            ]
        );

        $consoleCat = AssetCategory::firstOrCreate(
            ['slug' => 'konsol-game'],
            [
                'name' => 'Konsol Game',
                'description' => 'PlayStation, Nintendo Switch, dan controller tambahan',
                'is_active' => true,
            ]
        );

        // 2. Models & Rates
        // Camera: Sony Alpha A7 IV
        $sonyA7 = AssetModel::firstOrCreate(
            ['slug' => 'sony-alpha-a7-iv'],
            [
                'category_id' => $cameraCat->id,
                'brand' => 'Sony',
                'name' => 'Sony Alpha A7 IV Kit 28-70mm',
                'description' => 'Kamera full-frame 33MP untuk fotografer & videografer komersial',
                'storage_capacity' => null,
                'color' => 'Hitam',
                'standard_accessories' => ['Baterai NP-FZ100 (2 unit)', 'Dual Charger', 'Tali Strap', 'SD Card Sandisk Extreme Pro 128GB', 'Tas Kamera'],
                'has_serial_number' => true,
                'requires_frp_check' => false,
                'specifications' => [
                    'sensor' => '33MP Full-Frame Exmor R',
                    'video' => '4K 60p 10-bit 4:2:2',
                    'stabilization' => '5-Axis IBIS',
                ],
                'is_active' => true,
            ]
        );

        PricingTier::firstOrCreate(['model_id' => $sonyA7->id, 'duration_unit' => 'day', 'duration_value' => 1], ['duration_hours' => 24, 'price' => 250000.00, 'extension_hourly_rate' => 20000.00, 'is_active' => true]);
        PricingTier::firstOrCreate(['model_id' => $sonyA7->id, 'duration_unit' => 'day', 'duration_value' => 3], ['duration_hours' => 72, 'price' => 650000.00, 'extension_hourly_rate' => 20000.00, 'is_active' => true]);
        PricingTier::firstOrCreate(['model_id' => $sonyA7->id, 'duration_unit' => 'week', 'duration_value' => 1], ['duration_hours' => 168, 'price' => 1400000.00, 'extension_hourly_rate' => 20000.00, 'is_active' => true]);

        Asset::firstOrCreate(
            ['asset_code' => 'AST-CAM-001'],
            [
                'affiliate_id' => $central->id,
                'model_id' => $sonyA7->id,
                'serial_number' => 'SN-SONY-778811',
                'status' => 'ready',
                'condition_grade' => 'A',
                'notes' => 'Unit prima, sensor bersih bebas jamur',
            ]
        );

        // Laptop: MacBook Pro 14 M3
        $macbook = AssetModel::firstOrCreate(
            ['slug' => 'macbook-pro-14-m3-pro'],
            [
                'category_id' => $laptopCat->id,
                'brand' => 'Apple',
                'name' => 'MacBook Pro 14 M3 Pro 18GB 512GB',
                'description' => 'Laptop premium Apple Silicon untuk rendering & software engineering',
                'storage_capacity' => '512GB',
                'color' => 'Space Black',
                'standard_accessories' => ['MagSafe 3 Cable', 'Adaptor 70W USB-C', 'Sleeve Kulit'],
                'has_serial_number' => true,
                'requires_frp_check' => true, // macOS Activation Lock / iCloud guard
                'specifications' => [
                    'chip' => 'Apple M3 Pro (11-Core CPU, 14-Core GPU)',
                    'ram' => '18GB Unified Memory',
                    'screen' => '14.2 Liquid Retina XDR 120Hz',
                ],
                'is_active' => true,
            ]
        );

        PricingTier::firstOrCreate(['model_id' => $macbook->id, 'duration_unit' => 'day', 'duration_value' => 1], ['duration_hours' => 24, 'price' => 300000.00, 'extension_hourly_rate' => 25000.00, 'is_active' => true]);
        PricingTier::firstOrCreate(['model_id' => $macbook->id, 'duration_unit' => 'day', 'duration_value' => 3], ['duration_hours' => 72, 'price' => 800000.00, 'extension_hourly_rate' => 25000.00, 'is_active' => true]);
        PricingTier::firstOrCreate(['model_id' => $macbook->id, 'duration_unit' => 'week', 'duration_value' => 1], ['duration_hours' => 168, 'price' => 1750000.00, 'extension_hourly_rate' => 25000.00, 'is_active' => true]);

        Asset::firstOrCreate(
            ['asset_code' => 'AST-MBP-001'],
            [
                'affiliate_id' => $central->id,
                'model_id' => $macbook->id,
                'serial_number' => 'C02G9988X192',
                'status' => 'ready',
                'condition_grade' => 'A',
                'battery_health' => 100,
                'notes' => 'Cycle count 12, baterai 100%',
            ]
        );

        // Projector: Epson EB-E500
        $projector = AssetModel::firstOrCreate(
            ['slug' => 'epson-eb-e500-3300-lumens'],
            [
                'category_id' => $projectorCat->id,
                'brand' => 'Epson',
                'name' => 'Epson EB-E500 3300 Lumens 3LCD',
                'description' => 'Proyektor jernih hemat daya untuk seminar, nobar, dan presentasi kantor',
                'storage_capacity' => null,
                'color' => 'Putih',
                'standard_accessories' => ['Kabel HDMI 5m', 'Kabel Power', 'Remote Control', 'Tas Proyektor'],
                'has_serial_number' => true,
                'requires_frp_check' => false,
                'specifications' => [
                    'brightness' => '3300 ANSI Lumens',
                    'technology' => '3LCD',
                    'resolution' => 'XGA (1024 x 768)',
                ],
                'is_active' => true,
            ]
        );

        PricingTier::firstOrCreate(['model_id' => $projector->id, 'duration_unit' => 'hour', 'duration_value' => 6], ['duration_hours' => 6, 'price' => 90000.00, 'extension_hourly_rate' => 15000.00, 'is_active' => true]);
        PricingTier::firstOrCreate(['model_id' => $projector->id, 'duration_unit' => 'day', 'duration_value' => 1], ['duration_hours' => 24, 'price' => 150000.00, 'extension_hourly_rate' => 15000.00, 'is_active' => true]);
        PricingTier::firstOrCreate(['model_id' => $projector->id, 'duration_unit' => 'day', 'duration_value' => 2], ['duration_hours' => 48, 'price' => 280000.00, 'extension_hourly_rate' => 15000.00, 'is_active' => true]);

        Asset::firstOrCreate(
            ['asset_code' => 'AST-PRJ-001'],
            [
                'affiliate_id' => $central->id,
                'model_id' => $projector->id,
                'serial_number' => 'SN-EPSON-339911',
                'status' => 'ready',
                'condition_grade' => 'A',
                'notes' => 'Lampu baru, pemakaian di bawah 100 jam',
            ]
        );

        // Gaming Console: PlayStation 5 Slim
        $ps5 = AssetModel::firstOrCreate(
            ['slug' => 'playstation-5-slim-1tb'],
            [
                'category_id' => $consoleCat->id,
                'brand' => 'Sony',
                'name' => 'PlayStation 5 Slim 1TB + 2 DualSense',
                'description' => 'Konsel game next-gen lengkap dengan 2 stick dan game terinstal',
                'storage_capacity' => '1TB SSD',
                'color' => 'Putih',
                'standard_accessories' => ['DualSense Controller (2 pcs)', 'Kabel HDMI High-Speed', 'Kabel Type-C', 'Kabel Power', 'Dus & Bag'],
                'has_serial_number' => true,
                'requires_frp_check' => false,
                'specifications' => [
                    'storage' => '1TB Custom NVMe SSD',
                    'accessories' => '2 Controllers + E-Football, FIFA 24, God of War Ragnarok pre-installed',
                ],
                'is_active' => true,
            ]
        );

        PricingTier::firstOrCreate(['model_id' => $ps5->id, 'duration_unit' => 'day', 'duration_value' => 1], ['duration_hours' => 24, 'price' => 180000.00, 'extension_hourly_rate' => 15000.00, 'is_active' => true]);
        PricingTier::firstOrCreate(['model_id' => $ps5->id, 'duration_unit' => 'day', 'duration_value' => 2], ['duration_hours' => 48, 'price' => 320000.00, 'extension_hourly_rate' => 15000.00, 'is_active' => true]);
        PricingTier::firstOrCreate(['model_id' => $ps5->id, 'duration_unit' => 'week', 'duration_value' => 1], ['duration_hours' => 168, 'price' => 950000.00, 'extension_hourly_rate' => 15000.00, 'is_active' => true]);

        Asset::firstOrCreate(
            ['asset_code' => 'AST-PS5-001'],
            [
                'affiliate_id' => $central->id,
                'model_id' => $ps5->id,
                'serial_number' => 'SN-PS5-998822',
                'status' => 'ready',
                'condition_grade' => 'A',
                'notes' => 'Stick responsif no drift, firmware updated',
            ]
        );
    }
}
