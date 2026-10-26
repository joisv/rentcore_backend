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

class DirectCounterRentalTest extends TestCase
{
    use RefreshDatabase;

    protected Affiliate $affiliate;
    protected User $cashier;
    protected AssetModel $model;
    protected Asset $asset;
    protected PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');

        $this->affiliate = Affiliate::create([
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

        $cat = AssetCategory::create([
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'category_id' => $cat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro 256GB',
            'slug' => 'iphone-15-pro-256gb',
            'storage_capacity' => '256GB',
            'color' => 'Titanium',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'price' => 350000.00,
            'extension_hourly_rate' => 15000.00,
        ]);

        $this->asset = Asset::create([
            'affiliate_id' => $this->affiliate->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-IP15P-001',
            'serial_number' => 'SN-IP15P-TEST',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 99,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->customer = \App\Models\Customer::create([
            'name' => 'Test Customer',
            'phone' => '081234567890',
        ]);
    }

    public function test_direct_counter_rental_creates_active_rental_and_secures_collateral(): void
    {
        $token = $this->cashier->createToken('Test')->plainTextToken;

        $payload = [
            'customer_name' => 'Rina Wijaya',
            'customer_phone' => '081299887766',
            'identity_type' => 'KTP',
            'identity_number' => '3510099887760001',
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'deposit_fee' => 0.00,
            'payment_method_id' => $this->paymentMethod->id,
            'collateral_type' => 'KTP Asli',
            'collateral_storage_slot' => 'Brankas A, Slot 12',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'rented')
            ->assertJsonPath('data.collateral_secured', true)
            ->assertJsonPath('data.collateral_storage_slot', 'Brankas A, Slot 12');

        // Asset must immediately transition to rented
        $this->assertEquals('rented', $this->asset->fresh()->status);

        // Single Source of Truth Payment transaction and receipt must exist
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $response->json('data.id'),
            'type' => 'settlement',
            'amount' => 350000.00,
        ]);

        // Immutable Audit Log must be created
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booking.created',
            'entity_type' => 'Booking',
        ]);
    }

    public function test_return_fails_if_icloud_guard_is_not_passed(): void
    {
        $token = $this->cashier->createToken('Test')->plainTextToken;

        // Create rental first
        $booking = $this->cashier->bookingsCreated()->create([
            'booking_code' => 'RC-TEST-001',
            'affiliate_id' => $this->affiliate->id,
            'customer_id' => $this->customer->id,
            'start_at' => Carbon::now()->subHours(24),
            'end_at' => Carbon::now(),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 350000.00,
            'total_bill' => 350000.00,
            'collateral_secured' => true,
        ]);

        $this->asset->update(['status' => 'rented']);

        // Attempt return without icloud_removed check
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'icloud_removed' => false, // Violates security guard
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['checklist']);
    }

    public function test_return_succeeds_and_restores_asset_to_ready(): void
    {
        $token = $this->cashier->createToken('Test')->plainTextToken;

        $booking = $this->cashier->bookingsCreated()->create([
            'booking_code' => 'RC-TEST-002',
            'affiliate_id' => $this->affiliate->id,
            'customer_id' => $this->customer->id,
            'start_at' => Carbon::now()->subHours(25),
            'end_at' => Carbon::now()->subHour(), // Returned 1 hour late (within 60m grace period)
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 350000.00,
            'total_bill' => 350000.00,
            'collateral_secured' => true,
        ]);

        $booking->items()->create([
            'asset_id' => $this->asset->id,
            'unit_price' => 350000.00,
        ]);

        $this->asset->update(['status' => 'rented']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'icloud_removed' => true,
                    'screen_ok' => true,
                ],
                'accessories_returned' => [
                    'cable' => true,
                    'adapter' => true,
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Booking status must be returned
        $this->assertEquals('returned', $booking->fresh()->status);

        // Asset status must be restored to ready
        $this->assertEquals('ready', $this->asset->fresh()->status);
    }

    public function test_direct_counter_rental_with_identity_photo_attaches_document(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $token = $this->cashier->createToken('test')->plainTextToken;

        $fakeBase64 = 'data:image/jpeg;base64,' . base64_encode('sample-encrypted-id-card-content');

        $payload = [
            'customer_name' => 'Rina Nose',
            'customer_phone' => '087788990011',
            'customer_email' => 'rina@example.com',
            'identity_type' => 'KTP',
            'identity_number' => '3271009988776655',
            'identity_photo_base64' => $fakeBase64,
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'collateral_type' => 'KTP Asli',
            'collateral_storage_slot' => 'SLOT-KTP-99',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('customer_identities', [
            'identity_number' => '3271009988776655',
        ]);

        $identity = \App\Models\CustomerIdentity::where('identity_number', '3271009988776655')->first();
        $this->assertNotNull($identity->file_path);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($identity->file_path);
    }
}
