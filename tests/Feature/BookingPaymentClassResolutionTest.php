<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BookingPaymentClassResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $cashier;
    protected AssetModel $model;
    protected Asset $asset;
    protected PaymentMethod $cashMethod;
    protected CashDrawerShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create([
            'name' => 'Owner Test',
            'email' => 'owner.test@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Test Business',
            'slug' => 'rentcore-test-biz',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'name' => 'Cabang Test Denpasar',
            'code' => 'DPS',
            'slug' => 'cabang-test-denpasar',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'business_id' => $this->business->id,
            'name' => 'Kasir Test',
            'email' => 'kasir.test@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branch->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->cashier->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 13 128GB',
            'slug' => 'iphone-13-128gb',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'price' => 150000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-IP13-001',
            'serial_number' => 'SN-IP13-001',
            'status' => 'ready',
            'condition_grade' => 'A',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_number' => 'SHIFT-TEST-001',
            'opened_by' => $this->cashier->id,
            'starting_cash' => 500000,
            'opened_at' => Carbon::now()->subHour(),
            'status' => 'open',
        ]);
    }

    public function test_business_class_autoloading_and_namespace_integrity(): void
    {
        $this->assertTrue(class_exists(\App\Models\Business::class));
        $this->assertFalse(class_exists('App\Services\Business'));
        $this->assertFalse(class_exists('App\Service\Bussines'));
    }

    public function test_booking_payment_creates_booking_and_transaction_without_class_not_found_error(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Branch-Id' => (string)$this->branch->id,
        ])->postJson('/api/v1/bookings', [
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Budi Pelanggan',
            'customer_phone' => '081234567890',
            'customer_address' => 'Jl. Sunset Road No. 10',
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 200000,
            'collateral_type' => 'KTP Asli',
            'collateral_storage_slot' => 'Slot A1',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $bookingData = $response->json('data');
        $this->assertNotNull($bookingData['id']);
        $this->assertEquals('rented', $bookingData['status']);
        $this->assertEquals('paid', $bookingData['payment_status']);
        $this->assertEquals($this->business->id, $bookingData['business_id']);
        $this->assertEquals($this->branch->id, $bookingData['affiliate_id']);

        // Verify Payment Transaction was created and linked to the active shift
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $bookingData['id'],
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'type' => 'settlement',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 150000.00,
            'cash_received' => 200000.00,
            'cash_change' => 50000.00,
        ]);

        // Verify Asset is marked as rented
        $this->assertEquals('rented', $this->asset->fresh()->status);
    }

    public function test_insufficient_cash_payment_fails_closed_and_rolls_back_cleanly(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Branch-Id' => (string)$this->branch->id,
        ])->postJson('/api/v1/bookings', [
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Budi Kurang Uang',
            'customer_phone' => '081234567891',
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 50000, // Bill is 150000
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cash_received']);

        // Verify NO booking and NO payment transaction created
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertEquals('ready', $this->asset->fresh()->status);
    }

    public function test_disabled_payment_method_is_enforced_and_rolls_back_cleanly(): void
    {
        // Disable CASH payment method on this business
        $this->business->update([
            'payment_methods_config' => ['cash_enabled' => false],
        ]);

        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Branch-Id' => (string)$this->branch->id,
        ])->postJson('/api/v1/bookings', [
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Budi Disabled Method',
            'customer_phone' => '081234567892',
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 200000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method_id']);

        // Verify NO records created
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertEquals('ready', $this->asset->fresh()->status);
    }

    public function test_rental_extension_with_payment_resolves_business_class_cleanly(): void
    {
        // 1. Create active rental first
        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'created_by' => $this->cashier->id,
            'customer_id' => Customer::create([
                'business_id' => $this->business->id,
                'name' => 'Customer Extension',
                'phone' => '081999888777',
            ])->id,
            'booking_code' => 'BK-TEST-EXT-001',
            'status' => 'rented',
            'start_at' => Carbon::now()->subHours(10),
            'end_at' => Carbon::now()->addHours(14),
            'duration_hours' => 24,
            'subtotal_amount' => 150000,
            'total_bill' => 150000,
            'payment_status' => 'paid',
        ]);

        $token = $this->cashier->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Branch-Id' => (string)$this->branch->id,
        ])->postJson("/api/v1/bookings/{$booking->id}/extend", [
            'additional_hours' => 6,
            'additional_fee' => 90000,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 100000,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // Verify extension payment transaction created with shift
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $booking->id,
            'type' => 'extension',
            'amount' => 90000.00,
            'status' => 'completed',
            'shift_id' => $this->shift->id,
        ]);
    }
}
