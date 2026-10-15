<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BranchMember;
use App\Models\InventoryTransfer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $affiliateA;
    protected Affiliate $affiliateB;
    protected User $staffA;
    protected User $staffB;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staffA = User::create([
            'name' => 'Owner Genteng',
            'email' => 'owner.genteng@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Franchise',
            'slug' => 'rentcore-franchise',
            'owner_id' => $this->staffA->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffA->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);

        $this->affiliateA = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'GNT',
            'name' => 'Genteng Central Hub',
            'slug' => 'genteng-central',
            'is_central' => true,
        ]);

        $this->affiliateB = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'SLR',
            'name' => 'Siliragung Outlet',
            'slug' => 'siliragung-outlet',
            'is_central' => false,
        ]);

        $this->staffA->update(['affiliate_id' => $this->affiliateA->id]);

        $this->staffB = User::create([
            'name' => 'Staf Siliragung',
            'email' => 'staf.silir@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->affiliateB->id,
            'is_active' => true,
        ]);

        $bmStaffB = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffB->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create([
            'business_member_id' => $bmStaffB->id,
            'affiliate_id' => $this->affiliateB->id,
        ]);

        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
        ]);

        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro 256GB',
            'slug' => 'iphone-15-pro-256gb',
            'storage_capacity' => '256GB',
            'color' => 'Black Titanium',
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $model->id,
            'asset_code' => 'AST-IP15P-TRF1',
            'serial_number' => 'SN-TRF-001',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 98,
        ]);
    }

    public function test_branch_staff_can_initiate_transfer_of_ready_asset(): void
    {
        auth()->forgetGuards();
        $token = $this->staffA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->affiliateA->id)
            ->postJson('/api/v1/transfers', [
                'asset_id' => $this->asset->id,
                'to_affiliate_id' => $this->affiliateB->id,
                'recipient_id' => $this->staffB->id,
                'notes' => 'Mutasi stok cadangan weekend',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'in_transit');

        $this->assertDatabaseHas('inventory_transfers', [
            'asset_id' => $this->asset->id,
            'from_affiliate_id' => $this->affiliateA->id,
            'to_affiliate_id' => $this->affiliateB->id,
            'status' => 'in_transit',
        ]);

        // Asset must transition to in_transit
        $this->assertEquals('in_transit', $this->asset->fresh()->status);
    }

    public function test_cannot_transfer_to_same_branch(): void
    {
        auth()->forgetGuards();
        $token = $this->staffA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->affiliateA->id)
            ->postJson('/api/v1/transfers', [
                'asset_id' => $this->asset->id,
                'to_affiliate_id' => $this->affiliateA->id,
                'recipient_id' => $this->staffA->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to_affiliate_id']);
    }

    public function test_destination_branch_can_receive_in_transit_unit(): void
    {
        // 1. Initiate transfer from A to B
        auth()->forgetGuards();
        $tokenA = $this->staffA->createToken('test')->plainTextToken;
        $initResponse = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->affiliateA->id)
            ->postJson('/api/v1/transfers', [
                'asset_id' => $this->asset->id,
                'to_affiliate_id' => $this->affiliateB->id,
                'recipient_id' => $this->staffB->id,
            ]);
        $transferId = $initResponse->json('data.id');

        // 2. Staff at destination branch B accepts unit
        auth()->forgetGuards();
        $tokenB = $this->staffB->createToken('test')->plainTextToken;
        $receiveResponse = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->affiliateB->id)
            ->postJson("/api/v1/transfers/{$transferId}/receive");

        $receiveResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        // Asset ownership must change to Branch B and status restored to ready
        $updatedAsset = $this->asset->fresh();
        $this->assertEquals($this->affiliateB->id, $updatedAsset->affiliate_id);
        $this->assertEquals('ready', $updatedAsset->status);

        // Transfer record must be marked received
        $this->assertDatabaseHas('inventory_transfers', [
            'id' => $transferId,
            'status' => 'received',
            'received_by' => $this->staffB->id,
        ]);
    }

    public function test_source_branch_can_cancel_in_transit_transfer(): void
    {
        // 1. Initiate transfer from A to B
        auth()->forgetGuards();
        $tokenA = $this->staffA->createToken('test')->plainTextToken;
        $initResponse = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->affiliateA->id)
            ->postJson('/api/v1/transfers', [
                'asset_id' => $this->asset->id,
                'to_affiliate_id' => $this->affiliateB->id,
                'recipient_id' => $this->staffB->id,
            ]);
        $transferId = $initResponse->json('data.id');

        // 2. Staff A cancels transfer
        $cancelResponse = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->affiliateA->id)
            ->postJson("/api/v1/transfers/{$transferId}/cancel");
        $cancelResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        // Asset restored to ready in Branch A
        $updatedAsset = $this->asset->fresh();
        $this->assertEquals($this->affiliateA->id, $updatedAsset->affiliate_id);
        $this->assertEquals('ready', $updatedAsset->status);

        $this->assertDatabaseHas('inventory_transfers', [
            'id' => $transferId,
            'status' => 'cancelled',
        ]);
    }
}
