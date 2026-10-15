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

class EndToEndIPhoneTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branchA;
    protected Affiliate $branchB;
    protected User $owner;
    protected User $staffA;
    protected User $staffX;
    protected User $staffOtherB;
    protected AssetModel $iphone11Model;
    protected Asset $purpleIphone11;
    protected PaymentMethod $paymentMethod;

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

        // 2. Owner & Business
        $this->owner = User::create([
            'name' => 'Owner Bisnis Rental',
            'email' => 'owner@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Business A',
            'slug' => 'rentcore-business-a',
            'phone' => '081234567890',
            'currency' => 'IDR',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);

        // 3. Branches: Branch A (Source) and Branch B (Destination)
        $this->branchA = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'BR-A',
            'name' => 'Cabang Branch A',
            'slug' => 'cabang-branch-a',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchB = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'BR-B',
            'name' => 'Cabang Branch B',
            'slug' => 'cabang-branch-b',
            'is_central' => false,
            'is_active' => true,
        ]);

        // 4. Staff members
        // Staff at Branch A
        $this->staffA = User::create([
            'name' => 'Staff Origin A',
            'email' => 'staff.a@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmA = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffA->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmA->id,
            'affiliate_id' => $this->branchA->id,
        ]);

        // Staff X at Branch B (Assigned Recipient)
        $this->staffX = User::create([
            'name' => 'Staff X Destination',
            'email' => 'staff.x@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmX = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffX->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmX->id,
            'affiliate_id' => $this->branchB->id,
        ]);

        // Other Staff at Branch B (Unassigned)
        $this->staffOtherB = User::create([
            'name' => 'Other Staff Branch B',
            'email' => 'other.b@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmOtherB = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffOtherB->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmOtherB->id,
            'affiliate_id' => $this->branchB->id,
        ]);

        // 5. Catalog: iPhone 11 Purple
        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
        ]);

        $this->iphone11Model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 11 128GB',
            'slug' => 'iphone-11-128gb',
            'storage_capacity' => '128GB',
            'color' => 'Purple',
        ]);

        PricingTier::create([
            'model_id' => $this->iphone11Model->id,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'price' => 150000.00,
            'extension_hourly_rate' => 10000.00,
            'is_active' => true,
        ]);

        // 6. Initial State: iPhone 11 Purple at Branch A, status: ready
        $this->purpleIphone11 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchA->id,
            'model_id' => $this->iphone11Model->id,
            'asset_code' => 'AST-IP11-PURPLE-001',
            'serial_number' => 'SN-IP11-P-999',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 95,
        ]);
    }

    protected function requestAs(User $user, Affiliate $branch)
    {
        auth()->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string)$this->business->id,
            'X-Branch-Id' => (string)$branch->id,
        ]);
    }

    /**
     * Complete End-to-End Acceptance Scenario:
     * Physical iPhone 11 Purple transfer from Branch A to Branch B.
     */
    public function test_complete_physical_iphone_transfer_lifecycle_and_branch_isolation(): void
    {
        // --- PRE-CONDITION VERIFICATION ---
        // Branch A inventory lists iPhone 11 Purple
        $resPreA = $this->requestAs($this->staffA, $this->branchA)->getJson('/api/v1/assets?status=ready');
        $resPreA->assertStatus(200);
        $this->assertContains($this->purpleIphone11->id, collect($resPreA->json('data.data'))->pluck('id')->all());

        // Branch B inventory does NOT list iPhone 11 Purple
        $resPreB = $this->requestAs($this->staffX, $this->branchB)->getJson('/api/v1/assets?status=ready');
        $resPreB->assertStatus(200);
        $this->assertNotContains($this->purpleIphone11->id, collect($resPreB->json('data.data'))->pluck('id')->all());

        // --- STEP 1: Staff A submits transfer request to Branch B with Staff X as recipient ---
        $submitRes = $this->requestAs($this->staffA, $this->branchA)->postJson('/api/v1/transfers', [
            'asset_id' => $this->purpleIphone11->id,
            'to_affiliate_id' => $this->branchB->id,
            'recipient_id' => $this->staffX->id,
            'notes' => 'Mutasi unit iPhone 11 Ungu untuk booking event di Cabang B',
        ]);
        $submitRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');
        $transferId = $submitRes->json('data.id');

        // Asset must remain at Branch A with status reserved
        $this->assertEquals($this->branchA->id, $this->purpleIphone11->fresh()->affiliate_id);
        $this->assertEquals('reserved', $this->purpleIphone11->fresh()->status);

        // Unapproved transfer must NOT appear in ready inventory at either branch
        $this->assertNotContains($this->purpleIphone11->id, collect($this->requestAs($this->staffA, $this->branchA)->getJson('/api/v1/assets?status=ready')->json('data.data'))->pluck('id')->all());
        $this->assertNotContains($this->purpleIphone11->id, collect($this->requestAs($this->staffX, $this->branchB)->getJson('/api/v1/assets?status=ready')->json('data.data'))->pluck('id')->all());

        // --- STEP 3: Owner approves the request -> status transitions to in_transit ---
        $approveRes = $this->requestAs($this->owner, $this->branchA)->postJson("/api/v1/transfers/{$transferId}/approve");
        $approveRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'in_transit');

        $this->assertEquals('in_transit', $this->purpleIphone11->fresh()->status);
        $this->assertEquals($this->branchA->id, $this->purpleIphone11->fresh()->affiliate_id);

        // In transit: device cannot be booked at Branch A or Branch B
        $bookingFailA = $this->requestAs($this->staffA, $this->branchA)->postJson('/api/v1/bookings', [
            'customer_name' => 'Customer A',
            'customer_phone' => '08123456789',
            'customer_address' => 'Surabaya',
            'asset_ids' => [$this->purpleIphone11->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 150000,
        ]);
        $bookingFailA->assertStatus(422);

        $bookingFailB = $this->requestAs($this->staffX, $this->branchB)->postJson('/api/v1/bookings', [
            'customer_name' => 'Customer B',
            'customer_phone' => '08123456789',
            'customer_address' => 'Sidoarjo',
            'asset_ids' => [$this->purpleIphone11->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 150000,
        ]);
        $bookingFailB->assertStatus(422);

        // --- STEP 9: Attempt receipt confirmation with a DIFFERENT user at Branch B -> REJECTED ---
        $wrongUserRes = $this->requestAs($this->staffOtherB, $this->branchB)->postJson("/api/v1/transfers/{$transferId}/receive");
        $wrongUserRes->assertStatus(403)
            ->assertJsonPath('success', false);

        // --- STEP 5: Assigned recipient Staff X confirms receipt ---
        $receiveRes = $this->requestAs($this->staffX, $this->branchB)->postJson("/api/v1/transfers/{$transferId}/receive");
        $receiveRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'received');

        // --- VERIFY DATABASE TRANSITION ---
        $deviceAfter = $this->purpleIphone11->fresh();
        $this->assertEquals($this->branchB->id, $deviceAfter->affiliate_id, 'Authoritative location MUST now be Branch B');
        $this->assertEquals('ready', $deviceAfter->status);

        $transferAfter = InventoryTransfer::find($transferId);
        $this->assertEquals('received', $transferAfter->status);
        $this->assertEquals($this->staffX->id, $transferAfter->received_by);
        $this->assertNotNull($transferAfter->received_at);
        $this->assertEquals($this->owner->id, $transferAfter->approved_by);
        $this->assertNotNull($transferAfter->approved_at);

        // --- STEP 6 & 7: Refresh inventory and booking wizard queries ---
        // Branch A's inventory API does NOT return the iPhone
        $resPostA = $this->requestAs($this->staffA, $this->branchA)->getJson('/api/v1/assets?status=ready');
        $this->assertNotContains($this->purpleIphone11->id, collect($resPostA->json('data.data'))->pluck('id')->all());

        // Branch B's inventory API DOES return the iPhone
        $resPostB = $this->requestAs($this->staffX, $this->branchB)->getJson('/api/v1/assets?status=ready');
        $this->assertContains($this->purpleIphone11->id, collect($resPostB->json('data.data'))->pluck('id')->all());

        // --- STEP 8: Branch A attempts direct booking API with iPhone ID -> MUST BE REJECTED ---
        $directBookingAttemptA = $this->requestAs($this->staffA, $this->branchA)->postJson('/api/v1/bookings', [
            'affiliate_id' => $this->branchA->id,
            'customer_name' => 'Bypassing Attacker',
            'customer_phone' => '08999999999',
            'customer_address' => 'Jl. Ilegal No. 1',
            'asset_ids' => [$this->purpleIphone11->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 150000,
        ]);
        $directBookingAttemptA->assertStatus(422)
            ->assertJsonValidationErrors(['asset_ids']);

        // --- Branch B can create a booking for the transferred iPhone ---
        $directBookingB = $this->requestAs($this->staffX, $this->branchB)->postJson('/api/v1/bookings', [
            'affiliate_id' => $this->branchB->id,
            'customer_name' => 'Pelanggan Sah Cabang B',
            'customer_phone' => '08788888888',
            'customer_address' => 'Jl. Raya Darmo No. 45',
            'asset_ids' => [$this->purpleIphone11->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->paymentMethod->id,
            'cash_received' => 150000,
        ]);
        $directBookingB->assertStatus(201)
            ->assertJsonPath('success', true);

        // Device is now rented at Branch B
        $this->assertEquals('rented', $this->purpleIphone11->fresh()->status);
        $this->assertEquals($this->branchB->id, $this->purpleIphone11->fresh()->affiliate_id);
    }
}
