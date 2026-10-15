<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\InventoryTransfer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\RentalExtension;
use App\Models\ReturnInspection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchRevenueIsolationAndDashboardTest extends TestCase
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
    protected User $ownerB;

    protected AssetCategory $category;
    protected AssetModel $modelIPhone11;
    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Users for Business A & B
        $this->ownerA = User::create([
            'name' => 'Owner Alice',
            'email' => 'owner.alice@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->ownerB = User::create([
            'name' => 'Owner Bob',
            'email' => 'owner.bob@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 2. Setup Businesses
        $this->businessA = Business::create([
            'name' => 'RentCore Business A',
            'slug' => 'rentcore-business-a',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'RentCore Business B',
            'slug' => 'rentcore-business-b',
            'owner_id' => $this->ownerB->id,
            'is_active' => true,
        ]);

        // 3. Setup Memberships
        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessB->id,
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 4. Setup Branches
        $this->branchA1 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'name' => 'Branch A1 (Source)',
            'code' => 'BA1',
            'slug' => 'branch-a1',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'name' => 'Branch A2 (Destination)',
            'code' => 'BA2',
            'slug' => 'branch-a2',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->branchB1 = Affiliate::create([
            'business_id' => $this->businessB->id,
            'name' => 'Foreign Branch B1',
            'code' => 'BB1',
            'slug' => 'foreign-branch-b1',
            'is_central' => true,
            'is_active' => true,
        ]);

        // 5. Setup Staff Members
        $this->staffA1 = User::create([
            'name' => 'Staff A1 User',
            'email' => 'staff.a1@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $memberA1 = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA1->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
        BranchMember::create([
            'business_member_id' => $memberA1->id,
            'affiliate_id' => $this->branchA1->id,
        ]);

        $this->staffA2 = User::create([
            'name' => 'Staff A2 User',
            'email' => 'staff.a2@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $memberA2 = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA2->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
        BranchMember::create([
            'business_member_id' => $memberA2->id,
            'affiliate_id' => $this->branchA2->id,
        ]);

        // 4. Products & Methods
        $this->category = AssetCategory::create([
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_active' => true,
        ]);

        $this->modelIPhone11 = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 11 128GB Purple',
            'slug' => 'iphone-11-purple',
            'storage_capacity' => '128GB',
            'color' => 'Purple',
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'type' => 'cash',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // PART 5: REVENUE ISOLATION TESTS (1 to 9)
    // =========================================================================

    public function test_01_authorized_owner_can_see_revenue_for_branches_in_their_business(): void
    {
        $customer = Customer::create(['business_id' => $this->businessA->id, 'name' => 'Customer A', 'phone' => '0811111111']);

        // Booking & Payment in Branch A1: Rp 300,000
        $bookingA1 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'booking_code' => 'BK-A1-001',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA1->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000,
            'total_bill' => 300000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-A1-001',
            'receipt_number' => 'REC-A1-001',
            'booking_id' => $bookingA1->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'settlement',
            'amount' => 300000,
            'transacted_at' => Carbon::now(),
        ]);

        // Booking & Payment in Branch A2: Rp 500,000
        $bookingA2 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-A2-001',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA2->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 500000,
            'total_bill' => 500000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-A2-001',
            'receipt_number' => 'REC-A2-001',
            'booking_id' => $bookingA2->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA2->id,
            'type' => 'settlement',
            'amount' => 500000,
            'transacted_at' => Carbon::now(),
        ]);

        // Owner views Branch A1 closing summary
        $respA1 = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchA1->id}");

        $respA1->assertOk()
            ->assertJsonPath('data.total_revenue', 300000)
            ->assertJsonPath('data.total_transactions', 1);

        // Owner views Branch A2 closing summary
        $respA2 = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchA2->id}");

        $respA2->assertOk()
            ->assertJsonPath('data.total_revenue', 500000)
            ->assertJsonPath('data.total_transactions', 1);
    }

    public function test_02_owner_can_see_consolidated_revenue_without_double_counting(): void
    {
        $customer = Customer::create(['business_id' => $this->businessA->id, 'name' => 'Customer A', 'phone' => '0811111111']);

        // A1: 200,000 | A2: 300,000 => Consolidated: 500,000
        $b1 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'booking_code' => 'BK-CON-001',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA1->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 200000,
            'total_bill' => 200000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-CON-001',
            'receipt_number' => 'REC-CON-001',
            'booking_id' => $b1->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'settlement',
            'amount' => 200000,
            'transacted_at' => Carbon::now(),
        ]);

        $b2 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-CON-002',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA2->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000,
            'total_bill' => 300000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-CON-002',
            'receipt_number' => 'REC-CON-002',
            'booking_id' => $b2->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA2->id,
            'type' => 'settlement',
            'amount' => 300000,
            'transacted_at' => Carbon::now(),
        ]);

        $resp = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $resp->assertOk()
            ->assertJsonPath('data.total_revenue', 500000)
            ->assertJsonPath('data.total_transactions', 2)
            ->assertJsonPath('data.is_consolidated', true);
    }

    public function test_03_branch_user_can_see_only_their_assigned_branch_revenue(): void
    {
        $customer = Customer::create(['business_id' => $this->businessA->id, 'name' => 'Customer A', 'phone' => '0811111111']);

        // A1: 150,000 | A2: 450,000
        $b1 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'booking_code' => 'BK-STF-001',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA1->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 150000,
            'total_bill' => 150000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-STF-001',
            'receipt_number' => 'REC-STF-001',
            'booking_id' => $b1->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'settlement',
            'amount' => 150000,
            'transacted_at' => Carbon::now(),
        ]);

        $b2 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-STF-002',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA2->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 450000,
            'total_bill' => 450000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-STF-002',
            'receipt_number' => 'REC-STF-002',
            'booking_id' => $b2->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA2->id,
            'type' => 'settlement',
            'amount' => 450000,
            'transacted_at' => Carbon::now(),
        ]);

        // Staff A1 calls closing summary without query param -> automatically scoped to A1 (150,000)
        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $resp->assertOk()
            ->assertJsonPath('data.total_revenue', 150000)
            ->assertJsonPath('data.total_transactions', 1)
            ->assertJsonPath('data.branch_id', $this->branchA1->id);
    }

    public function test_04_branch_user_cannot_retrieve_another_branch_revenue_using_forged_branch_id(): void
    {
        // Staff A1 attempts to request Branch A2 closing summary
        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchA2->id}");

        $resp->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');

        // Staff A1 attempts to request Branch A2 dashboard KPI
        $respKpi = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/dashboard/kpi?branch_id={$this->branchA2->id}");

        $respKpi->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
    }

    public function test_05_user_cannot_retrieve_revenue_from_another_business(): void
    {
        // Owner A attempts to request Business B closing summary
        $resp = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessB->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $resp->assertStatus(403)
            ->assertJsonPath('code', 'BUSINESS_ACCESS_DENIED');
    }

    public function test_06_receipt_detail_endpoint_enforces_same_branch_scope(): void
    {
        $customer = Customer::create(['business_id' => $this->businessA->id, 'name' => 'Cust', 'phone' => '08999']);
        $b2 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-DET-002',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA2->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 250000,
            'total_bill' => 250000,
            'payment_status' => 'paid',
        ]);
        $tx2 = PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-DET-002',
            'receipt_number' => 'REC-DET-002',
            'booking_id' => $b2->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA2->id,
            'type' => 'settlement',
            'amount' => 250000,
            'transacted_at' => Carbon::now(),
        ]);

        // Staff A1 attempts to access receipt from Branch A2 -> rejected 403
        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/transactions/{$tx2->id}/receipt");

        $resp->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_07_historical_revenue_remains_attributed_to_transaction_branch_after_asset_transfer(): void
    {
        $customer = Customer::create(['business_id' => $this->businessA->id, 'name' => 'Cust Hist', 'phone' => '08777']);

        // Asset starts at Branch A1
        $asset = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-HIST-01',
            'serial_number' => 'SN-HIST-01',
            'status' => 'ready',
        ]);

        // Rent at Branch A1: Rp 350,000
        $booking = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'booking_code' => 'BK-HIST-001',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA1->id,
            'start_at' => Carbon::now()->subDays(2),
            'end_at' => Carbon::now()->subDay(),
            'duration_hours' => 24,
            'status' => 'returned',
            'subtotal_amount' => 350000,
            'total_bill' => 350000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-HIST-001',
            'receipt_number' => 'REC-HIST-001',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'settlement',
            'amount' => 350000,
            'transacted_at' => Carbon::today()->midDay(),
        ]);

        // Asset is then transferred and received at Branch A2!
        $transfer = InventoryTransfer::create([
            'business_id' => $this->businessA->id,
            'transfer_code' => 'TRF-HIST-001',
            'asset_id' => $asset->id,
            'from_affiliate_id' => $this->branchA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'sent_by' => $this->staffA1->id,
            'recipient_id' => $this->staffA2->id,
            'status' => 'received',
            'sent_at' => Carbon::now(),
            'received_by' => $this->staffA2->id,
            'received_at' => Carbon::now(),
        ]);
        $asset->update([
            'affiliate_id' => $this->branchA2->id,
            'status' => 'ready',
        ]);

        // Branch A1 closing summary: MUST STILL CONTAIN the 350,000 revenue!
        $respA1 = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchA1->id}");

        $respA1->assertOk()
            ->assertJsonPath('data.total_revenue', 350000)
            ->assertJsonPath('data.total_transactions', 1);

        // Branch A2 closing summary: MUST NOT CONTAIN Branch A1's historical revenue!
        $respA2 = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchA2->id}");

        $respA2->assertOk()
            ->assertJsonPath('data.total_revenue', 0)
            ->assertJsonPath('data.total_transactions', 0);
    }

    public function test_08_all_revenue_types_settlement_dp_penalty_extension_are_consistently_scoped(): void
    {
        $customer = Customer::create(['business_id' => $this->businessA->id, 'name' => 'Cust All Types', 'phone' => '08555']);

        $b = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'booking_code' => 'BK-ALL-001',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA1->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 200000,
            'total_bill' => 280000,
            'payment_status' => 'paid',
        ]);

        // 1. Initial DP: 100,000
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-DP-01',
            'receipt_number' => 'REC-DP-01',
            'booking_id' => $b->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'dp',
            'amount' => 100000,
            'transacted_at' => Carbon::today()->midDay(),
        ]);

        // 2. Settlement: 100,000
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-SET-01',
            'receipt_number' => 'REC-SET-01',
            'booking_id' => $b->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'settlement',
            'amount' => 100000,
            'transacted_at' => Carbon::today()->midDay(),
        ]);

        // 3. Extension fee: 30,000
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-EXT-01',
            'receipt_number' => 'REC-EXT-01',
            'booking_id' => $b->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'extension',
            'amount' => 30000,
            'transacted_at' => Carbon::today()->midDay(),
        ]);

        // 4. Penalty fee: 50,000
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-PEN-01',
            'receipt_number' => 'REC-PEN-01',
            'booking_id' => $b->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'penalty',
            'amount' => 50000,
            'transacted_at' => Carbon::today()->midDay(),
        ]);

        // Total = 100,000 + 100,000 + 30,000 + 50,000 = 280,000
        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $resp->assertOk()
            ->assertJsonPath('data.total_revenue', 280000)
            ->assertJsonPath('data.total_transactions', 4);
    }

    public function test_09_dashboard_revenue_and_detailed_closing_summary_agree_consistently(): void
    {
        $customer = Customer::create(['business_id' => $this->businessA->id, 'name' => 'Cust Agree', 'phone' => '08333']);

        $b = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'booking_code' => 'BK-AGR-001',
            'customer_id' => $customer->id,
            'created_by' => $this->staffA1->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 175000,
            'total_bill' => 175000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-AGR-001',
            'receipt_number' => 'REC-AGR-001',
            'booking_id' => $b->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'settlement',
            'amount' => 175000,
            'transacted_at' => Carbon::today()->midDay(),
        ]);

        // Branch A1 KPI today_revenue
        $kpiResp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $kpiRevenue = $kpiResp->json('data.kpi.today_revenue');
        $kpiTxCount = $kpiResp->json('data.kpi.today_transactions_count');

        // Branch A1 closing summary total_revenue
        $closingResp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary');

        $closingRevenue = $closingResp->json('data.total_revenue');
        $closingTxCount = $closingResp->json('data.total_transactions');

        $this->assertEquals(175000, $kpiRevenue);
        $this->assertEquals($closingRevenue, $kpiRevenue);
        $this->assertEquals(1, $kpiTxCount);
        $this->assertEquals($closingTxCount, $kpiTxCount);
    }

    // =========================================================================
    // PART 5: INVENTORY DASHBOARD TESTS (10 to 16)
    // =========================================================================

    public function test_10_total_fleet_counts_distinct_physical_units(): void
    {
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-A1-01',
            'serial_number' => 'SN-FLEET-01',
            'status' => 'ready',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-A1-02',
            'serial_number' => 'SN-FLEET-02',
            'status' => 'rented',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-A1-03',
            'serial_number' => 'SN-FLEET-03',
            'status' => 'maintenance',
        ]);

        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $resp->assertOk()
            ->assertJsonPath('data.kpi.units_total', 3);
    }

    public function test_11_ready_available_excludes_rented_reserved_maintenance_and_in_transit(): void
    {
        // 1 ready
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-RDY-01',
            'serial_number' => 'SN-RDY-01',
            'status' => 'ready',
        ]);
        // 1 rented
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-RNT-01',
            'serial_number' => 'SN-RNT-01',
            'status' => 'rented',
        ]);
        // 1 maintenance
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-MNT-01',
            'serial_number' => 'SN-MNT-01',
            'status' => 'maintenance',
        ]);
        // 1 in_transit
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-TRN-01',
            'serial_number' => 'SN-TRN-01',
            'status' => 'in_transit',
        ]);
        // 1 reserved
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-RSV-01',
            'serial_number' => 'SN-RSV-01',
            'status' => 'reserved',
        ]);

        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $resp->assertOk()
            ->assertJsonPath('data.kpi.units_total', 5)
            ->assertJsonPath('data.kpi.units_ready', 1)
            ->assertJsonPath('data.kpi.units_rented', 1)
            ->assertJsonPath('data.kpi.units_maintenance', 1)
            ->assertJsonPath('data.kpi.units_in_transit', 1);
    }

    public function test_12_currently_rented_reflects_authoritative_active_rental_state(): void
    {
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-RENT-01',
            'serial_number' => 'SN-RENT-01',
            'status' => 'rented',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-RENT-02',
            'serial_number' => 'SN-RENT-02',
            'status' => 'rented',
        ]);

        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $resp->assertOk()
            ->assertJsonPath('data.kpi.units_rented', 2)
            ->assertJsonPath('data.kpi.active_rentals', 2);
    }

    public function test_13_under_repair_maintenance_reflects_actual_maintenance_state(): void
    {
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-MAINT-01',
            'serial_number' => 'SN-MAINT-01',
            'status' => 'maintenance',
        ]);

        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $resp->assertOk()
            ->assertJsonPath('data.kpi.units_maintenance', 1);
    }

    public function test_14_branch_dashboard_statistics_exclude_units_assigned_to_other_branches(): void
    {
        // 2 units at Branch A1
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-A1-ONLY',
            'serial_number' => 'SN-A1-ONLY',
            'status' => 'ready',
        ]);
        // 3 units at Branch A2
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-A2-ONLY-1',
            'serial_number' => 'SN-A2-ONLY-1',
            'status' => 'ready',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-A2-ONLY-2',
            'serial_number' => 'SN-A2-ONLY-2',
            'status' => 'ready',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-A2-ONLY-3',
            'serial_number' => 'SN-A2-ONLY-3',
            'status' => 'ready',
        ]);

        // Staff A1 queries dashboard -> only sees Branch A1 (1 unit)
        $respA1 = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $respA1->assertOk()
            ->assertJsonPath('data.kpi.units_total', 1)
            ->assertJsonPath('data.kpi.units_ready', 1);

        // Staff A2 queries dashboard -> only sees Branch A2 (3 units)
        $respA2 = $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $respA2->assertOk()
            ->assertJsonPath('data.kpi.units_total', 3)
            ->assertJsonPath('data.kpi.units_ready', 3);
    }

    public function test_15_consolidated_business_statistics_do_not_double_count_inventory(): void
    {
        // 2 at A1, 3 at A2 => Consolidated = 5
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-C1',
            'serial_number' => 'SN-C1',
            'status' => 'ready',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-C2',
            'serial_number' => 'SN-C2',
            'status' => 'rented',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-C3',
            'serial_number' => 'SN-C3',
            'status' => 'ready',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-C4',
            'serial_number' => 'SN-C4',
            'status' => 'maintenance',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-C5',
            'serial_number' => 'SN-C5',
            'status' => 'ready',
        ]);

        $resp = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $resp->assertOk()
            ->assertJsonPath('data.kpi.units_total', 5)
            ->assertJsonPath('data.kpi.units_ready', 3)
            ->assertJsonPath('data.kpi.units_rented', 1)
            ->assertJsonPath('data.kpi.units_maintenance', 1)
            ->assertJsonPath('data.is_consolidated', true);
    }

    public function test_16_unknown_or_retired_statuses_are_not_silently_treated_as_available(): void
    {
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-RET-01',
            'serial_number' => 'SN-RET-01',
            'status' => 'retired',
        ]);
        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-LST-01',
            'serial_number' => 'SN-LST-01',
            'status' => 'lost',
        ]);

        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');

        $resp->assertOk()
            ->assertJsonPath('data.kpi.units_ready', 0)
            ->assertJsonPath('data.kpi.units_total', 0); // Excluded from active fleet
    }

    // =========================================================================
    // PART 5: TRANSFER INTEGRATION TESTS (17 to 25)
    // =========================================================================

    public function test_17_iphone_in_transit_is_not_bookable_at_either_branch(): void
    {
        $asset = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-TRF-01',
            'serial_number' => 'SN-TRF-01',
            'status' => 'in_transit',
        ]);

        InventoryTransfer::create([
            'business_id' => $this->businessA->id,
            'transfer_code' => 'TRF-TRN-001',
            'asset_id' => $asset->id,
            'from_affiliate_id' => $this->branchA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'sent_by' => $this->staffA1->id,
            'recipient_id' => $this->staffA2->id,
            'status' => 'in_transit',
            'sent_at' => Carbon::now(),
            'approved_by' => $this->ownerA->id,
            'approved_at' => Carbon::now(),
        ]);

        // Branch A1 booking attempt fails
        $respA1 = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branchA1->id,
                'customer_name' => 'Budi Pelanggan',
                'customer_phone' => '08123456789',
                'asset_ids' => [$asset->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
            ]);
        $respA1->assertStatus(422);

        // Branch A2 booking attempt fails
        $respA2 = $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branchA2->id,
                'customer_name' => 'Budi Pelanggan',
                'customer_phone' => '08123456789',
                'asset_ids' => [$asset->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
            ]);
        $respA2->assertStatus(422);
    }

    public function test_18_to_22_transfer_lifecycle_receipt_and_dashboard_fleet_adjustment(): void
    {
        // 1. Initial State: iPhone 11 Purple at Branch A1
        $asset = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-LIFE-01',
            'serial_number' => 'SN-LIFE-01',
            'status' => 'ready',
        ]);

        // Initial check on Branch A1
        $initA1 = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $initA1->assertOk()
            ->assertJsonPath('data.kpi.units_total', 1)
            ->assertJsonPath('data.kpi.units_ready', 1);

        // Initial check on Branch A2
        $initA2 = $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $initA2->assertOk()
            ->assertJsonPath('data.kpi.units_total', 0)
            ->assertJsonPath('data.kpi.units_ready', 0);

        // Initial consolidated check
        $initCons = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $initCons->assertOk()
            ->assertJsonPath('data.kpi.units_total', 1);

        // 2. Transfer Initiation & Approval => In Transit
        $transfer = InventoryTransfer::create([
            'business_id' => $this->businessA->id,
            'transfer_code' => 'TRF-LIFE-001',
            'asset_id' => $asset->id,
            'from_affiliate_id' => $this->branchA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'sent_by' => $this->staffA1->id,
            'recipient_id' => $this->staffA2->id,
            'status' => 'in_transit',
            'sent_at' => Carbon::now(),
            'approved_by' => $this->ownerA->id,
            'approved_at' => Carbon::now(),
        ]);
        $asset->update(['status' => 'in_transit']);

        // Test 21: While in transit, Ready / Available at Branch A1 excludes it!
        $transitA1 = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $transitA1->assertOk()
            ->assertJsonPath('data.kpi.units_ready', 0)
            ->assertJsonPath('data.kpi.units_in_transit', 1);

        // While in transit, Branch A2 still has 0 total and 0 ready!
        $transitA2 = $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $transitA2->assertOk()
            ->assertJsonPath('data.kpi.units_total', 0)
            ->assertJsonPath('data.kpi.units_ready', 0);

        // 3. Receipt Confirmation by assigned recipient (Staff A2)
        $rcvResp = $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/transfers/{$transfer->id}/receive");
        $rcvResp->assertOk();

        // Test 18 & 21: Source branch Total Fleet decreases by 1 (to 0), Ready count is 0
        $postA1 = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $postA1->assertOk()
            ->assertJsonPath('data.kpi.units_total', 0)
            ->assertJsonPath('data.kpi.units_ready', 0);

        // Test 19 & 22: Destination branch Total Fleet increases by 1 (to 1), Ready count becomes 1
        $postA2 = $this->actingAs($this->staffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $postA2->assertOk()
            ->assertJsonPath('data.kpi.units_total', 1)
            ->assertJsonPath('data.kpi.units_ready', 1);

        // Test 20: Business-wide Total Fleet remains unchanged (still 1)
        $postCons = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $postCons->assertOk()
            ->assertJsonPath('data.kpi.units_total', 1);
    }

    public function test_23_source_branch_cannot_create_booking_using_transferred_iphone_id(): void
    {
        $asset = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id, // Transferred to A2
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-NOBOOK-01',
            'serial_number' => 'SN-NOBOOK-01',
            'status' => 'ready',
        ]);

        // Branch A1 attempts to book unit currently at A2
        $resp = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branchA1->id,
                'customer_name' => 'Budi Pelanggan',
                'customer_phone' => '08123456789',
                'asset_ids' => [$asset->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
            ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['asset_ids']);
    }

    public function test_24_destination_branch_cannot_confirm_receipt_using_unassigned_user(): void
    {
        $otherStaffA2 = User::create([
            'name' => 'Unassigned Staff',
            'email' => 'unassigned@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $memberOther = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $otherStaffA2->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
        BranchMember::create([
            'business_member_id' => $memberOther->id,
            'affiliate_id' => $this->branchA2->id,
        ]);

        $asset = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-UNAUTH-01',
            'serial_number' => 'SN-UNAUTH-01',
            'status' => 'in_transit',
        ]);

        $transfer = InventoryTransfer::create([
            'business_id' => $this->businessA->id,
            'transfer_code' => 'TRF-UNAUTH-001',
            'asset_id' => $asset->id,
            'from_affiliate_id' => $this->branchA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'sent_by' => $this->staffA1->id,
            'recipient_id' => $this->staffA2->id, // Specifically assigned to staffA2
            'status' => 'in_transit',
            'sent_at' => Carbon::now(),
            'approved_by' => $this->ownerA->id,
            'approved_at' => Carbon::now(),
        ]);

        // otherStaffA2 attempts to confirm receipt -> 403 Forbidden!
        $resp = $this->actingAs($otherStaffA2)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/transfers/{$transfer->id}/receive");

        $resp->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_25_existing_inventory_and_revenue_data_remain_consistent_after_cancelled_transfer(): void
    {
        $asset = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelIPhone11->id,
            'asset_code' => 'IPHONE-CAN-01',
            'serial_number' => 'SN-CAN-01',
            'status' => 'in_transit',
        ]);

        $transfer = InventoryTransfer::create([
            'business_id' => $this->businessA->id,
            'transfer_code' => 'TRF-CAN-001',
            'asset_id' => $asset->id,
            'from_affiliate_id' => $this->branchA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'sent_by' => $this->staffA1->id,
            'recipient_id' => $this->staffA2->id,
            'status' => 'in_transit',
            'sent_at' => Carbon::now(),
            'approved_by' => $this->ownerA->id,
            'approved_at' => Carbon::now(),
        ]);

        // Cancel transfer
        $resp = $this->actingAs($this->ownerA)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->postJson("/api/v1/transfers/{$transfer->id}/cancel", [
                'cancellation_reason' => 'Transfer aborted due to courier cancellation.',
            ]);
        $resp->assertOk();

        // Asset restored to ready at Branch A1
        $asset->refresh();
        $this->assertEquals('ready', $asset->status);
        $this->assertEquals($this->branchA1->id, $asset->affiliate_id);

        // Branch A1 dashboard counts
        $kpiA1 = $this->actingAs($this->staffA1)
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/dashboard/kpi');
        $kpiA1->assertOk()
            ->assertJsonPath('data.kpi.units_total', 1)
            ->assertJsonPath('data.kpi.units_ready', 1)
            ->assertJsonPath('data.kpi.units_in_transit', 0);
    }
}
