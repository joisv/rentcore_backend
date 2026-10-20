<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\RentalUnitExchange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RentalUnitExchangeTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $cashier;
    protected User $manager;
    protected PaymentMethod $cashMethod;
    protected AssetModel $modelXr;
    protected AssetModel $model11;
    protected Asset $oldAsset;
    protected Asset $newAssetSame;
    protected Asset $newAssetUpgrade;
    protected Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::create([
            'name' => 'Kasir Exchange',
            'email' => 'cashier.exc@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Test Business',
            'slug' => 'rentcore-test-biz',
            'owner_id' => $this->cashier->id,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'MCM',
            'name' => 'Malang Cyber Mall',
            'slug' => 'mcm-branch',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->cashier->update([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->cashier->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager Toko',
            'email' => 'manager.exc@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'manager_override_pin' => Hash::make('123456'),
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->manager->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai (Cash)',
            'is_active' => true,
        ]);

        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphone',
            'slug' => 'smartphone',
        ]);

        // Model 1: iPhone XR 64GB (Tier Rp 40.000 / 24 hours)
        $this->modelXr = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'iPhone XR 64GB',
            'slug' => 'iphone-xr-64gb',
            'brand' => 'Apple',
            'storage_capacity' => '64GB',
            'color' => 'White',
            'requires_frp_check' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $this->modelXr->id,
            'duration_hours' => 24,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'price' => 40000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);

        // Model 2: iPhone 11 128GB (Tier Rp 70.000 / 24 hours)
        $this->model11 = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'iPhone 11 128GB',
            'slug' => 'iphone-11-128gb',
            'brand' => 'Apple',
            'storage_capacity' => '128GB',
            'color' => 'Black',
            'requires_frp_check' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $this->model11->id,
            'duration_hours' => 24,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'price' => 70000.00,
            'extension_hourly_rate' => 20000.00,
            'is_active' => true,
        ]);

        // Old Asset: currently rented
        $this->oldAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->modelXr->id,
            'asset_code' => 'RC-XR-001',
            'serial_number' => 'SN-XR-001',
            'status' => 'rented',
            'condition_grade' => 'A',
            'battery_health' => 90,
        ]);

        // Replacement Candidate 1: same model (ready)
        $this->newAssetSame = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->modelXr->id,
            'asset_code' => 'RC-XR-002',
            'serial_number' => 'SN-XR-002',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 93,
        ]);

        // Replacement Candidate 2: upgrade model (ready)
        $this->newAssetUpgrade = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->model11->id,
            'asset_code' => 'RC-11-001',
            'serial_number' => 'SN-11-001',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 97,
        ]);

        $customer = Customer::create([
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
        ]);

        $startAt = Carbon::now()->subHours(5);
        $endAt = (clone $startAt)->addHours(24);

        $this->booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'created_by' => $this->cashier->id,
            'booking_code' => 'RC-261020-0099',
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 40000.00,
            'deposit_fee' => 0.00,
            'total_bill' => 40000.00,
            'payment_status' => 'paid',
            'collateral_storage_slot' => 'Brankas A1',
        ]);

        BookingItem::create([
            'booking_id' => $this->booking->id,
            'asset_id' => $this->oldAsset->id,
            'unit_price' => 40000.00,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'status' => 'active',
        ]);
    }

    public function test_get_available_replacements_lists_ready_units_with_tier_comparisons(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->getJson("/api/v1/bookings/{$this->booking->id}/available-replacements");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $candidates = $response->json('data.candidates');
        $this->assertCount(2, $candidates);

        // Verify same tier candidate
        $sameCandidate = collect($candidates)->firstWhere('asset_id', $this->newAssetSame->id);
        $this->assertNotNull($sameCandidate);
        $this->assertEquals('same_tier', $sameCandidate['tier_category']);
        $this->assertEquals(0.0, $sameCandidate['price_differential']);

        // Verify upgrade candidate
        $upgradeCandidate = collect($candidates)->firstWhere('asset_id', $this->newAssetUpgrade->id);
        $this->assertNotNull($upgradeCandidate);
        $this->assertEquals('upgrade', $upgradeCandidate['tier_category']);
        $this->assertEquals(30000.0, $upgradeCandidate['price_differential']);
    }

    public function test_same_tier_exchange_executes_without_extra_charge(): void
    {
        $payload = [
            'old_asset_id' => $this->oldAsset->id,
            'new_asset_id' => $this->newAssetSame->id,
            'reported_issue_category' => 'camera',
            'customer_complaint' => 'Kamera bergetar saat buka aplikasi foto',
            'staff_diagnosis' => 'OIS magnetik kamera goyang',
            'old_unit_condition_grade' => 'B',
            'icloud_signout_verified' => true,
            'old_asset_disposition' => 'maintenance',
        ];

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('receipt_data.price_differential', 0);

        // Old asset transitioned to maintenance
        $this->oldAsset->refresh();
        $this->assertEquals('maintenance', $this->oldAsset->status);
        $this->assertEquals('B', $this->oldAsset->condition_grade);

        // New asset transitioned to rented
        $this->newAssetSame->refresh();
        $this->assertEquals('rented', $this->newAssetSame->status);

        // Old booking item marked exchanged, new active item created
        $this->assertDatabaseHas('booking_items', [
            'booking_id' => $this->booking->id,
            'asset_id' => $this->oldAsset->id,
            'status' => 'exchanged',
        ]);

        $this->assertDatabaseHas('booking_items', [
            'booking_id' => $this->booking->id,
            'asset_id' => $this->newAssetSame->id,
            'status' => 'active',
            'unit_price' => 40000.00,
        ]);

        // Exchange record saved
        $this->assertDatabaseHas('rental_unit_exchanges', [
            'booking_id' => $this->booking->id,
            'old_asset_id' => $this->oldAsset->id,
            'new_asset_id' => $this->newAssetSame->id,
            'reported_issue_category' => 'camera',
            'price_differential' => 0.00,
            'handled_by' => $this->cashier->id,
        ]);

        // Booking total bill unchanged
        $this->booking->refresh();
        $this->assertEquals(40000.00, (float)$this->booking->total_bill);
    }

    public function test_upgrade_exchange_creates_payment_transaction_and_updates_bill(): void
    {
        $payload = [
            'old_asset_id' => $this->oldAsset->id,
            'new_asset_id' => $this->newAssetUpgrade->id,
            'reported_issue_category' => 'battery',
            'customer_complaint' => 'Baterai drop cepat dari 80% ke 10%',
            'staff_diagnosis' => 'Baterai degradasi saat beban tinggi',
            'old_unit_condition_grade' => 'B',
            'icloud_signout_verified' => true,
            'old_asset_disposition' => 'maintenance',
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 50000.00,
        ];

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Verify payment transaction created
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $this->booking->id,
            'type' => 'exchange',
            'direction' => 'inflow',
            'status' => 'completed',
            'amount' => 30000.00,
            'cash_received' => 50000.00,
            'cash_change' => 20000.00,
        ]);

        // Verify booking total bill increased by differential
        $this->booking->refresh();
        $this->assertEquals(70000.00, (float)$this->booking->total_bill);
    }

    public function test_upgrade_fee_waiver_requires_valid_manager_pin(): void
    {
        // 1. Without Manager PIN -> fails
        $payloadNoPin = [
            'old_asset_id' => $this->oldAsset->id,
            'new_asset_id' => $this->newAssetUpgrade->id,
            'reported_issue_category' => 'screen',
            'customer_complaint' => 'Layar bergaris hijau vertikal',
            'staff_diagnosis' => 'Kerusakan flex gate display bawaan',
            'icloud_signout_verified' => true,
            'fee_waived' => true,
            'waiver_reason' => 'Kompensasi stok habis model sama',
        ];

        $responseNoPin = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", $payloadNoPin);

        $responseNoPin->assertStatus(422)
            ->assertJsonValidationErrors(['manager_pin']);

        // 2. With valid Manager PIN -> succeeds
        $payloadWithPin = array_merge($payloadNoPin, [
            'manager_pin' => '123456',
        ]);

        $responseWithPin = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", $payloadWithPin);

        $responseWithPin->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('rental_unit_exchanges', [
            'booking_id' => $this->booking->id,
            'fee_waived' => true,
            'manager_approved_by' => $this->manager->id,
            'price_differential' => 0.00,
        ]);
    }

    public function test_exchange_fails_if_icloud_signout_not_verified_on_apple_devices(): void
    {
        $payload = [
            'old_asset_id' => $this->oldAsset->id,
            'new_asset_id' => $this->newAssetSame->id,
            'reported_issue_category' => 'camera',
            'customer_complaint' => 'Kamera getar',
            'icloud_signout_verified' => false, // Customer refused / forgot to sign out
        ];

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['icloud_signout_verified']);
    }

    public function test_active_rental_displays_replacement_device_after_exchange_and_preserves_old_device_in_history(): void
    {
        $payload = [
            'old_asset_id' => $this->oldAsset->id,
            'new_asset_id' => $this->newAssetSame->id,
            'reported_issue_category' => 'screen',
            'customer_complaint' => 'Layar flickering',
            'icloud_signout_verified' => true,
        ];

        $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", $payload)
            ->assertStatus(200);

        // Fetch booking list
        $listResponse = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->getJson("/api/v1/bookings?status=rented");

        $listResponse->assertStatus(200);
        $bookingData = collect($listResponse->json('data.data'))->firstWhere('id', $this->booking->id);

        // 1. Primary active asset is the replacement unit (RC-XR-002)
        $this->assertEquals($this->newAssetSame->id, $bookingData['items'][0]['asset_id']);
        $this->assertEquals($this->newAssetSame->asset_code, $bookingData['items'][0]['asset']['asset_code']);
        $this->assertEquals($this->newAssetSame->id, $bookingData['active_asset']['id']);

        // 2. Old unit is preserved as an exchanged item
        $this->assertCount(2, $bookingData['items']);
        $this->assertEquals($this->oldAsset->id, $bookingData['items'][1]['asset_id']);
        $this->assertEquals('exchanged', $bookingData['items'][1]['status']);

        // 3. Exchange history record is populated with full details
        $this->assertCount(1, $bookingData['exchanges']);
        $exchange = $bookingData['exchanges'][0];
        $this->assertEquals($this->oldAsset->id, $exchange['old_asset_id']);
        $this->assertEquals($this->newAssetSame->id, $exchange['new_asset_id']);
        $this->assertEquals('Layar flickering', $exchange['customer_complaint']);
        $this->assertNotNull($exchange['exchange_reference']);
    }

    public function test_second_exchange_on_same_booking_targets_current_active_device_and_can_swap_again(): void
    {
        // 1st Exchange: oldAsset -> newAssetSame
        $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", [
                'old_asset_id' => $this->oldAsset->id,
                'new_asset_id' => $this->newAssetSame->id,
                'reported_issue_category' => 'camera',
                'customer_complaint' => 'Kamera getar',
                'icloud_signout_verified' => true,
            ])
            ->assertStatus(200);

        // Check available replacements for 2nd exchange:
        // newAssetSame (current active) and oldAsset (in maintenance) must NOT be offered as candidates!
        $candidatesResponse = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->getJson("/api/v1/bookings/{$this->booking->id}/available-replacements");

        $candidates = $candidatesResponse->json('data.candidates');
        $candidateIds = collect($candidates)->pluck('asset_id')->toArray();

        $this->assertNotContains($this->newAssetSame->id, $candidateIds, 'Currently assigned replacement unit must not be offered as its own replacement');
        $this->assertNotContains($this->oldAsset->id, $candidateIds, 'Quarantined old unit must not be offered as candidate');
        $this->assertContains($this->newAssetUpgrade->id, $candidateIds, 'Other ready unit must be offered');

        // 2nd Exchange: newAssetSame -> newAssetUpgrade
        $secondExchangeResponse = $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", [
                'old_asset_id' => $this->newAssetSame->id,
                'new_asset_id' => $this->newAssetUpgrade->id,
                'reported_issue_category' => 'battery',
                'customer_complaint' => 'Baterai unit pengganti drop',
                'icloud_signout_verified' => true,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 50000.00,
            ]);

        $secondExchangeResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        // Verify state transitions after 2nd exchange
        $this->newAssetSame->refresh();
        $this->assertEquals('maintenance', $this->newAssetSame->status);

        $this->newAssetUpgrade->refresh();
        $this->assertEquals('rented', $this->newAssetUpgrade->status);

        // Verify active item is now newAssetUpgrade
        $this->booking->refresh();
        $this->assertEquals($this->newAssetUpgrade->id, $this->booking->active_asset->id);
        $this->assertCount(2, $this->booking->exchanges);
    }

    public function test_is_asset_available_ignores_exchanged_booking_items(): void
    {
        $pricingService = app(\App\Services\PricingService::class);

        // 1. Perform exchange: oldAsset -> newAssetSame
        $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", [
                'old_asset_id' => $this->oldAsset->id,
                'new_asset_id' => $this->newAssetSame->id,
                'reported_issue_category' => 'camera',
                'customer_complaint' => 'Kamera getar',
                'icloud_signout_verified' => true,
            ])
            ->assertStatus(200);

        // Simulate oldAsset repaired and returned to 'ready'
        $this->oldAsset->update(['status' => 'ready']);

        // Check availability of oldAsset during the active booking's time window:
        // It must be available because its item on that booking is status 'exchanged'!
        $isAvailable = $pricingService->isAssetAvailable(
            $this->oldAsset,
            Carbon::parse($this->booking->start_at),
            Carbon::parse($this->booking->end_at)
        );

        $this->assertTrue($isAvailable, 'Repaired device must not falsely collide with its historical exchanged booking item');
    }

    public function test_return_process_only_restores_active_units_and_keeps_quarantined_units_in_maintenance(): void
    {
        // 1. Perform exchange
        $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/exchange", [
                'old_asset_id' => $this->oldAsset->id,
                'new_asset_id' => $this->newAssetSame->id,
                'reported_issue_category' => 'camera',
                'customer_complaint' => 'Kamera getar',
                'icloud_signout_verified' => true,
            ])
            ->assertStatus(200);

        // 2. Customer finishes rental and returns the replacement unit
        $this->actingAs($this->cashier, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->business->id,
                'X-Branch-Id' => (string)$this->branch->id,
            ])
            ->postJson("/api/v1/bookings/{$this->booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'icloud_removed' => true,
                ],
                'accessories_returned' => ['cable' => true, 'adapter' => true],
            ])
            ->assertStatus(200);

        // Replacement unit must be restored to 'ready'
        $this->newAssetSame->refresh();
        $this->assertEquals('ready', $this->newAssetSame->status);

        // Defective quarantined unit must REMAIN in 'maintenance'
        $this->oldAsset->refresh();
        $this->assertEquals('maintenance', $this->oldAsset->status);
    }
}
