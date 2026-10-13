<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\InventoryTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use RefreshDatabase;

    protected Affiliate $affiliateA;
    protected Affiliate $affiliateB;
    protected User $staffA;
    protected User $staffB;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliateA = Affiliate::create([
            'code' => 'GNT',
            'name' => 'Genteng Central Hub',
            'slug' => 'genteng-central',
            'is_central' => true,
        ]);

        $this->affiliateB = Affiliate::create([
            'code' => 'SLR',
            'name' => 'Siliragung Outlet',
            'slug' => 'siliragung-outlet',
            'is_central' => false,
        ]);

        $this->staffA = User::create([
            'name' => 'Staf Genteng',
            'email' => 'staf.genteng@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->affiliateA->id,
            'is_active' => true,
        ]);

        $this->staffB = User::create([
            'name' => 'Staf Siliragung',
            'email' => 'staf.silir@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->affiliateB->id,
            'is_active' => true,
        ]);

        $category = AssetCategory::create([
            'name' => 'Smartphones',
            'slug' => 'smartphones',
        ]);

        $model = AssetModel::create([
            'category_id' => $category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro 256GB',
            'slug' => 'iphone-15-pro-256gb',
            'storage_capacity' => '256GB',
            'color' => 'Black Titanium',
        ]);

        $this->asset = Asset::create([
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
        Sanctum::actingAs($this->staffA);

        $response = $this->postJson('/api/v1/transfers', [
            'asset_id' => $this->asset->id,
            'to_affiliate_id' => $this->affiliateB->id,
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

        // Audit log must exist
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transfer.initiated',
            'entity_type' => 'InventoryTransfer',
        ]);
    }

    public function test_cannot_transfer_to_same_branch(): void
    {
        Sanctum::actingAs($this->staffA);

        $response = $this->postJson('/api/v1/transfers', [
            'asset_id' => $this->asset->id,
            'to_affiliate_id' => $this->affiliateA->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to_affiliate_id']);
    }

    public function test_destination_branch_can_receive_in_transit_unit(): void
    {
        // 1. Initiate transfer from A to B
        Sanctum::actingAs($this->staffA);
        $initResponse = $this->postJson('/api/v1/transfers', [
            'asset_id' => $this->asset->id,
            'to_affiliate_id' => $this->affiliateB->id,
        ]);
        $transferId = $initResponse->json('data.id');

        // 2. Staff at destination branch B accepts unit
        Sanctum::actingAs($this->staffB);
        $receiveResponse = $this->postJson("/api/v1/transfers/{$transferId}/receive");

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

        // Audit log must exist
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transfer.received',
            'entity_type' => 'InventoryTransfer',
        ]);
    }

    public function test_source_branch_can_cancel_in_transit_transfer(): void
    {
        // 1. Initiate transfer from A to B
        Sanctum::actingAs($this->staffA);
        $initResponse = $this->postJson('/api/v1/transfers', [
            'asset_id' => $this->asset->id,
            'to_affiliate_id' => $this->affiliateB->id,
        ]);
        $transferId = $initResponse->json('data.id');

        // 2. Staff A cancels transfer
        $cancelResponse = $this->postJson("/api/v1/transfers/{$transferId}/cancel");
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
