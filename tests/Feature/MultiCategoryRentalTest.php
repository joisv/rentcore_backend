<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiCategoryRentalTest extends TestCase
{
    use RefreshDatabase;

    protected Affiliate $affiliate;
    protected User $superAdmin;
    protected User $cashier;
    protected PaymentMethod $paymentMethod;
    protected \App\Models\Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'name' => 'Super Administrator',
            'email' => 'admin@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->business = \App\Models\Business::create([
            'name' => 'RentCore Central',
            'slug' => 'rentcore-central',
            'owner_id' => $this->superAdmin->id,
            'is_active' => true,
            'shift_enforcement_enabled' => false,
        ]);

        $this->affiliate = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'GNT',
            'name' => 'Genteng Central Hub',
            'slug' => 'genteng-central-hub',
            'is_central' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Budi Santoso',
            'email' => 'kasir@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->affiliate->id,
            'is_active' => true,
        ]);

        \App\Models\BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->cashier->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        \App\Models\BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->superAdmin->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai di Meja Kasir',
            'is_active' => true,
        ]);
    }

    public function test_can_create_and_list_categories(): void
    {
        $token = $this->superAdmin->createToken('Test')->plainTextToken;

        // Create new category
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/categories', [
                'name' => 'Kamera & Lensa',
                'description' => 'Kamera mirrorless, DSLR, action cam, dan lensa cinema',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Kamera & Lensa')
            ->assertJsonPath('data.slug', 'kamera-lensa');

        // Create another category that yields the same base slug; verify deduplication
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/categories', [
                'name' => 'Kamera Lensa',
                'description' => 'Variant category with duplicate slug',
            ]);
        $response2->assertStatus(201)
            ->assertJsonPath('data.slug', 'kamera-lensa-2');

        // List categories
        $listResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/categories');

        $listResponse->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_can_create_product_with_multi_unit_rates(): void
    {
        $token = $this->superAdmin->createToken('Test')->plainTextToken;

        $category = AssetCategory::create([
            'name' => 'Kamera',
            'slug' => 'kamera',
            'is_active' => true,
        ]);

        $payload = [
            'category_id' => $category->id,
            'brand' => 'Sony',
            'name' => 'Sony Alpha A7 IV Kit 28-70mm',
            'description' => 'Full-frame mirrorless camera for photo and video',
            'standard_accessories' => ['Battery NP-FZ100', 'Charger Dual Slot', 'Camera Strap', 'SD Card 128GB V60'],
            'has_serial_number' => true,
            'requires_frp_check' => false,
            'specifications' => [
                'sensor' => '33MP Full-Frame Exmor R CMOS',
                'video' => '4K 60p 10-bit 4:2:2',
            ],
            'rates' => [
                ['duration_unit' => 'day', 'duration_value' => 1, 'price' => 250000.00, 'extension_hourly_rate' => 20000.00],
                ['duration_unit' => 'day', 'duration_value' => 3, 'price' => 650000.00, 'extension_hourly_rate' => 20000.00],
                ['duration_unit' => 'week', 'duration_value' => 1, 'price' => 1400000.00, 'extension_hourly_rate' => 20000.00],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/products', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Sony Alpha A7 IV Kit 28-70mm')
            ->assertJsonPath('data.requires_frp_check', false);

        $this->assertDatabaseHas('pricing_tiers', [
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 250000.00,
        ]);

        $this->assertDatabaseHas('pricing_tiers', [
            'duration_unit' => 'week',
            'duration_value' => 1,
            'duration_hours' => 168,
            'price' => 1400000.00,
        ]);
    }

    public function test_pricing_calculation_endpoint(): void
    {
        $token = $this->cashier->createToken('Test')->plainTextToken;

        $category = AssetCategory::create(['business_id' => $this->business->id, 'name' => 'Proyektor', 'slug' => 'proyektor']);
        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Epson',
            'name' => 'Epson EB-E500 3300 Lumens',
            'slug' => 'epson-eb-e500',
            'requires_frp_check' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $model->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 150000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/pricing/calculate', [
                'model_id' => $model->id,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.price', 150000)
            ->assertJsonPath('data.duration_hours', 24)
            ->assertJsonPath('data.rate_snapshot.brand', 'Epson');
    }

    public function test_direct_counter_rental_with_daily_duration_and_snapshots(): void
    {
        $token = $this->cashier->createToken('Test')->plainTextToken;

        $category = AssetCategory::create(['name' => 'Konsol Game', 'slug' => 'konsol-game']);
        $model = AssetModel::create([
            'category_id' => $category->id,
            'brand' => 'Sony',
            'name' => 'PlayStation 5 Slim Digital 1TB',
            'slug' => 'ps5-slim-digital',
            'requires_frp_check' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $model->id,
            'duration_unit' => 'day',
            'duration_value' => 2,
            'duration_hours' => 48,
            'price' => 300000.00,
            'extension_hourly_rate' => 20000.00,
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'affiliate_id' => $this->affiliate->id,
            'model_id' => $model->id,
            'asset_code' => 'AST-PS5-001',
            'serial_number' => 'SN-PS5-SLIM-99182',
            'status' => 'ready',
        ]);

        $payload = [
            'customer_name' => 'Doni Firmansyah',
            'customer_phone' => '082199881122',
            'identity_type' => 'KTP',
            'identity_number' => '3510099887711223',
            'asset_ids' => [$asset->id],
            'duration_unit' => 'day',
            'duration_value' => 2,
            'deposit_fee' => 50000.00,
            'payment_method_id' => $this->paymentMethod->id,
            'collateral_type' => 'KTP Asli',
            'collateral_storage_slot' => 'Brankas B, Slot 05',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'rented')
            ->assertJsonPath('data.duration_unit', 'day')
            ->assertJsonPath('data.duration_value', 2)
            ->assertJsonPath('data.duration_hours', 48)
            ->assertJsonPath('data.subtotal_amount', '300000.00')
            ->assertJsonPath('data.total_bill', '350000.00');

        // Verify booking item snapshot
        $bookingId = $response->json('data.id');
        $this->assertDatabaseHas('booking_items', [
            'booking_id' => $bookingId,
            'asset_id' => $asset->id,
            'duration_unit' => 'day',
            'duration_value' => 2,
        ]);

        // Verify unit is rented
        $this->assertEquals('rented', $asset->fresh()->status);
    }

    public function test_return_for_non_frp_item_does_not_require_icloud_removed(): void
    {
        $token = $this->cashier->createToken('Test')->plainTextToken;

        $category = AssetCategory::create(['business_id' => $this->business->id, 'name' => 'Kamera', 'slug' => 'kamera']);
        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Canon',
            'name' => 'Canon EOS R6 Mark II',
            'slug' => 'canon-eos-r6-mk2',
            'requires_frp_check' => false,
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->affiliate->id,
            'model_id' => $model->id,
            'asset_code' => 'AST-CAM-001',
            'serial_number' => 'SN-CANON-88127',
            'status' => 'rented',
        ]);

        $customer = \App\Models\Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Dwi Santika',
            'phone' => '081298765432',
        ]);

        $booking = $this->cashier->bookingsCreated()->create([
            'business_id' => $this->business->id,
            'booking_code' => 'RC-CAM-001',
            'affiliate_id' => $this->affiliate->id,
            'customer_id' => $customer->id,
            'start_at' => Carbon::now()->subDays(2),
            'end_at' => Carbon::now(),
            'duration_hours' => 48,
            'duration_unit' => 'day',
            'duration_value' => 2,
            'status' => 'rented',
            'subtotal_amount' => 500000.00,
            'total_bill' => 500000.00,
            'collateral_secured' => true,
        ]);

        $booking->items()->create([
            'asset_id' => $asset->id,
            'unit_price' => 500000.00,
            'duration_unit' => 'day',
            'duration_value' => 2,
        ]);

        // Return camera with icloud_removed omitted / false - MUST SUCCEED
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'body_clean' => true,
                    'sensor_clean' => true,
                ],
                'accessories_returned' => [
                    'strap' => true,
                    'battery' => true,
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('returned', $booking->fresh()->status);
        $this->assertEquals('ready', $asset->fresh()->status);
    }

    public function test_asset_availability_check_endpoint(): void
    {
        $token = $this->cashier->createToken('Test')->plainTextToken;

        $category = AssetCategory::create(['business_id' => $this->business->id, 'name' => 'Laptop', 'slug' => 'laptop']);
        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Asus',
            'name' => 'Asus ROG Zephyrus G14',
            'slug' => 'asus-rog-g14',
            'requires_frp_check' => false,
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->affiliate->id,
            'model_id' => $model->id,
            'asset_code' => 'AST-ROG-001',
            'serial_number' => 'SN-ROG-ZEPHYRUS-01',
            'status' => 'ready',
        ]);

        // Check availability
        $startAt = Carbon::now()->toDateTimeString();
        $endAt = Carbon::now()->addDays(3)->toDateTimeString();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/assets/{$asset->id}/availability?start_at=" . urlencode($startAt) . "&end_at=" . urlencode($endAt));

        $response->assertStatus(200)
            ->assertJsonPath('data.is_available', true);
    }

    public function test_business_creation_with_business_categories(): void
    {
        $token = $this->superAdmin->createToken('Test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/businesses', [
                'name' => 'Kamera Bali Pro',
                'city' => 'Denpasar',
                'business_categories' => ['cameras_photography', 'tools_equipment'],
                'initial_branch_name' => 'Kamera Bali Pusat',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Kamera Bali Pro')
            ->assertJsonPath('data.business_categories', ['cameras_photography', 'tools_equipment']);

        $bizId = $response->json('data.id');
        $this->assertDatabaseHas('businesses', [
            'id' => $bizId,
            'name' => 'Kamera Bali Pro',
        ]);
    }

    public function test_can_register_unit_without_serial_number_when_model_has_no_serial(): void
    {
        $token = $this->superAdmin->createToken('Test')->plainTextToken;

        $category = AssetCategory::create(['business_id' => $this->business->id, 'name' => 'Alat & Perlengkapan', 'slug' => 'alat-perlengkapan']);
        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Krisbow',
            'name' => 'Tangga Aluminium Teleskopik 3.8M',
            'slug' => 'tangga-aluminium-38m',
            'has_serial_number' => false,
            'requires_frp_check' => false,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/assets', [
                'model_id' => $model->id,
                'affiliate_id' => $this->affiliate->id,
                'asset_code' => 'AST-TGG-001',
                'condition_grade' => 'A',
                'notes' => 'Tangga kokoh tanpa serial pabrik',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asset_code', 'AST-TGG-001')
            ->assertJsonPath('data.serial_number', null)
            ->assertJsonPath('data.battery_health', null);

        $this->assertDatabaseHas('assets', [
            'asset_code' => 'AST-TGG-001',
            'serial_number' => null,
            'battery_health' => null,
        ]);
    }

    public function test_unit_registration_does_not_default_battery_health_to_100_for_non_battery_items(): void
    {
        $token = $this->superAdmin->createToken('Test')->plainTextToken;

        $category = AssetCategory::create(['business_id' => $this->business->id, 'name' => 'Kamera', 'slug' => 'kamera-lensa']);
        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Sigma',
            'name' => 'Sigma 24-70mm f/2.8 DG DN Art',
            'slug' => 'sigma-24-70-art',
            'has_serial_number' => true,
            'requires_frp_check' => false,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/assets', [
                'model_id' => $model->id,
                'affiliate_id' => $this->affiliate->id,
                'asset_code' => 'AST-LENS-001',
                'serial_number' => 'SN-SIGMA-99281',
                'condition_grade' => 'A',
                // Omit battery_health completely
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.battery_health', null);

        $this->assertDatabaseHas('assets', [
            'asset_code' => 'AST-LENS-001',
            'battery_health' => null,
        ]);
    }
}
