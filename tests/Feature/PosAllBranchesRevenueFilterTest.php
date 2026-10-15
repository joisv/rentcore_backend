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
use App\Models\InventoryTransfer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosAllBranchesRevenueFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Business $businessB;
    protected Affiliate $mainBranchA;
    protected Affiliate $branchA2;
    protected Affiliate $branchA3;
    protected Affiliate $branchB1;
    protected User $ownerA;
    protected User $mainStaffA;
    protected User $satelliteStaffA2;
    protected User $ownerB;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $qrisMethod;
    protected PaymentMethod $transferMethod;
    protected Customer $customerA;
    protected AssetModel $iphoneModel;
    protected Asset $iphoneAsset;

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

        // 3. Setup Branches for Business A
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

        $this->branchA3 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'TMR',
            'name' => 'RentCore Cabang Timur',
            'slug' => 'rentcore-cabang-timur',
            'is_central' => false,
            'is_active' => true,
        ]);

        // Foreign Branch for Business B
        $this->branchB1 = Affiliate::create([
            'business_id' => $this->businessB->id,
            'code' => 'CMP',
            'name' => 'Competitor Main Branch',
            'slug' => 'competitor-main',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->ownerA->update([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
        ]);
        $this->ownerB->update([
            'business_id' => $this->businessB->id,
            'affiliate_id' => $this->branchB1->id,
        ]);

        // 4. Setup Staff Users
        $this->mainStaffA = User::create([
            'business_id' => $this->businessA->id,
            'name' => 'Kasir Main Store',
            'email' => 'kasir.main@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->mainBranchA->id,
            'is_active' => true,
        ]);

        $this->satelliteStaffA2 = User::create([
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
            'user_id' => $this->satelliteStaffA2->id,
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
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
        ]);

        // 8. Asset Setup
        $category = AssetCategory::create(['name' => 'iPhone Series', 'slug' => 'iphone-series', 'is_active' => true]);
        $this->iphoneModel = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro Titanium',
            'slug' => 'iphone-15-pro',
            'is_active' => true,
        ]);
        $this->iphoneAsset = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'IPH15P-001',
            'serial_number' => 'SN-IPHONE-001',
            'imei_1' => '351111222233334',
            'status' => 'available',
        ]);
    }

    /**
     * Scenario 1, 2, 7:
     * Main Store has transactions, Branch Barat has transactions, Branch Timur has transactions.
     * Selecting All Branches (all_branches=1, even when X-Branch-Id: 1 is sent by ApiClient)
     * returns all transactions from all three locations, and total revenue matches exact sum.
     */
    public function test_all_branches_displays_all_transactions_even_when_x_branch_id_header_present(): void
    {
        // 1. Transaction at Main Store: Rp 500,000 (Cash)
        $b1 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-PST-101',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 500000,
            'total_bill' => 500000,
            'payment_status' => 'paid',
        ]);
        BookingItem::create([
            'booking_id' => $b1->id,
            'asset_id' => $this->iphoneAsset->id,
            'unit_price' => 500000,
            'subtotal' => 500000,
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-PST-101',
            'receipt_number' => 'REC-PST-101',
            'booking_id' => $b1->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 500000,
            'transacted_at' => Carbon::now(),
        ]);

        // 2. Transaction at Branch Barat: Rp 350,000 (QRIS)
        $b2 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-BRT-102',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->satelliteStaffA2->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 350000,
            'total_bill' => 350000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-BRT-102',
            'receipt_number' => 'REC-BRT-102',
            'booking_id' => $b2->id,
            'payment_method_id' => $this->qrisMethod->id,
            'processed_by' => $this->satelliteStaffA2->id,
            'type' => 'settlement',
            'amount' => 350000,
            'transacted_at' => Carbon::now(),
        ]);

        // 3. Transaction at Branch Timur: Rp 250,000 (Transfer)
        $b3 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA3->id,
            'booking_code' => 'BK-TMR-103',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 250000,
            'total_bill' => 250000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-TMR-103',
            'receipt_number' => 'REC-TMR-103',
            'booking_id' => $b3->id,
            'payment_method_id' => $this->transferMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 250000,
            'transacted_at' => Carbon::now(),
        ]);

        // Act as Owner with ApiClient sending active branch header X-Branch-Id: 1
        Sanctum::actingAs($this->ownerA);

        $response = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->withHeader('X-Branch-Id', (string)$this->mainBranchA->id)
            ->getJson('/api/v1/cashier/closing-summary?all_branches=1');

        $response->assertOk()
            ->assertJsonPath('data.is_consolidated', true)
            ->assertJsonPath('data.branch_id', null)
            ->assertJsonPath('data.total_revenue', 1100000)
            ->assertJsonPath('data.total_transactions', 3);

        $breakdownByBranch = $response->json('data.breakdown_by_branch');
        $this->assertCount(3, $breakdownByBranch);

        $pst = collect($breakdownByBranch)->firstWhere('branch_id', $this->mainBranchA->id);
        $brt = collect($breakdownByBranch)->firstWhere('branch_id', $this->branchA2->id);
        $tmr = collect($breakdownByBranch)->firstWhere('branch_id', $this->branchA3->id);

        $this->assertEquals(500000, $pst['total_revenue']);
        $this->assertEquals(350000, $brt['total_revenue']);
        $this->assertEquals(250000, $tmr['total_revenue']);

        // Verify recent transactions include all 3 branches
        $recent = $response->json('data.recent_transactions');
        $this->assertCount(3, $recent);
        $recentBranchIds = collect($recent)->pluck('branch_id')->all();
        $this->assertContains($this->mainBranchA->id, $recentBranchIds);
        $this->assertContains($this->branchA2->id, $recentBranchIds);
        $this->assertContains($this->branchA3->id, $recentBranchIds);

        // Also verify BookingController index with all_branches=1 returns all 3 bookings
        $bookingsResp = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->withHeader('X-Branch-Id', (string)$this->mainBranchA->id)
            ->getJson('/api/v1/bookings?all_branches=1');

        $bookingsResp->assertOk()
            ->assertJsonPath('data.total', 3);
    }

    /**
     * Scenario 3, 4, 5:
     * Selecting Branch A returns only Branch A.
     * Selecting Branch B returns only Branch B.
     * Switching back to All Branches restores full consolidated view.
     */
    public function test_switching_between_individual_branches_and_all_branches(): void
    {
        $b1 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-PST-201',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
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
            'transaction_code' => 'TX-PST-201',
            'receipt_number' => 'REC-PST-201',
            'booking_id' => $b1->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 400000,
            'transacted_at' => Carbon::now(),
        ]);

        $b2 = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-BRT-202',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->satelliteStaffA2->id,
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
            'transaction_code' => 'TX-BRT-202',
            'receipt_number' => 'REC-BRT-202',
            'booking_id' => $b2->id,
            'payment_method_id' => $this->qrisMethod->id,
            'processed_by' => $this->satelliteStaffA2->id,
            'type' => 'settlement',
            'amount' => 300000,
            'transacted_at' => Carbon::now(),
        ]);

        Sanctum::actingAs($this->ownerA);

        // 1. Filter Branch A (Main Store)
        $respA = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->mainBranchA->id}");
        $respA->assertOk()
            ->assertJsonPath('data.is_consolidated', false)
            ->assertJsonPath('data.branch_id', $this->mainBranchA->id)
            ->assertJsonPath('data.total_revenue', 400000)
            ->assertJsonPath('data.total_transactions', 1);

        // 2. Filter Branch B (Branch Barat)
        $respB = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchA2->id}");
        $respB->assertOk()
            ->assertJsonPath('data.is_consolidated', false)
            ->assertJsonPath('data.branch_id', $this->branchA2->id)
            ->assertJsonPath('data.total_revenue', 300000)
            ->assertJsonPath('data.total_transactions', 1);

        // 3. Switch back to All Branches
        $respAll = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?all_branches=1');
        $respAll->assertOk()
            ->assertJsonPath('data.is_consolidated', true)
            ->assertJsonPath('data.total_revenue', 700000)
            ->assertJsonPath('data.total_transactions', 2);
    }

    /**
     * Scenario 6:
     * Date filters produce consistent results across transactions and revenue summary.
     */
    public function test_date_filters_produce_consistent_results(): void
    {
        $yesterday = Carbon::yesterday()->setHour(12);

        // Today transaction: Rp 200,000
        $bToday = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-TDY-01',
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
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-TDY-01',
            'receipt_number' => 'REC-TDY-01',
            'booking_id' => $bToday->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 200000,
            'transacted_at' => Carbon::now(),
        ]);

        // Yesterday transaction: Rp 350,000
        $bYest = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'booking_code' => 'BK-YST-01',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->satelliteStaffA2->id,
            'start_at' => $yesterday,
            'end_at' => (clone $yesterday)->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 350000,
            'total_bill' => 350000,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-YST-01',
            'receipt_number' => 'REC-YST-01',
            'booking_id' => $bYest->id,
            'payment_method_id' => $this->qrisMethod->id,
            'processed_by' => $this->satelliteStaffA2->id,
            'type' => 'settlement',
            'amount' => 350000,
            'transacted_at' => $yesterday,
        ]);

        Sanctum::actingAs($this->ownerA);

        // Filter: yesterday date range
        $yDate = $yesterday->toDateString();
        $respYesterday = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?all_branches=1&start_date={$yDate}&end_date={$yDate}");

        $respYesterday->assertOk()
            ->assertJsonPath('data.total_revenue', 350000)
            ->assertJsonPath('data.total_transactions', 1)
            ->assertJsonPath('data.recent_transactions.0.booking_code', 'BK-YST-01');
    }

    /**
     * Scenario 8:
     * Multiple payments, extensions, and penalties do not cause duplicate revenue aggregation.
     */
    public function test_multiple_payments_and_penalties_accurately_aggregate(): void
    {
        $b = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-MULTI-01',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 500000,
            'total_bill' => 600000,
            'payment_status' => 'paid',
        ]);

        // 1. Initial DP payment: Rp 200,000
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-M-01',
            'receipt_number' => 'REC-M-01',
            'booking_id' => $b->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'dp',
            'amount' => 200000,
            'transacted_at' => Carbon::now(),
        ]);

        // 2. Settlement payment: Rp 300,000
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-M-02',
            'receipt_number' => 'REC-M-02',
            'booking_id' => $b->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 300000,
            'transacted_at' => Carbon::now(),
        ]);

        // 3. Penalty payment: Rp 100,000
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-M-03',
            'receipt_number' => 'REC-M-03',
            'booking_id' => $b->id,
            'payment_method_id' => $this->qrisMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'penalty',
            'amount' => 100000,
            'transacted_at' => Carbon::now(),
        ]);

        Sanctum::actingAs($this->ownerA);

        $resp = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?all_branches=1');

        $resp->assertOk()
            ->assertJsonPath('data.total_revenue', 600000)
            ->assertJsonPath('data.total_transactions', 3);
    }

    /**
     * Scenario 9:
     * Satellite branch staff cannot retrieve consolidated revenue (HTTP 403).
     */
    public function test_satellite_branch_staff_cannot_retrieve_consolidated_revenue(): void
    {
        Sanctum::actingAs($this->satelliteStaffA2);

        $response = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?all_branches=1');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');

        // Satellite staff also cannot query bookings with all_branches=1
        $bookResp = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/bookings?all_branches=1');

        $bookResp->assertStatus(403);
    }

    /**
     * Scenario 10:
     * Cross-business tenant boundary isolation. User from another business cannot access transactions.
     */
    public function test_unrelated_business_cannot_access_transactions(): void
    {
        Sanctum::actingAs($this->ownerB);

        // Competitor business B querying Business A closing summary receives 403
        $response = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/cashier/closing-summary?all_branches=1');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'BUSINESS_ACCESS_DENIED');
    }

    /**
     * Scenario 11:
     * Historical branch attribution remains unchanged after physical asset transfer.
     */
    public function test_historical_branch_attribution_remains_unchanged_after_asset_transfer(): void
    {
        // 1. Transaction originally occurred at Main Store with iPhone Asset
        $bOrig = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->mainBranchA->id,
            'booking_code' => 'BK-ORIG-01',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->mainStaffA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'completed',
            'subtotal_amount' => 450000,
            'total_bill' => 450000,
            'payment_status' => 'paid',
        ]);
        BookingItem::create([
            'booking_id' => $bOrig->id,
            'asset_id' => $this->iphoneAsset->id,
            'unit_price' => 450000,
            'subtotal' => 450000,
        ]);
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'transaction_code' => 'TX-ORIG-01',
            'receipt_number' => 'REC-ORIG-01',
            'booking_id' => $bOrig->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->mainStaffA->id,
            'type' => 'settlement',
            'amount' => 450000,
            'transacted_at' => Carbon::now(),
        ]);

        // 2. Transfer physical iPhone asset from Main Store to Branch Barat
        InventoryTransfer::create([
            'business_id' => $this->businessA->id,
            'transfer_code' => 'TRF-TEST-001',
            'asset_id' => $this->iphoneAsset->id,
            'from_affiliate_id' => $this->mainBranchA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'status' => 'received',
            'sent_by' => $this->ownerA->id,
            'sent_at' => Carbon::now(),
            'requested_by' => $this->ownerA->id,
            'received_by' => $this->satelliteStaffA2->id,
            'received_at' => Carbon::now(),
        ]);
        $this->iphoneAsset->update([
            'affiliate_id' => $this->branchA2->id,
            'status' => 'available',
        ]);

        Sanctum::actingAs($this->ownerA);

        // Verify Branch Barat closing summary does NOT count the historical Main Store transaction
        $respBarat = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchA2->id}");
        $respBarat->assertOk()
            ->assertJsonPath('data.total_revenue', 0);

        // Verify Main Store closing summary STILL attributes the historical transaction
        $respPusat = $this->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->mainBranchA->id}");
        $respPusat->assertOk()
            ->assertJsonPath('data.total_revenue', 450000)
            ->assertJsonPath('data.recent_transactions.0.booking_code', 'BK-ORIG-01')
            ->assertJsonPath('data.recent_transactions.0.branch_id', $this->mainBranchA->id);
    }
}
