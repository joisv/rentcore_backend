<?php

namespace Database\Seeders;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\User;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Affiliates
        $central = Affiliate::create([
            'code' => 'GNT',
            'name' => 'Genteng Central Hub',
            'slug' => 'genteng-central-hub',
            'phone' => '0812-3456-0001',
            'address' => 'Jl. Gajah Mada No. 45, Genteng',
            'city' => 'Banyuwangi',
            'is_central' => true,
            'royalty_percentage' => 0.00,
            'cashier_discount_limit_percent' => 10.00,
            'cashier_discount_limit_nominal' => 50000.00,
            'is_active' => true,
        ]);

        $satellite = Affiliate::create([
            'code' => 'BWX',
            'name' => 'Banyuwangi Kota Hub',
            'slug' => 'banyuwangi-kota-hub',
            'phone' => '0812-3456-0002',
            'address' => 'Jl. Ahmad Yani No. 12, Banyuwangi Kota',
            'city' => 'Banyuwangi',
            'is_central' => false,
            'royalty_percentage' => 10.00,
            'cashier_discount_limit_percent' => 10.00,
            'cashier_discount_limit_nominal' => 50000.00,
            'is_active' => true,
        ]);

        // 2. Users
        $superAdmin = User::create([
            'name' => 'Super Administrator RentCore',
            'email' => 'superadmin@rentcore.id',
            'password' => Hash::make('password'),
            'phone' => '0811-0000-0001',
            'role' => 'super_admin',
            'affiliate_id' => null, // Super admin is network-wide
            'is_active' => true,
        ]);

        $managerGenteng = User::create([
            'name' => 'Reza Pratama (Manager Genteng)',
            'email' => 'manager.genteng@rentcore.id',
            'password' => Hash::make('password'),
            'phone' => '0812-1111-2222',
            'role' => 'affiliate_admin',
            'affiliate_id' => $central->id,
            'manager_override_pin' => Hash::make('123456'), // 6-digit override PIN
            'is_active' => true,
        ]);

        $cashierGenteng = User::create([
            'name' => 'Budi Santoso (Kasir Genteng)',
            'email' => 'kasir.genteng@rentcore.id',
            'password' => Hash::make('password'),
            'phone' => '0813-2222-3333',
            'role' => 'staff',
            'affiliate_id' => $central->id,
            'is_active' => true,
        ]);

        // 3. Payment Methods
        $cashMethod    = PaymentMethod::firstOrCreate(['code' => 'CASH'], ['name' => 'Tunai di Meja Kasir', 'is_active' => true]);
        $qrisMethod    = PaymentMethod::firstOrCreate(['code' => 'QRIS'], ['name' => 'QRIS Dinamis / Statis', 'is_active' => true]);
        $bcaMethod     = PaymentMethod::firstOrCreate(['code' => 'BCA'],  ['name' => 'Transfer Bank BCA', 'is_active' => true]);
        $mandiriMethod = PaymentMethod::firstOrCreate(['code' => 'MANDIRI'], ['name' => 'Transfer Bank Mandiri', 'is_active' => true]);
        $briMethod     = PaymentMethod::firstOrCreate(['code' => 'BRI'],  ['name' => 'Transfer Bank BRI', 'is_active' => true]);
        $bniMethod     = PaymentMethod::firstOrCreate(['code' => 'BNI'],  ['name' => 'Transfer Bank BNI', 'is_active' => true]);
        $shopeeMethod  = PaymentMethod::firstOrCreate(['code' => 'SHOPEEPAY'], ['name' => 'ShopeePay', 'is_active' => true]);
        $danaMethod    = PaymentMethod::firstOrCreate(['code' => 'DANA'], ['name' => 'DANA', 'is_active' => true]);
        $gopayMethod   = PaymentMethod::firstOrCreate(['code' => 'GOPAY'], ['name' => 'GoPay', 'is_active' => true]);
        $ovoMethod     = PaymentMethod::firstOrCreate(['code' => 'OVO'], ['name' => 'OVO', 'is_active' => true]);
        $linkajaMethod = PaymentMethod::firstOrCreate(['code' => 'LINKAJA'], ['name' => 'LinkAja', 'is_active' => true]);

        // 4. Asset Catalog (Categories & Models)
        $smartphoneCat = AssetCategory::create([
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'description' => 'Flagship & mid-range smartphones (Apple iPhone & Android)',
            'is_active' => true,
        ]);

        $tabletCat = AssetCategory::create([
            'name' => 'Tablets',
            'slug' => 'tablets',
            'description' => 'Apple iPad & Android professional tablets',
            'is_active' => true,
        ]);

        // Model 1: iPhone 15 Pro
        $ip15Pro = AssetModel::create([
            'category_id' => $smartphoneCat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro 256GB Titanium',
            'slug' => 'iphone-15-pro-256gb-titanium',
            'storage_capacity' => '256GB',
            'color' => 'Natural Titanium',
            'standard_accessories' => ['Kabel USB-C to USB-C', 'Adaptor 20W Fast Charging', 'Silicone Case Transparan'],
            'is_active' => true,
        ]);

        PricingTier::create(['model_id' => $ip15Pro->id, 'duration_hours' => 6,  'price' => 120000.00, 'extension_hourly_rate' => 15000.00]);
        PricingTier::create(['model_id' => $ip15Pro->id, 'duration_hours' => 12, 'price' => 200000.00, 'extension_hourly_rate' => 15000.00]);
        PricingTier::create(['model_id' => $ip15Pro->id, 'duration_hours' => 24, 'price' => 350000.00, 'extension_hourly_rate' => 15000.00]);
        PricingTier::create(['model_id' => $ip15Pro->id, 'duration_hours' => 48, 'price' => 650000.00, 'extension_hourly_rate' => 15000.00]);

        // Model 2: iPhone 14
        $ip14 = AssetModel::create([
            'category_id' => $smartphoneCat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 14 128GB Blue',
            'slug' => 'iphone-14-128gb-blue',
            'storage_capacity' => '128GB',
            'color' => 'Blue',
            'standard_accessories' => ['Kabel Lightning', 'Adaptor 20W Fast Charging', 'Silicone Case'],
            'is_active' => true,
        ]);

        PricingTier::create(['model_id' => $ip14->id, 'duration_hours' => 6,  'price' => 80000.00,  'extension_hourly_rate' => 12000.00]);
        PricingTier::create(['model_id' => $ip14->id, 'duration_hours' => 12, 'price' => 140000.00, 'extension_hourly_rate' => 12000.00]);
        PricingTier::create(['model_id' => $ip14->id, 'duration_hours' => 24, 'price' => 240000.00, 'extension_hourly_rate' => 12000.00]);

        // Model 3: iPad Pro 11 M2
        $ipadPro = AssetModel::create([
            'category_id' => $tabletCat->id,
            'brand' => 'Apple',
            'name' => 'iPad Pro 11 M2 128GB Space Gray',
            'slug' => 'ipad-pro-11-m2-128gb-space-gray',
            'storage_capacity' => '128GB',
            'color' => 'Space Gray',
            'standard_accessories' => ['Apple Pencil Gen 2', 'Smart Folio Case', 'Kabel USB-C', 'Adaptor 30W'],
            'is_active' => true,
        ]);

        PricingTier::create(['model_id' => $ipadPro->id, 'duration_hours' => 12, 'price' => 180000.00, 'extension_hourly_rate' => 15000.00]);
        PricingTier::create(['model_id' => $ipadPro->id, 'duration_hours' => 24, 'price' => 300000.00, 'extension_hourly_rate' => 15000.00]);

        // 5. Assets (Physical Units)
        Asset::create([
            'affiliate_id' => $central->id,
            'model_id' => $ip15Pro->id,
            'asset_code' => 'AST-IP15P-001',
            'serial_number' => 'F2LND001890',
            'imei_1' => '354890123456781',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 98,
            'notes' => 'Fisik 100% mulus garansi iBox',
        ]);

        Asset::create([
            'affiliate_id' => $central->id,
            'model_id' => $ip15Pro->id,
            'asset_code' => 'AST-IP15P-002',
            'serial_number' => 'F2LND001891',
            'imei_1' => '354890123456782',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 100,
            'notes' => 'Unit baru restock',
        ]);

        Asset::create([
            'affiliate_id' => $central->id,
            'model_id' => $ip14->id,
            'asset_code' => 'AST-IP14-001',
            'serial_number' => 'DX3J0098412',
            'imei_1' => '356781234598711',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 92,
            'notes' => 'Bebas lecet, baterai awet',
        ]);

        Asset::create([
            'affiliate_id' => $central->id,
            'model_id' => $ipadPro->id,
            'asset_code' => 'AST-IPAD-001',
            'serial_number' => 'DM6K8829101',
            'imei_1' => null,
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 96,
            'notes' => 'Layar true tone bening, Apple Pencil aktif',
        ]);

        // 6. Customers & Identities
        $c1 = Customer::create([
            'name' => 'Ahmad Fauzi',
            'phone' => '081234567890',
            'email' => 'ahmad.fauzi@gmail.com',
            'address' => 'Jl. Diponegoro No. 8, Genteng',
        ]);
        CustomerIdentity::create([
            'customer_id' => $c1->id,
            'identity_type' => 'KTP',
            'identity_number' => '3510091205940001',
            'is_verified' => true,
        ]);

        $c2 = Customer::create([
            'name' => 'Budi Setiawan',
            'phone' => '081398765432',
            'email' => 'budi.setiawan@yahoo.com',
            'address' => 'Perum Griya Genteng Asri B-12',
        ]);
        CustomerIdentity::create([
            'customer_id' => $c2->id,
            'identity_type' => 'KTP',
            'identity_number' => '3510092107950003',
            'is_verified' => true,
        ]);

        // 7. Vouchers (Super-Admin Governance with % & min transaction amount)
        Voucher::create([
            'affiliate_id' => null, // Jaringan pusat (semua cabang)
            'code' => 'RC-VCR-8921',
            'name' => 'Voucher Diskon Pelanggan Baru 15%',
            'description' => 'Diskon 15% untuk sewa minimal Rp 300.000 dengan plafon maksimal Rp 100.000.',
            'discount_percentage' => 15.00,
            'max_discount_cap' => 100000.00,
            'min_transaction_amount' => 300000.00, // Syarat minimal sewa
            'barcode_type' => 'qr_code',
            'start_at' => Carbon::now()->subDays(1),
            'end_at' => Carbon::now()->addMonths(3),
            'usage_limit_total' => 500,
            'usage_limit_per_customer' => 1,
            'usage_count' => 0,
            'is_active' => true,
            'created_by' => $superAdmin->id,
        ]);

        Voucher::create([
            'affiliate_id' => null,
            'code' => 'RC-HEMAT10',
            'name' => 'Promo Akhir Pekan Hemat 10%',
            'description' => 'Diskon 10% minimal sewa Rp 200.000',
            'discount_percentage' => 10.00,
            'max_discount_cap' => 50000.00,
            'min_transaction_amount' => 200000.00,
            'barcode_type' => 'qr_code',
            'start_at' => Carbon::now()->subDays(1),
            'end_at' => Carbon::now()->addMonths(1),
            'usage_limit_total' => 1000,
            'usage_limit_per_customer' => 2,
            'usage_count' => 0,
            'is_active' => true,
            'created_by' => $superAdmin->id,
        ]);
    }
}
