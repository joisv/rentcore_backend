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
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchToBranchTransferTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Business $businessB;
    protected Affiliate $branchA1;
    protected Affiliate $branchA2;
    protected Affiliate $branchB1;
    protected User $ownerA;
    protected User $adminA1;
    protected User $staffA1;
    protected User $staffA2;
    protected User $otherStaffA2;
    protected User $ownerB;
    protected AssetModel $model;
    protected Asset $assetA;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Users for Business A
        $this->ownerA = User::create([
            'name' => 'Owner A',
            'email' => 'owner.a@megajaya.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->businessA = Business::create([
            'name' => 'Rental Mega Jaya',
            'slug' => 'rental-mega-jaya',
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
            'code' => 'PST',
            'name' => 'Cabang Pusat',
            'slug' => 'cabang-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'SBY',
            'name' => 'Cabang Surabaya',
            'slug' => 'cabang-surabaya',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->adminA1 = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin.a1@megajaya.id',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $bmAdmin = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->adminA1->id,
            'role' => 'admin',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmAdmin->id,
            'affiliate_id' => $this->branchA1->id,
        ]);

        $this->staffA1 = User::create([
            'name' => 'Staff Pusat',
            'email' => 'staff.a1@megajaya.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmStaffA1 = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA1->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmStaffA1->id,
            'affiliate_id' => $this->branchA1->id,
        ]);

        $this->staffA2 = User::create([
            'name' => 'Staff Surabaya',
            'email' => 'staff.a2@megajaya.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmStaffA2 = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA2->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmStaffA2->id,
            'affiliate_id' => $this->branchA2->id,
        ]);

        $this->otherStaffA2 = User::create([
            'name' => 'Other Staff Surabaya',
            'email' => 'other.a2@megajaya.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmOtherA2 = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->otherStaffA2->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmOtherA2->id,
            'affiliate_id' => $this->branchA2->id,
        ]);

        // 2. Business B (Competitor Tenant)
        $this->ownerB = User::create([
            'name' => 'Owner B',
            'email' => 'owner.b@keluarga.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'Keluarga Rental',
            'slug' => 'keluarga-rental',
            'phone' => '08999999999',
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
            'code' => 'MLG',
            'name' => 'Cabang Malang',
            'slug' => 'cabang-malang',
            'is_central' => true,
            'is_active' => true,
        ]);

        // Catalog & Asset in Branch A1
        $category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro Max',
            'slug' => 'iphone-15-pro-max',
            'storage_capacity' => '256GB',
            'color' => 'Natural Titanium',
        ]);

        $this->assetA = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-IP15PM-001',
            'serial_number' => 'SN-IP15PM-TEST1',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 100,
        ]);
    }

    protected function authHeaders(User $user, Business $business, Affiliate $branch): array
    {
        auth()->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        return [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string)$business->id,
            'X-Branch-Id' => (string)$branch->id,
        ];
    }

    public function test_owner_initiated_transfer_auto_approves_to_in_transit(): void
    {
        $headers = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);

        $response = $this->withHeaders($headers)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
            'notes' => 'Owner transfer langsung kirim',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.recipient_id', $this->staffA2->id);

        $this->assertDatabaseHas('inventory_transfers', [
            'asset_id' => $this->assetA->id,
            'status' => 'in_transit',
            'recipient_id' => $this->staffA2->id,
            'approved_by' => $this->ownerA->id,
        ]);

        $this->assertDatabaseHas('assets', [
            'id' => $this->assetA->id,
            'status' => 'in_transit',
        ]);
    }

    public function test_staff_initiated_transfer_creates_pending_and_reserves_asset(): void
    {
        $headers = $this->authHeaders($this->staffA1, $this->businessA, $this->branchA1);

        $response = $this->withHeaders($headers)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
            'notes' => 'Permintaan stok tambahan dari cabang Surabaya',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.recipient_id', $this->staffA2->id);

        $this->assertDatabaseHas('inventory_transfers', [
            'asset_id' => $this->assetA->id,
            'status' => 'pending',
            'approved_by' => null,
        ]);

        // Device must be locked into reserved status
        $this->assertDatabaseHas('assets', [
            'id' => $this->assetA->id,
            'status' => 'reserved',
        ]);
    }

    public function test_owner_can_approve_pending_transfer(): void
    {
        // 1. Staff creates pending transfer
        $staffHeaders = $this->authHeaders($this->staffA1, $this->businessA, $this->branchA1);
        $createRes = $this->withHeaders($staffHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $createRes->json('data.id');

        // 2. Owner approves
        $ownerHeaders = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);
        $approveRes = $this->withHeaders($ownerHeaders)->postJson("/api/v1/transfers/{$transferId}/approve");

        $approveRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'in_transit');

        $this->assertDatabaseHas('inventory_transfers', [
            'id' => $transferId,
            'status' => 'in_transit',
            'approved_by' => $this->ownerA->id,
        ]);

        $this->assertDatabaseHas('assets', [
            'id' => $this->assetA->id,
            'status' => 'in_transit',
        ]);
    }

    public function test_owner_can_reject_pending_transfer_with_reason(): void
    {
        // 1. Staff creates pending transfer
        $staffHeaders = $this->authHeaders($this->staffA1, $this->businessA, $this->branchA1);
        $createRes = $this->withHeaders($staffHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $createRes->json('data.id');

        // 2. Owner rejects with reason
        $ownerHeaders = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);
        $rejectRes = $this->withHeaders($ownerHeaders)->postJson("/api/v1/transfers/{$transferId}/reject", [
            'rejection_reason' => 'Unit sedang disiapkan untuk booking offline hari ini.',
        ]);

        $rejectRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Unit sedang disiapkan untuk booking offline hari ini.');

        $this->assertDatabaseHas('inventory_transfers', [
            'id' => $transferId,
            'status' => 'rejected',
            'rejected_by' => $this->ownerA->id,
        ]);

        // Asset must be safely reverted to ready in origin branch
        $this->assertDatabaseHas('assets', [
            'id' => $this->assetA->id,
            'affiliate_id' => $this->branchA1->id,
            'status' => 'ready',
        ]);
    }

    public function test_staff_and_admin_cannot_approve_or_reject_transfers(): void
    {
        // Staff creates pending transfer
        $staffHeaders = $this->authHeaders($this->staffA1, $this->businessA, $this->branchA1);
        $createRes = $this->withHeaders($staffHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $createRes->json('data.id');

        // Staff tries to approve
        $this->withHeaders($staffHeaders)->postJson("/api/v1/transfers/{$transferId}/approve")
            ->assertStatus(403);

        // Branch Admin tries to approve
        $adminHeaders = $this->authHeaders($this->adminA1, $this->businessA, $this->branchA1);
        $this->withHeaders($adminHeaders)->postJson("/api/v1/transfers/{$transferId}/approve")
            ->assertStatus(403);

        // Branch Admin tries to reject
        $this->withHeaders($adminHeaders)->postJson("/api/v1/transfers/{$transferId}/reject", [
            'rejection_reason' => 'Tolak sendiri',
        ])->assertStatus(403);
    }

    public function test_only_assigned_recipient_can_confirm_receipt(): void
    {
        // Owner creates transfer to Branch A2 with Staff A2 as explicit recipient
        $ownerHeaders = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);
        $createRes = $this->withHeaders($ownerHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $createRes->json('data.id');

        // 1. Another staff member at the same branch attempts to confirm receipt -> 403
        $otherHeaders = $this->authHeaders($this->otherStaffA2, $this->businessA, $this->branchA2);
        $this->withHeaders($otherHeaders)->postJson("/api/v1/transfers/{$transferId}/receive")
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        // 2. The explicitly assigned recipient confirms receipt -> 200 OK
        $recipientHeaders = $this->authHeaders($this->staffA2, $this->businessA, $this->branchA2);
        $receiveRes = $this->withHeaders($recipientHeaders)->postJson("/api/v1/transfers/{$transferId}/receive");

        $receiveRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'received');

        // Asset must now be at Branch A2 with status ready
        $this->assertDatabaseHas('assets', [
            'id' => $this->assetA->id,
            'affiliate_id' => $this->branchA2->id,
            'status' => 'ready',
        ]);
    }

    public function test_cross_business_transfer_is_rejected(): void
    {
        $ownerHeaders = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);

        // Attempt transfer to Business B's branch
        $res = $this->withHeaders($ownerHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchB1->id,
            'recipient_id' => $this->ownerB->id,
        ]);

        $res->assertStatus(404);
    }

    public function test_cannot_transfer_ineligible_asset(): void
    {
        // Mark asset as rented
        $this->assetA->update(['status' => 'rented']);

        $ownerHeaders = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);

        $res = $this->withHeaders($ownerHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors('asset_id');
    }

    public function test_cancel_pending_transfer_reverts_asset_to_ready(): void
    {
        $staffHeaders = $this->authHeaders($this->staffA1, $this->businessA, $this->branchA1);
        $createRes = $this->withHeaders($staffHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $createRes->json('data.id');

        // Staff cancels own transfer
        $cancelRes = $this->withHeaders($staffHeaders)->postJson("/api/v1/transfers/{$transferId}/cancel");

        $cancelRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('assets', [
            'id' => $this->assetA->id,
            'affiliate_id' => $this->branchA1->id,
            'status' => 'ready',
        ]);
    }

    public function test_destination_affiliates_only_lists_same_business_branches(): void
    {
        $headers = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);

        $response = $this->withHeaders($headers)->getJson('/api/v1/transfers/destinations?from_affiliate_id=' . $this->branchA1->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $branchIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->branchA2->id, $branchIds);
        $this->assertNotContains($this->branchB1->id, $branchIds);
    }

    public function test_recipients_endpoint_only_lists_destination_branch_members(): void
    {
        $headers = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);

        $response = $this->withHeaders($headers)->getJson('/api/v1/transfers/recipients?branch_id=' . $this->branchA2->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $recipientIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($this->staffA2->id, $recipientIds);
        $this->assertContains($this->otherStaffA2->id, $recipientIds);
        $this->assertNotContains($this->ownerB->id, $recipientIds);
    }

    public function test_recipient_no_longer_authorized_for_destination_branch_is_rejected(): void
    {
        // 1. Owner creates transfer to Branch A2 assigned to Staff A2
        $ownerHeaders = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);
        $createRes = $this->withHeaders($ownerHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $createRes->json('data.id');

        // 2. Staff A2 is reassigned/removed from destination branch Branch A2
        BranchMember::where('affiliate_id', $this->branchA2->id)
            ->whereHas('businessMember', fn($bm) => $bm->where('user_id', $this->staffA2->id))
            ->delete();

        // 3. Staff A2 attempts to confirm receipt -> Must be rejected with 403
        $recipientHeaders = $this->authHeaders($this->staffA2, $this->businessA, $this->branchA2);
        $receiveRes = $this->withHeaders($recipientHeaders)->postJson("/api/v1/transfers/{$transferId}/receive");

        $receiveRes->assertStatus(403)
            ->assertJsonPath('success', false);

        // Asset must remain at origin branch Branch A1 in in_transit status
        $this->assertDatabaseHas('assets', [
            'id' => $this->assetA->id,
            'affiliate_id' => $this->branchA1->id,
            'status' => 'in_transit',
        ]);
    }

    public function test_unapproved_transfer_does_not_change_current_branch(): void
    {
        // Staff A1 initiates transfer (creates pending)
        $staffHeaders = $this->authHeaders($this->staffA1, $this->businessA, $this->branchA1);
        $createRes = $this->withHeaders($staffHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $createRes->assertStatus(201);

        // Authoritative branch must NOT change
        $asset = $this->assetA->fresh();
        $this->assertEquals($this->branchA1->id, $asset->affiliate_id);
        $this->assertEquals('reserved', $asset->status);
    }

    public function test_concurrent_or_duplicate_receipt_confirmation_is_rejected(): void
    {
        $ownerHeaders = $this->authHeaders($this->ownerA, $this->businessA, $this->branchA1);
        $createRes = $this->withHeaders($ownerHeaders)->postJson('/api/v1/transfers', [
            'asset_id' => $this->assetA->id,
            'to_affiliate_id' => $this->branchA2->id,
            'recipient_id' => $this->staffA2->id,
        ]);
        $transferId = $createRes->json('data.id');

        // First confirmation succeeds
        $recipientHeaders = $this->authHeaders($this->staffA2, $this->businessA, $this->branchA2);
        $res1 = $this->withHeaders($recipientHeaders)->postJson("/api/v1/transfers/{$transferId}/receive");
        $res1->assertStatus(200);

        // Immediate duplicate attempt fails with 422 (transfer no longer in_transit)
        $res2 = $this->withHeaders($recipientHeaders)->postJson("/api/v1/transfers/{$transferId}/receive");
        $res2->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
