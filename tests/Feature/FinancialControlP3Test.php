<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinancialControlP3Test extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branchMataram;
    protected User $owner;
    protected User $manager;
    protected User $staff;
    protected Customer $customer;
    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner P3',
            'email' => 'owner.p3@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore P3 Test',
            'slug' => 'rentcore-p3-test',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->branchMataram = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'MTR',
            'name' => 'Cabang Mataram',
            'slug' => 'cabang-mataram-p3',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager P3',
            'email' => 'manager.p3@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchMataram->id,
            'business_id' => $this->business->id,
            'manager_override_pin' => password_hash('889900', PASSWORD_BCRYPT),
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Kasir Staff P3',
            'email' => 'staff.p3@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchMataram->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->manager->id,
            'role' => 'admin',
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Budi Pelanggan',
            'phone' => '08123456789',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai / Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);
    }

    protected function makeBooking(string $code, float $totalBill, string $paymentStatus, User $creator): Booking
    {
        return Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchMataram->id,
            'customer_id' => $this->customer->id,
            'created_by' => $creator->id,
            'booking_code' => $code,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'confirmed',
            'subtotal_amount' => $totalBill,
            'total_bill' => $totalBill,
            'payment_status' => $paymentStatus,
        ]);
    }

    public function test_staff_cannot_void_transaction_without_manager_pin(): void
    {
        $booking = $this->makeBooking('BK-P3-001', 200000.00, 'paid', $this->staff);

        $tx = PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-P3-001',
            'receipt_number' => 'REC-P3-001',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staff->id,
            'type' => 'settlement',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 200000.00,
            'cash_received' => 200000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/v1/cashier/transactions/{$tx->id}/void", [
            'reason' => 'Salah input pembayaran',
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Branch-Id' => $this->branchMataram->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['manager_pin']);

        $tx->refresh();
        $this->assertEquals('completed', $tx->status);
    }

    public function test_staff_can_void_with_valid_manager_pin_and_recalculates_booking_status(): void
    {
        $booking = $this->makeBooking('BK-P3-002', 300000.00, 'paid', $this->staff);

        $tx = PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-P3-002',
            'receipt_number' => 'REC-P3-002',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staff->id,
            'type' => 'settlement',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 300000.00,
            'cash_received' => 300000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/v1/cashier/transactions/{$tx->id}/void", [
            'reason' => 'Pelanggan batal bayar dan ingin ganti metode QRIS',
            'manager_pin' => '889900',
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Branch-Id' => $this->branchMataram->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'void')
            ->assertJsonPath('data.void_reason', 'Pelanggan batal bayar dan ingin ganti metode QRIS');

        $tx->refresh();
        $this->assertEquals('void', $tx->status);
        $this->assertEquals($this->manager->id, $tx->void_by);

        $booking->refresh();
        $this->assertEquals('unpaid', $booking->payment_status);
    }

    public function test_owner_can_void_without_manager_pin(): void
    {
        $booking = $this->makeBooking('BK-P3-003', 150000.00, 'paid', $this->owner);

        $tx = PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-P3-003',
            'receipt_number' => 'REC-P3-003',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staff->id,
            'type' => 'settlement',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 150000.00,
            'cash_received' => 150000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->owner)->postJson("/api/v1/cashier/transactions/{$tx->id}/void", [
            'reason' => 'Koreksi owner: duplikasi transaksi fisik',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'void');

        $tx->refresh();
        $this->assertEquals('void', $tx->status);
        $this->assertEquals($this->owner->id, $tx->void_by);
    }

    public function test_payment_idempotency_prevents_duplicate_charge(): void
    {
        $booking = $this->makeBooking('BK-P3-004', 100000.00, 'unpaid', $this->staff);

        $idempotencyKey = 'idem-uuid-9988-7766';

        // First payment request
        $res1 = $this->actingAs($this->staff)->postJson('/api/v1/cashier/pay', [
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 100000.00,
            'cash_received' => 100000.00,
            'payment_type' => 'settlement',
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Branch-Id' => $this->branchMataram->id,
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        $res1->assertStatus(201);
        $txId1 = $res1->json('data.payment.id');

        // Repeated payment request with same idempotency key
        $res2 = $this->actingAs($this->staff)->postJson('/api/v1/cashier/pay', [
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 100000.00,
            'cash_received' => 100000.00,
            'payment_type' => 'settlement',
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Branch-Id' => $this->branchMataram->id,
            'X-Idempotency-Key' => $idempotencyKey,
        ]);

        $res2->assertStatus(200)
            ->assertJsonPath('data.is_idempotent', true)
            ->assertJsonPath('data.transaction_id', $txId1);

        // Ensure only 1 record exists in DB
        $count = PaymentTransaction::where('booking_id', $booking->id)->count();
        $this->assertEquals(1, $count);
    }
}
