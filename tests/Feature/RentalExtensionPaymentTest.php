<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RentalExtensionPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $cashier;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $qrisMethod;
    protected AssetModel $model;
    protected Asset $asset;
    protected Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::create([
            'name' => 'Kasir Perpanjangan',
            'email' => 'cashier.ext@rentcore.id',
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
            'code' => 'MTR',
            'name' => 'Mataram Branch',
            'slug' => 'mataram-branch',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->cashier->update([
            'affiliate_id' => $this->branch->id,
            'business_id' => $this->business->id,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->cashier->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->qrisMethod = PaymentMethod::create([
            'code' => 'QRIS',
            'name' => 'QRIS',
            'is_active' => true,
        ]);

        $cat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Phones',
            'slug' => 'phones',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'category_id' => $cat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro',
            'storage_capacity' => '256GB',
            'color' => 'Black',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'price' => 300000.00,
            'extension_hourly_rate' => 20000.00, // Rp 20.000 / hr
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-IP15-01',
            'serial_number' => 'SN-IP15-01',
            'status' => 'rented',
            'condition_grade' => 'A',
            'battery_health' => 100,
        ]);

        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Doni Pratama',
            'phone' => '081234567890',
        ]);

        $startAt = Carbon::now()->subHours(12);
        $endAt = Carbon::now()->addHours(12);

        $this->booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'booking_code' => 'RC-EXT-0001',
            'affiliate_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'created_by' => $this->cashier->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000.00,
            'total_bill' => 300000.00,
            'payment_status' => 'paid',
            'collateral_secured' => true,
        ]);

        $this->booking->items()->create([
            'asset_id' => $this->asset->id,
            'unit_price' => 300000.00,
        ]);

        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'transaction_code' => 'TX-INIT-001',
            'receipt_number' => 'REC-INIT-001',
            'booking_id' => $this->booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->cashier->id,
            'type' => 'settlement',
            'amount' => 300000.00,
            'cash_received' => 300000.00,
            'cash_change' => 0.00,
            'transacted_at' => $startAt,
        ]);
    }

    public function test_extension_with_immediate_cash_payment_succeeds_and_records_transaction(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        // Extend 6 hours @ 20,000 = 120,000 IDR with 150,000 cash received (30,000 change)
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/bookings/{$this->booking->id}/extend", [
                'additional_hours' => 6,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 150000.00,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.extension.additional_hours', 6)
            ->assertJsonPath('data.extension.additional_fee', '120000.00')
            ->assertJsonPath('data.payment.type', 'extension')
            ->assertJsonPath('data.payment.amount', '120000.00')
            ->assertJsonPath('data.payment.cash_received', '150000.00')
            ->assertJsonPath('data.payment.cash_change', '30000.00')
            ->assertJsonPath('data.booking.payment_status', 'paid');

        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $this->booking->id,
            'type' => 'extension',
            'amount' => 120000.00,
            'cash_change' => 30000.00,
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $this->booking->id,
            'duration_hours' => 30,
            'total_bill' => 420000.00,
            'payment_status' => 'paid',
        ]);
    }

    public function test_extension_without_payment_marks_partial_status_and_is_payable_at_cashier(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        // Extend 6 hours without payment
        $extResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/bookings/{$this->booking->id}/extend", [
                'additional_hours' => 6,
            ]);

        $extResp->assertStatus(200)
            ->assertJsonPath('data.booking.payment_status', 'partial');

        $this->assertEquals(0, PaymentTransaction::where('booking_id', $this->booking->id)->where('type', 'extension')->count());

        // Now settle the extension balance at Cashier POS
        $cashierResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $this->booking->id,
                'payment_method_id' => $this->qrisMethod->id,
                'payment_type' => 'extension',
                'amount' => 120000.00,
            ]);

        $cashierResp->assertStatus(201)
            ->assertJsonPath('data.payment.type', 'extension')
            ->assertJsonPath('data.payment.amount', '120000.00')
            ->assertJsonPath('data.booking.payment_status', 'paid');

        $this->assertEquals('paid', $this->booking->fresh()->payment_status);
    }

    public function test_closing_summary_tracks_extension_revenue_separately(): void
    {
        $token = $this->cashier->createToken('test')->plainTextToken;

        // Perform extension with immediate payment
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/bookings/{$this->booking->id}/extend", [
                'additional_hours' => 6,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 120000.00,
            ])->assertStatus(200);

        $summaryResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $summaryResp->assertStatus(200)
            ->assertJsonPath('data.breakdown_by_type.extension.total', 120000)
            ->assertJsonPath('data.breakdown_by_type.extension.count', 1);
    }
}
