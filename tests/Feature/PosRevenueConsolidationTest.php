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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosRevenueConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Business $businessB;
    protected Affiliate $mainBranchA;
    protected Affiliate $branchA2;
    protected Affiliate $branchB1;
    protected User $ownerA;
    protected User $mainStaffA;
    protected User $branchStaffA2;
    protected User $ownerB;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $qrisMethod;
    protected PaymentMethod $transferMethod;
    protected Customer $customerA;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Owners
        $this->ownerA = User::create([
            'name' => 'Owner Business A',
            'email' => 'owner.a@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->ownerB = User::create([
            'name' => 'Owner Business B',
            'email' => 'owner.b@competitor.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 2. Setup Businesses
        $this->businessA = Business::create([
            'name' => 'RentCore Central Business',
            'slug' => 'rentcore-central-business',
            'owner_id' => $this->ownerA->id,
            'email' => 'owner@rentcore.id',
            'phone' => '08111111111',
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'Competitor Business B',
            'slug' => 'competitor-b',
            'owner_id' => $this->ownerB->id,
            'email' => 'owner@competitor.id',
            'phone' => '08222222222',
            'is_active' => true,
        ]);

        // 3. Setup Branches
        $this->mainBranchA = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'PST',
            'name' => 'RentCore Pusat (Main Store)',
            'slug' => 'rentcore-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'BRT',
            'name' => 'RentCore Cabang Barat',
            'slug' => 'rentcore-cabang-barat',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->branchB1 = Affiliate::create([
            'business_id' => $this->businessB->id,
            'code' => 'CMP',
            'name' => 'Competitor Main Branch',
            'slug' => 'competitor-main',
            'is_central' => true,
            'is_active' => true,
        ]);

        // Update owner affiliate assignments
        $this->ownerA->update([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
        ]);
        $this->ownerB->update([
            'business_id' => $this->businessB->id,
            'affiliate_id' => $this->branchB1->id,
        ]);

        // 4. Setup Staff Users for Business A
        $this->mainStaffA = User::create([
            'business_id' => $this->businessA->id,
            'name' => 'Kasir Main Store',
            'email' => 'kasir.main@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->mainBranchA->id,
            'is_active' => true,
        ]);

        $this->branchStaffA2 = User::create([
            'business_id' => $this->businessA->id,
            'name' => 'Kasir Cabang Barat',
            'email' => 'kasir.barat@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA2->id,
            'is_active' => true,
        ]);

        // 5. Setup Memberships
        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->mainStaffA->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->branchStaffA2->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
        BusinessMember::create([
            'business_id' => $this->businessB->id,
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 6. Payment Methods
        $this->cashMethod = PaymentMethod::create(['code' => 'CASH', 'name' => 'Tunai', 'is_active' => true]);
        $this->qrisMethod = PaymentMethod::create(['code' => 'QRIS', 'name' => 'QRIS', 'is_active' => true]);
        $this->transferMethod = PaymentMethod::create(['code' => 'TRANSFER', 'name' => 'Transfer Bank', 'is_active' => true]);

        // 7. Customers
        $this->customerA = Customer::create([
            'business_id' => $this->businessA->id,
            'name' => 'John Doe',
            'phone' => '081234567890',
        ]);
    }

    public function test_pos_payment_records_business_id_and_updates_closing_summary(): void
    {
        $booking = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-PAY-001',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 450000,
            'total_bill' => 450000,
            'payment_status' => 'unpaid',
            'collateral_type' => 'KTP',
            'collateral_secured' => true,
        ]);

        Sanctum::actingAs($this->mainStaffA);

        $response = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->withHeader('X-Branch-Id', (string)$this->mainBranchA->id)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $booking->id,
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 450000,
                'cash_received' => 500000,
                'notes' => 'Pembayaran lunas via POS',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.payment.booking_id', $booking->id)
            ->assertJsonPath('data.cash_change', 50000);

        // Verify database persistence has business_id populated
        $tx = PaymentTransaction::where('booking_id', $booking->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals($this->businessA->id, $tx->business_id);
        $this->assertEquals(50000, $tx->cash_change);

        // Verify closing summary includes this transaction under business_id tenant scope
        $summaryResp = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $summaryResp->assertOk()
            ->assertJsonPath('data.total_revenue', 450000)
            ->assertJsonPath('data.total_transactions', 1);
    }

    public function test_main_store_and_owner_can_see_consolidated_revenue_with_branch_breakdown(): void
    {
        // Transaction at Main Store (PST): Rp 600,000 (Tunai)
        $b1 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-PST-01',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 600000,
            'total_bill' => 600000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-PST-01',
            'receipt_number' => 'REC-PST-01',
            'booking_id' => $b1->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 600000,
            'cash_received' => 600000,
            'cash_change' => 0,
            'transacted_at' => Carbon::now(),
        ]);

        // Transaction at Branch Barat (BRT): Rp 400,000 (QRIS)
        $b2 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-BRT-01',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->branchStaffA2->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 400000,
            'total_bill' => 400000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-BRT-01',
            'receipt_number' => 'REC-BRT-01',
            'booking_id' => $b2->id,
            'payment_method_id' => $this->qrisMethod->id,
            'processed_by' => $this->branchStaffA2->id,
            'type' => 'settlement',
            'amount' => 400000,
            'cash_received' => 400000,
            'cash_change' => 0,
            'transacted_at' => Carbon::now(),
        ]);

        // 1. Owner requests consolidated closing summary (no branch_id)
        Sanctum::actingAs($this->ownerA);
        $respOwner = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $respOwner->assertOk()
            ->assertJsonPath('data.is_consolidated', true)
            ->assertJsonPath('data.total_revenue', 1000000)
            ->assertJsonPath('data.total_transactions', 2);

        $breakdownByBranch = $respOwner->json('data.breakdown_by_branch');
        $this->assertCount(2, $breakdownByBranch);

        $mainBranchStat = collect($breakdownByBranch)->firstWhere('branch_id', $this->mainBranchA->id);
        $this->assertNotNull($mainBranchStat);
        $this->assertEquals(600000, $mainBranchStat['total_revenue']);
        $this->assertEquals(600000, $mainBranchStat['cash_revenue']);
        $this->assertEquals(0, $mainBranchStat['non_cash_revenue']);

        $westBranchStat = collect($breakdownByBranch)->firstWhere('branch_id', $this->branchA2->id);
        $this->assertNotNull($westBranchStat);
        $this->assertEquals(400000, $westBranchStat['total_revenue']);
        $this->assertEquals(0, $westBranchStat['cash_revenue']);
        $this->assertEquals(400000, $westBranchStat['non_cash_revenue']);

        // 2. Main Store Staff also can access consolidated view
        Sanctum::actingAs($this->mainStaffA);
        $respMainStaff = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $respMainStaff->assertOk()
            ->assertJsonPath('data.is_consolidated', true)
            ->assertJsonPath('data.total_revenue', 1000000);

        // 3. Satellite Branch Staff is restricted to their branch only
        Sanctum::actingAs($this->branchStaffA2);
        $respBranchStaff = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $respBranchStaff->assertOk()
            ->assertJsonPath('data.is_consolidated', false)
            ->assertJsonPath('data.branch_id', $this->branchA2->id)
            ->assertJsonPath('data.total_revenue', 400000)
            ->assertJsonPath('data.total_transactions', 1);

        // Satellite branch staff attempting to query Main Store receives 403
        $respForbidden = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->mainBranchA->id}");

        $respForbidden->assertStatus(403)
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
    }

    public function test_closing_summary_supports_custom_date_range(): void
    {
        // Transaction yesterday: Rp 300,000
        $yesterday = Carbon::yesterday()->setHour(12);
        $bOld = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-OLD-01',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => $yesterday,
            'end_at' => (clone $yesterday)->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000,
            'total_bill' => 300000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-OLD-01',
            'receipt_number' => 'REC-OLD-01',
            'booking_id' => $bOld->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 300000,
            'transacted_at' => $yesterday,
        ]);

        // Transaction today: Rp 500,000
        $today = Carbon::now();
        $bNew = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-NEW-01',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => $today,
            'end_at' => (clone $today)->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 500000,
            'total_bill' => 500000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-NEW-01',
            'receipt_number' => 'REC-NEW-01',
            'booking_id' => $bNew->id,
            'payment_method_id' => $this->transferMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 500000,
            'transacted_at' => $today,
        ]);

        Sanctum::actingAs($this->ownerA);

        // 1. Query today only (default)
        $todayResp = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');
        $todayResp->assertOk()
            ->assertJsonPath('data.total_revenue', 500000)
            ->assertJsonPath('data.total_transactions', 1);

        // 2. Query date range covering both yesterday and today
        $rangeResp = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?' . http_build_query([
                'start_date' => Carbon::yesterday()->toDateString(),
                'end_date' => Carbon::today()->toDateString(),
            ]));

        $rangeResp->assertOk()
            ->assertJsonPath('data.total_revenue', 800000)
            ->assertJsonPath('data.total_transactions', 2);
    }

    public function test_booking_controller_index_filters_by_branch_id_with_isolation(): void
    {
        $b1 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-INDEX-PST',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 200000,
            'total_bill' => 200000,
            'payment_status' => 'paid',
        ]);

        $b2 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-INDEX-BRT',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->branchStaffA2->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000,
            'total_bill' => 300000,
            'payment_status' => 'paid',
        ]);

        // 1. Owner filters bookings for Branch Barat
        Sanctum::actingAs($this->ownerA);
        $resp = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/bookings?branch_id={$this->branchA2->id}");

        $resp->assertOk();
        $codes = collect($resp->json('data.data'))->pluck('booking_code');
        $this->assertContains('BK-INDEX-BRT', $codes);
        $this->assertNotContains('BK-INDEX-PST', $codes);

        // 2. Branch Barat Staff cannot access Main Store bookings
        Sanctum::actingAs($this->branchStaffA2);
        $respForbidden = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/bookings?branch_id={$this->mainBranchA->id}");

        $respForbidden->assertStatus(403);
    }

    public function test_unrelated_business_cannot_access_closing_summary(): void
    {
        Sanctum::actingAs($this->ownerB);

        // Owner B passes Business A's branch ID
        $resp = $this->withHeader('X-Business-Id', (string)$this->businessB->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->mainBranchA->id}");

        $resp->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_BRANCH');
    }
}
