<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    protected Affiliate $affiliate;
    protected User $staff;
    protected AssetCategory $category;
    protected AssetModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliate = Affiliate::create([
            'name' => 'Branch Mataram',
            'slug' => 'branch-mataram',
            'code' => 'MTR',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'affiliate_id' => $this->affiliate->id,
            'name' => 'Staff Outlet',
            'email' => 'staff.asset@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->category = AssetCategory::create([
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'category_id' => $this->category->id,
            'name' => 'iPhone 15 Pro 128GB',
            'slug' => 'iphone-15-pro-128gb',
            'brand' => 'Apple',
            'storage_capacity' => '128GB',
            'color' => 'Natural Titanium',
            'is_active' => true,
        ]);
    }

    public function test_staff_can_register_new_device_asset(): void
    {
        $payload = [
            'model_id' => $this->model->id,
            'asset_code' => 'IP15P-MTR-001',
            'serial_number' => 'SN9988776655',
            'imei_1' => '359876543210987',
            'condition_grade' => 'A',
            'battery_health' => 98,
            'notes' => 'Unit baru restock',
        ];

        $response = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/assets', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'asset_code' => 'IP15P-MTR-001',
                    'serial_number' => 'SN9988776655',
                    'status' => 'ready',
                    'condition_grade' => 'A',
                    'battery_health' => 98,
                ],
            ]);

        $this->assertDatabaseHas('assets', [
            'asset_code' => 'IP15P-MTR-001',
            'affiliate_id' => $this->affiliate->id,
            'status' => 'ready',
        ]);

        // Verify audit log creation
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'asset.created',
            'entity_type' => 'Asset',
            'user_id' => $this->staff->id,
        ]);
    }

    public function test_staff_can_update_asset_details(): void
    {
        $asset = Asset::create([
            'affiliate_id' => $this->affiliate->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IP15P-MTR-002',
            'serial_number' => 'SN1122334455',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 95,
        ]);

        $updatePayload = [
            'condition_grade' => 'B',
            'battery_health' => 91,
            'status' => 'maintenance',
            'notes' => 'Layar ada goresan halus',
        ];

        $response = $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/v1/assets/{$asset->id}", $updatePayload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'condition_grade' => 'B',
                    'battery_health' => 91,
                    'status' => 'maintenance',
                ],
            ]);

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'status' => 'maintenance',
            'condition_grade' => 'B',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'asset.updated',
            'entity_id' => (string)$asset->id,
            'user_id' => $this->staff->id,
        ]);
    }
}
