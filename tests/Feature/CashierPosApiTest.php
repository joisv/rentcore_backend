<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashierPosApiTest extends TestCase
{
    use RefreshDatabase;

    protected Affiliate $affiliateA;
    protected Affiliate $affiliateB;
    protected User $cashierA;
    protected User $cashierB;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $qrisMethod;
    protected AssetModel $model;
    protected Asset $assetA;
    protected Booking $bookingA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliateA = Affiliate::create([
            'code' => 'MTR',
            'name' => 'Mataram Central',
            'slug' => 'mataram-central',
            'is_central' => true,
        ]);

        $this->affiliateB = Affiliate::create([
            'code' => 'DPS',
            'name' => 'Denpasar Branch',
            'slug' => 'denpasar-branch',
            'is_central' => false,
        ]);

        $this->cashierA = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->affiliateA->id,
            'is_active' => true,
        ]);

        $this->cashierB = User::create([
            'name' => 'I Wayan Sudira',
            'email' => 'wayan@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->affiliateB->id,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->qrisMethod = PaymentMethod::create([
            'code' => 'QRIS',
            'name' => 'QRIS Standar',
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
            'color' => 'Natural Titanium',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'price' => 350000.00,
            'extension_hourly_rate' => 15000.00,
        ]);

        $this->assetA = Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-IP15P-MTR-01',
            'serial_number' => 'SN-IP15P-001',
            'status' => 'rented',
            'condition_grade' => 'A',
            'battery_health' => 100,
        ]);

        $customer = Customer::create([
            'name' => 'Ahmad Fauzi',
            'phone' => '081234567890',
            'address' => 'Jl. Pejanggik No. 45, Mataram',
        ]);

        // Create an unpaid booking for Branch A
        $this->bookingA = Booking::create([
            'booking_code' => 'RC-261011-0001',
            'affiliate_id' => $this->affiliateA->id,
            'customer_id' => $customer->id,
            'created_by' => $this->cashierA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 350000.00,
            'deposit_fee' => 0.00,
            'discount_amount' => 0.00,
            'total_bill' => 350000.00,
            'payment_status' => 'unpaid',
            'collateral_type' => 'KTP Asli',
            'collateral_secured' => true,
            'collateral_storage_slot' => 'Brankas A, Slot 01',
        ]);
    }

    public function test_cash_payment_with_exact_amount_succeeds_with_zero_change(): void
    {
        $token = $this->cashierA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $this->bookingA->id,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000.00,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount_due', 350000)
            ->assertJsonPath('data.cash_received', 350000)
            ->assertJsonPath('data.cash_change', 0)
            ->assertJsonPath('data.booking.payment_status', 'paid');

        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $this->bookingA->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 350000.00,
            'cash_received' => 350000.00,
            'cash_change' => 0.00,
            'type' => 'settlement',
        ]);
    }

    public function test_cash_payment_with_excess_amount_returns_correct_change(): void
    {
        $token = $this->cashierA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $this->bookingA->id,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 400000.00, // Rp 400.000 for Rp 350.000 bill
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount_due', 350000)
            ->assertJsonPath('data.cash_received', 400000)
            ->assertJsonPath('data.cash_change', 50000); // Rp 50.000 change
    }

    public function test_cash_payment_with_insufficient_amount_fails_with_validation_error(): void
    {
        $token = $this->cashierA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $this->bookingA->id,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 300000.00, // Rp 300.000 (less than Rp 350.000)
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cash_received']);
    }

    public function test_non_cash_payment_succeeds_without_change_calculation(): void
    {
        $token = $this->cashierA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $this->bookingA->id,
                'payment_method_id' => $this->qrisMethod->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cash_received', 350000)
            ->assertJsonPath('data.cash_change', 0)
            ->assertJsonPath('data.booking.payment_status', 'paid');
    }

    public function test_cannot_pay_already_settled_booking(): void
    {
        $token = $this->cashierA->createToken('test')->plainTextToken;

        // First payment
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $this->bookingA->id,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000.00,
            ])->assertStatus(201);

        // Second payment attempt on same booking
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $this->bookingA->id,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['booking']);
    }

    public function test_cashier_cannot_pay_booking_belonging_to_another_branch(): void
    {
        // Cashier B belongs to Denpasar (Branch B), trying to pay Mataram's booking (Branch A)
        $tokenB = $this->cashierB->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $this->bookingA->id,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000.00,
            ]);

        $response->assertStatus(403);
    }

    public function test_booking_creation_with_customer_address_saves_address(): void
    {
        $token = $this->cashierA->createToken('test')->plainTextToken;

        $newAsset = Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-IP15P-MTR-02',
            'serial_number' => 'SN-IP15P-002',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 98,
        ]);

        $payload = [
            'customer_name' => 'Siti Nurhaliza',
            'customer_phone' => '087765432100',
            'customer_address' => 'Jl. Airlangga No. 12, RT 01/RW 04, Gomong, Mataram 83125',
            'asset_ids' => [$newAsset->id],
            'duration_hours' => 24,
            'deposit_fee' => 0.00,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 350000.00,
            'collateral_type' => 'KTP Asli',
            'collateral_storage_slot' => 'Brankas A, Slot 05',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201);

        $customer = Customer::where('phone', '087765432100')->first();
        $this->assertNotNull($customer);
        $this->assertEquals('Jl. Airlangga No. 12, RT 01/RW 04, Gomong, Mataram 83125', $customer->address);
    }

    public function test_booking_creation_with_insufficient_cash_fails(): void
    {
        $token = $this->cashierA->createToken('test')->plainTextToken;

        $readyAsset = Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-IP15P-MTR-03',
            'serial_number' => 'SN-IP15P-003',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 97,
        ]);

        $payload = [
            'customer_name' => 'Joko Anwar',
            'customer_phone' => '087799881122',
            'customer_address' => 'Jl. Majapahit No. 88, Mataram',
            'asset_ids' => [$readyAsset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 200000.00, // Insufficient: bill is 350000
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cash_received']);
    }
}
