<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\InventoryTransfer;
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchAwareBookingInventoryTest extends TestCase
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
    protected AssetModel $model;
    protected PaymentMethod $paymentMethod;
    protected Asset $iphoneA1;
    protected Asset $iphoneA2;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Payment Method
        $this->paymentMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai (Cash)',
            'type' => 'cash',
            'is_active' => true,
        ]);

        // 2. Business A & Users
        $this->ownerA = User::create([
            'name' => 'Owner Mega Jaya',
            'email' => 'owner.megajaya@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->businessA = Business::create([
            'name' => 'Mega Jaya Rental',
            'slug' => 'mega-jaya-rental',
            'phone' => '081234567890',
            'currency' => 'IDR',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);

        $this->branchA1 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'BR-A1',
            'name' => 'Cabang Surabaya Pusat',
            'slug' => 'cabang-surabaya-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'BR-A2',
            'name' => 'Cabang Surabaya Barat',
            'slug' => 'cabang-surabaya-barat',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->staffA1 = User::create([
            'name' => 'Staff Pusat',
            'email' => 'staff.pusat@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmA1 = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA1->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmA1->id,
            'affiliate_id' => $this->branchA1->id,
        ]);

        $this->staffA2 = User::create([
            'name' => 'Staff Barat',
            'email' => 'staff.barat@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmA2 = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA2->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmA2->id,
            'affiliate_id' => $this->branchA2->id,
        ]);

        // 3. Business B (Competitor)
        $this->ownerB = User::create([
            'name' => 'Owner Nusantara',
            'email' => 'owner.nusantara@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'Nusantara Gadget',
            'slug' => 'nusantara-gadget',
            'phone' => '089999999999',
            'currency' => 'IDR',
            'owner_id' => $this->ownerB->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessB->id,
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);

        $this->branchB1 = Affiliate::create([
            'business_id' => $this->businessB->id,
            'code' => 'BR-B1',
            'name' => 'Cabang Jakarta Selatan',
            'slug' => 'cabang-jakarta-selatan',
            'is_central' => true,
            'is_active' => true,
        ]);

        // 4. Catalog & Assets at Branch A1
        $category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'iPhone Rental',
            'slug' => 'iphone-rental',
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro 128GB',
            'slug' => 'iphone-15-pro-128gb',
            'storage_capacity' => '128GB',
            'color' => 'Natural Titanium',
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'price' => 300000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);

        $this->iphoneA1 = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IPHONE-A1-001',
            'serial_number' => 'SN-IP15P-001',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 100,
        ]);

        $this->iphoneA2 = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IPHONE-A1-002',
            'serial_number' => 'SN-IP15P-002',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 99,
        ]);
    }

    /**
     * Test 1: After Branch B confirms receipt, the transferred iPhone is excluded from Branch A's booking-unit list.
     */
    public function test_transferred_iphone_excluded_from_source_branch_booking_list(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Owner transfers iPhone A1 from Branch A1 to Branch A2 directly (recipient is staffA2)
        $storeRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
            'notes' => 'Transfer unit ke cabang barat',
        ]);
        $storeRes->assertStatus(201);
        $transferId = $storeRes->json('data.id');

        // Staff A2 confirms receipt
        auth()->forgetGuards();
        $tokenStaff2 = $this->staffA2->createToken('test')->plainTextToken;
        $receiveRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff2}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA2->id,
        ])->postJson("/api/v1/transfers/{$transferId}/receive");
        $receiveRes->assertStatus(200);

        // Branch A1 queries available booking inventory
        auth()->forgetGuards();
        $listRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->getJson('/api/v1/assets?status=ready');

        $listRes->assertStatus(200);
        $assets = $listRes->json('data.data');
        $assetIds = collect($assets)->pluck('id')->all();

        // Transferred iPhone A1 must NOT be in Branch A1 list
        $this->assertNotContains($this->iphoneA1->id, $assetIds);
        // Non-transferred iPhone A2 MUST still be present
        $this->assertContains($this->iphoneA2->id, $assetIds);
    }

    /**
     * Test 2: The same iPhone is included in Branch B's booking list when received and ready.
     */
    public function test_transferred_iphone_included_in_destination_branch_booking_list(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        $storeRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $storeRes->json('data.id');

        // Confirm receipt
        auth()->forgetGuards();
        $tokenStaff2 = $this->staffA2->createToken('test')->plainTextToken;
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff2}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA2->id,
        ])->postJson("/api/v1/transfers/{$transferId}/receive")->assertStatus(200);

        // Branch A2 queries available booking inventory
        auth()->forgetGuards();
        $listRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff2}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA2->id,
        ])->getJson('/api/v1/assets?status=ready');

        $listRes->assertStatus(200);
        $assets = $listRes->json('data.data');
        $assetIds = collect($assets)->pluck('id')->all();

        // iPhone A1 MUST be in Branch A2 list
        $this->assertContains($this->iphoneA1->id, $assetIds);
    }

    /**
     * Test 3: Branch A cannot create a booking for the transferred iPhone by submitting its ID directly.
     */
    public function test_source_branch_cannot_book_transferred_iphone_by_id(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        $storeRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $storeRes->json('data.id');

        // Confirm receipt
        auth()->forgetGuards();
        $tokenStaff2 = $this->staffA2->createToken('test')->plainTextToken;
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff2}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA2->id,
        ])->postJson("/api/v1/transfers/{$transferId}/receive")->assertStatus(200);

        // Branch A1 attempts to create booking for iPhone A1
        auth()->forgetGuards();
        $bookingRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/bookings', [
            'customer_name' => 'Customer Test',
            'customer_phone' => '08111111111',
            'customer_address' => 'Jl. Pemuda No. 10',
            'asset_ids' => [$this->iphoneA1->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 300000,
        ]);

        $bookingRes->assertStatus(422);
        $bookingRes->assertJsonValidationErrors(['asset_ids']);
    }

    /**
     * Test 4: Forged destination branch ID cannot expose foreign inventory.
     */
    public function test_forged_destination_branch_id_cannot_expose_foreign_inventory(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Attempting to query with a branch belonging to Business B
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchB1->id,
        ])->getJson('/api/v1/assets?status=ready');

        $this->assertContains($res->status(), [403, 404, 422]);

        // Staff restricted to Branch A1 attempts to access Branch A2 via query param
        auth()->forgetGuards();
        $tokenStaff1 = $this->staffA1->createToken('test')->plainTextToken;
        $staffRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff1}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->getJson("/api/v1/assets?status=ready&branch_id={$this->branchA2->id}");

        $staffRes->assertStatus(403);
    }

    /**
     * Test 5: An iPhone in transit cannot be booked at any branch.
     */
    public function test_iphone_in_transit_cannot_be_booked_at_any_branch(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Transfer initiated by owner -> directly in_transit
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ])->assertStatus(201);

        $this->assertEquals('in_transit', $this->iphoneA1->fresh()->status);

        // Branch A1 attempts to book it
        $resA1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/bookings', [
            'customer_name' => 'Customer Transit',
            'customer_phone' => '08222222222',
            'customer_address' => 'Jl. Transit No. 1',
            'asset_ids' => [$this->iphoneA1->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 300000,
        ]);
        $resA1->assertStatus(422);

        // Branch A2 attempts to book it before receiving
        auth()->forgetGuards();
        $tokenStaff2 = $this->staffA2->createToken('test')->plainTextToken;
        $resA2 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff2}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA2->id,
        ])->postJson('/api/v1/bookings', [
            'customer_name' => 'Customer Transit',
            'customer_phone' => '08222222222',
            'customer_address' => 'Jl. Transit No. 1',
            'asset_ids' => [$this->iphoneA1->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 300000,
        ]);
        $resA2->assertStatus(422);
    }

    /**
     * Test 6: Rejecting or cancelling a transfer preserves branch assignment and restores booking eligibility at source.
     */
    public function test_rejected_or_cancelled_transfer_restores_eligibility_at_source_branch(): void
    {
        auth()->forgetGuards();
        $tokenStaff1 = $this->staffA1->createToken('test')->plainTextToken;

        // Staff A1 initiates transfer (creates pending and reserves asset)
        $storeRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff1}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $storeRes->assertStatus(201);
        $transferId = $storeRes->json('data.id');
        $this->assertEquals('reserved', $this->iphoneA1->fresh()->status);

        // Owner rejects transfer with reason
        auth()->forgetGuards();
        $tokenOwner = $this->ownerA->createToken('test')->plainTextToken;
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenOwner}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson("/api/v1/transfers/{$transferId}/reject", [
            'rejection_reason' => 'Unit sedang dibutuhkan di cabang pusat.',
        ])->assertStatus(200);

        $this->assertEquals('ready', $this->iphoneA1->fresh()->status);
        $this->assertEquals($this->branchA1->id, $this->iphoneA1->fresh()->affiliate_id);

        // Branch A1 queries inventory -> iPhone A1 is present
        auth()->forgetGuards();
        $listRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff1}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->getJson('/api/v1/assets?status=ready');
        $listRes->assertStatus(200);
        $this->assertContains($this->iphoneA1->id, collect($listRes->json('data.data'))->pluck('id')->all());

        // Branch A1 creates booking -> succeeds
        $bookingRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff1}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/bookings', [
            'customer_name' => 'Customer Restored',
            'customer_phone' => '08333333333',
            'customer_address' => 'Jl. Pemuda No. 20',
            'asset_ids' => [$this->iphoneA1->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 300000,
        ]);
        $bookingRes->assertStatus(201);
    }

    /**
     * Test 7: Non-transferred iPhones remain bookable under current business rules at source branch.
     */
    public function test_non_transferred_iphones_remain_bookable_at_source_branch(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Transfer iPhone A1 away
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ])->assertStatus(201);

        // Booking iPhone A2 (the unaffected device) at Branch A1 succeeds
        $bookingRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/bookings', [
            'customer_name' => 'Customer Unaffected',
            'customer_phone' => '08444444444',
            'customer_address' => 'Jl. Basuki Rahmat No. 5',
            'asset_ids' => [$this->iphoneA2->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 300000,
        ]);

        $bookingRes->assertStatus(201);
        $this->assertEquals('rented', $this->iphoneA2->fresh()->status);
    }

    /**
     * Test 8: Two concurrent booking requests cannot double-reserve the same iPhone.
     */
    public function test_concurrent_booking_requests_cannot_double_reserve_same_iphone(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // First booking succeeds
        $res1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/bookings', [
            'customer_name' => 'First Customer',
            'customer_phone' => '08555555555',
            'customer_address' => 'Jl. Tunjungan No. 12',
            'asset_ids' => [$this->iphoneA2->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 300000,
        ]);
        $res1->assertStatus(201);

        // Second booking for the same unit fails with 422 (unit no longer ready)
        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/bookings', [
            'customer_name' => 'Second Customer',
            'customer_phone' => '08666666666',
            'customer_address' => 'Jl. Tunjungan No. 14',
            'asset_ids' => [$this->iphoneA2->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 300000,
        ]);
        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['asset_ids']);
    }

    /**
     * Test 9: Destination branch can successfully book the transferred iPhone after receipt confirmation.
     */
    public function test_destination_branch_can_book_transferred_iphone_after_receipt(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Owner transfers iPhone A1 to Branch A2
        $storeRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $storeRes->json('data.id');

        // Staff A2 confirms receipt
        auth()->forgetGuards();
        $tokenStaff2 = $this->staffA2->createToken('test')->plainTextToken;
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff2}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA2->id,
        ])->postJson("/api/v1/transfers/{$transferId}/receive")->assertStatus(200);

        // Branch A2 creates rental for iPhone A1 -> succeeds
        $bookingRes = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff2}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA2->id,
        ])->postJson('/api/v1/bookings', [
            'affiliate_id' => $this->branchA2->id,
            'customer_name' => 'Destination Branch Customer',
            'customer_phone' => '08777777777',
            'customer_address' => 'Jl. Mayjen Sungkono No. 5',
            'asset_ids' => [$this->iphoneA1->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 300000,
        ]);

        $bookingRes->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertEquals('rented', $this->iphoneA1->fresh()->status);
        $this->assertEquals($this->branchA2->id, $this->iphoneA1->fresh()->affiliate_id);
    }

    /**
     * Test 10: Before transfer completion, iPhone is not prematurely listed in destination inventory.
     */
    public function test_iphone_not_prematurely_listed_in_destination_inventory(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Initiate transfer -> directly in_transit
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ])->assertStatus(201);

        // Destination Branch A2 queries ready inventory
        auth()->forgetGuards();
        $tokenStaff2 = $this->staffA2->createToken('test')->plainTextToken;
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenStaff2}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA2->id,
        ])->getJson('/api/v1/assets?status=ready');

        $res->assertStatus(200);
        $assetIds = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($this->iphoneA1->id, $assetIds);
    }

    /**
     * Test 11: The same iPhone cannot have multiple active transfers.
     */
    public function test_same_iphone_cannot_have_multiple_active_transfers(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // First transfer
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ])->assertStatus(201);

        // Second transfer attempt for same device while active
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['asset_id']);
    }

    /**
     * Test 12: In-transit or pending transfer marks device unavailable in checkAvailability.
     */
    public function test_active_transfer_marks_iphone_unavailable_in_check_availability(): void
    {
        auth()->forgetGuards();
        $token = $this->ownerA->createToken('test')->plainTextToken;

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->postJson('/api/v1/transfers', [
            'asset_id' => $this->iphoneA1->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ])->assertStatus(201);

        // Check availability
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => $this->businessA->id,
            'X-Branch-Id' => $this->branchA1->id,
        ])->getJson("/api/v1/assets/{$this->iphoneA1->id}/availability?start_at=" . urlencode(now()->toIso8601String()) . "&end_at=" . urlencode(now()->addDay()->toIso8601String()));

        $res->assertStatus(200)
            ->assertJsonPath('all_available', false)
            ->assertJsonPath('data.is_available', false);
    }
}
