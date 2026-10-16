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

class LateReturnPenaltyRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Business $businessB;
    protected Affiliate $branchA1;
    protected Affiliate $branchA2;
    protected Affiliate $branchB1;
    protected User $ownerA;
    protected User $staffA1;
    protected User $staffA2;
    protected User $staffB1;
    protected AssetModel $model;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $qrisMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Owners
        $this->ownerA = User::create([
            'name' => 'Owner Bisnis A',
            'email' => 'owner.a@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $ownerB = User::create([
            'name' => 'Owner Bisnis B',
            'email' => 'owner.b@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 2. Business A & B
        $this->businessA = Business::create([
            'name' => 'Bisnis Rental A',
            'slug' => 'bisnis-rental-a',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'Bisnis Rental B',
            'slug' => 'bisnis-rental-b',
            'owner_id' => $ownerB->id,
            'is_active' => true,
        ]);

        $this->branchA1 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'PST',
            'name' => 'Cabang Pusat A',
            'slug' => 'cabang-pusat-a',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'BRT',
            'name' => 'Cabang Barat A',
            'slug' => 'cabang-barat-a',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->ownerA->update([
            'affiliate_id' => $this->branchA1->id,
            'business_id' => $this->businessA->id,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->staffA1 = User::create([
            'name' => 'Staff Cabang Pusat A',
            'email' => 'staff.a1@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA1->id,
            'business_id' => $this->businessA->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA1->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->staffA2 = User::create([
            'name' => 'Staff Cabang Barat A',
            'email' => 'staff.a2@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA2->id,
            'business_id' => $this->businessA->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA2->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        // 3. Business B Branches & Users

        $this->branchB1 = Affiliate::create([
            'business_id' => $this->businessB->id,
            'code' => 'BB1',
            'name' => 'Cabang B1',
            'slug' => 'cabang-b1',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->staffB1 = User::create([
            'name' => 'Staff Bisnis B',
            'email' => 'staff.b1@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchB1->id,
            'business_id' => $this->businessB->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessB->id,
            'user_id' => $this->staffB1->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        // 3. Asset & Rate Setup
        $cat = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones-a',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'category_id' => $cat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro 256GB',
            'slug' => 'iphone-15-pro-256gb',
            'storage_capacity' => '256GB',
            'color' => 'Black',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'price' => 300000.00,
            'extension_hourly_rate' => 20000.00, // 20k per hour for penalty
        ]);

        // 4. Payment Methods
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
    }

    private function createRentedBooking(Affiliate $branch, User $creator, Carbon $startAt, Carbon $endAt): array
    {
        $customer = Customer::create([
            'business_id' => $branch->business_id,
            'name' => 'Customer ' . uniqid(),
            'phone' => '0812' . rand(10000000, 99999999),
        ]);

        $asset = Asset::create([
            'business_id' => $branch->business_id,
            'affiliate_id' => $branch->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-' . uniqid(),
            'serial_number' => 'SN-' . uniqid(),
            'status' => 'rented',
            'condition_grade' => 'A',
            'battery_health' => 98,
        ]);

        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $branch->business_id,
            'booking_code' => 'RC-' . uniqid(),
            'affiliate_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $creator->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000.00,
            'total_bill' => 300000.00,
            'payment_status' => 'paid',
            'collateral_secured' => true,
        ]);

        $booking->items()->create([
            'asset_id' => $asset->id,
            'unit_price' => 300000.00,
        ]);

        PaymentTransaction::create([
            'business_id' => $branch->business_id,
            'transaction_code' => 'TX-INIT-' . uniqid(),
            'receipt_number' => 'REC-INIT-' . uniqid(),
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $creator->id,
            'type' => 'settlement',
            'amount' => 300000.00,
            'cash_received' => 300000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now()->subHours(24),
        ]);

        return [$booking, $asset];
    }

    public function test_01_on_time_return_generates_zero_penalty_and_no_penalty_revenue(): void
    {
        [$booking, $asset] = $this->createRentedBooking(
            $this->branchA1,
            $this->staffA1,
            Carbon::now()->subHours(24),
            Carbon::now() // On time
        );

        $response = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.late_minutes', 0)
            ->assertJsonPath('data.late_fee', '0.00')
            ->assertJsonPath('data.total_penalty', '0.00');

        $this->assertEquals(0, PaymentTransaction::where('booking_id', $booking->id)->where('type', 'penalty')->count());
        $this->assertEquals('paid', $booking->fresh()->payment_status);
        $this->assertEquals('returned', $booking->fresh()->status);
        $this->assertEquals('ready', $asset->fresh()->status);
    }

    public function test_02_late_return_within_grace_period_generates_zero_penalty(): void
    {
        [$booking] = $this->createRentedBooking(
            $this->branchA1,
            $this->staffA1,
            Carbon::now()->subHours(24),
            Carbon::now()->subMinutes(45) // 45m late (under 60m grace period)
        );

        $response = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.late_fee', '0.00')
            ->assertJsonPath('data.total_penalty', '0.00');

        $this->assertEquals(0, PaymentTransaction::where('booking_id', $booking->id)->where('type', 'penalty')->count());
    }

    public function test_03_late_return_with_payment_records_penalty_revenue_and_attributes_to_branch(): void
    {
        // 2 hours 15 minutes late -> (135 - 60) = 75 billable minutes -> ceil(75/60) = 2 hours -> 2 * 20,000 = 40,000 IDR
        [$booking] = $this->createRentedBooking(
            $this->branchA1,
            $this->staffA1,
            Carbon::now()->subHours(26),
            Carbon::now()->subMinutes(135)
        );

        $response = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'penalty_payment_method_id' => $this->cashMethod->id,
                'cash_received' => 50000.00,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.late_fee', '40000.00')
            ->assertJsonPath('data.total_penalty', '40000.00');

        // Verify PaymentTransaction created
        $penaltyTx = PaymentTransaction::where('booking_id', $booking->id)
            ->where('type', 'penalty')
            ->first();

        $this->assertNotNull($penaltyTx);
        $this->assertEquals(40000.00, (float)$penaltyTx->amount);
        $this->assertEquals(50000.00, (float)$penaltyTx->cash_received);
        $this->assertEquals(10000.00, (float)$penaltyTx->cash_change);
        $this->assertEquals($this->businessA->id, $penaltyTx->business_id);
        $this->assertEquals('paid', $booking->fresh()->payment_status);

        // Verify Closing Summary includes penalty revenue and type breakdown
        $closingResp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $closingResp->assertOk()
            ->assertJsonPath('data.breakdown_by_type.penalty.total', 40000)
            ->assertJsonPath('data.breakdown_by_type.penalty.count', 1);
    }

    public function test_04_branch_a_and_branch_b_penalties_are_correctly_attributed(): void
    {
        // Branch A1 booking penalty: 40,000
        [$bookingA1] = $this->createRentedBooking(
            $this->branchA1,
            $this->staffA1,
            Carbon::now()->subHours(26),
            Carbon::now()->subMinutes(135)
        );

        $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$bookingA1->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'penalty_payment_method_id' => $this->cashMethod->id,
            ])->assertOk();

        // Branch A2 booking penalty: 40,000
        [$bookingA2] = $this->createRentedBooking(
            $this->branchA2,
            $this->staffA2,
            Carbon::now()->subHours(26),
            Carbon::now()->subMinutes(135)
        );

        $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$bookingA2->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'penalty_payment_method_id' => $this->cashMethod->id,
            ])->assertOk();

        // 1. Branch A1 staff closing summary sees only Branch A1's penalty
        $respA1 = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branchA1->id);

        $respA1->assertOk()
            ->assertJsonPath('data.breakdown_by_type.penalty.total', 40000);

        // 2. Branch A2 staff closing summary sees only Branch A2's penalty
        $respA2 = $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branchA2->id);

        $respA2->assertOk()
            ->assertJsonPath('data.breakdown_by_type.penalty.total', 40000);

        // 3. Owner of Business A sees consolidated (40k + 40k = 80k penalty)
        $respOwner = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?all_branches=1');

        $respOwner->assertOk()
            ->assertJsonPath('data.breakdown_by_type.penalty.total', 80000)
            ->assertJsonPath('data.breakdown_by_type.penalty.count', 2);
    }

    public function test_05_unpaid_late_return_sets_partial_status_and_is_payable_in_cashier_pos(): void
    {
        [$booking] = $this->createRentedBooking(
            $this->branchA1,
            $this->staffA1,
            Carbon::now()->subHours(26),
            Carbon::now()->subMinutes(135)
        );

        // Return without payment method
        $returnResp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
            ]);

        $returnResp->assertOk();
        $this->assertEquals('partial', $booking->fresh()->payment_status);
        $this->assertEquals(0, PaymentTransaction::where('booking_id', $booking->id)->where('type', 'penalty')->count());

        // Process penalty payment via Cashier POS endpoint
        $cashierPayResp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $booking->id,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'penalty',
                'amount' => 40000.00,
                'cash_received' => 40000.00,
            ]);

        $cashierPayResp->assertStatus(201)
            ->assertJsonPath('data.payment.type', 'penalty')
            ->assertJsonPath('data.payment.amount', '40000.00');

        $this->assertEquals('paid', $booking->fresh()->payment_status);
    }

    public function test_06_repeating_return_request_does_not_duplicate_penalty_revenue(): void
    {
        [$booking] = $this->createRentedBooking(
            $this->branchA1,
            $this->staffA1,
            Carbon::now()->subHours(26),
            Carbon::now()->subMinutes(135)
        );

        // First return call succeeds
        $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'penalty_payment_method_id' => $this->cashMethod->id,
            ])->assertOk();

        $this->assertEquals(1, PaymentTransaction::where('booking_id', $booking->id)->where('type', 'penalty')->count());

        // Second return call is rejected (booking status is already returned)
        $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'penalty_payment_method_id' => $this->cashMethod->id,
            ])->assertStatus(422);

        // Still only 1 penalty transaction
        $this->assertEquals(1, PaymentTransaction::where('booking_id', $booking->id)->where('type', 'penalty')->count());
    }

    public function test_07_historical_branch_attribution_persists_after_asset_transfer(): void
    {
        [$booking, $asset] = $this->createRentedBooking(
            $this->branchA1,
            $this->staffA1,
            Carbon::now()->subHours(26),
            Carbon::now()->subMinutes(135)
        );

        // Return and record penalty
        $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'penalty_payment_method_id' => $this->cashMethod->id,
            ])->assertOk();

        // Physically transfer asset to Branch A2
        $asset->update(['affiliate_id' => $this->branchA2->id]);

        // Branch A1 closing summary still has the penalty revenue
        $respA1 = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branchA1->id);

        $respA1->assertOk()
            ->assertJsonPath('data.breakdown_by_type.penalty.total', 40000);

        // Branch A2 closing summary does NOT have this penalty revenue
        $respA2 = $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branchA2->id);

        $respA2->assertOk()
            ->assertJsonPath('data.breakdown_by_type.penalty.total', 0);
    }

    public function test_08_cross_tenant_isolation_prevents_viewing_penalty_revenue(): void
    {
        [$booking] = $this->createRentedBooking(
            $this->branchA1,
            $this->staffA1,
            Carbon::now()->subHours(26),
            Carbon::now()->subMinutes(135)
        );

        $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'penalty_payment_method_id' => $this->cashMethod->id,
            ])->assertOk();

        // Staff from Business B requests closing summary
        $respB = $this->actingAs($this->staffB1)
            ->withHeader('X-Business-Id', (string)$this->businessB->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $respB->assertOk()
            ->assertJsonPath('data.total_revenue', 0)
            ->assertJsonPath('data.breakdown_by_type.penalty.total', 0);
    }
}
