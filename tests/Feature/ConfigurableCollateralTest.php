<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConfigurableCollateralTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $staff;
    private Business $business;
    private Affiliate $branch;
    private Asset $readyAsset;
    private Asset $secondReadyAsset;
    private Asset $thirdReadyAsset;
    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Jaminan',
            'email' => 'owner-jaminan@rentcore.id',
            'password' => Hash::make('password123'),
            'role' => 'owner',
        ]);

        $this->staff = User::create([
            'name' => 'Kasir Jaminan',
            'email' => 'kasir-jaminan@rentcore.id',
            'password' => Hash::make('password123'),
            'role' => 'staff',
        ]);

        $this->business = Business::create([
            'name' => 'SewaKasir Collateral Biz',
            'slug' => 'rentcore-collateral-' . uniqid(),
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'PST',
            'name' => 'Pusat',
            'slug' => 'pusat-' . uniqid(),
            'is_central' => true,
            'is_active' => true,
        ]);

        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Konsol',
            'slug' => 'konsol-' . uniqid(),
            'is_addon' => false,
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Sony',
            'name' => 'PS5 Slim',
            'slug' => 'ps5-slim-' . uniqid(),
            'is_addon' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $model->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 150000.00,
            'is_active' => true,
        ]);

        $this->readyAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => 'PS5-01',
            'status' => 'ready',
        ]);

        $this->secondReadyAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => 'PS5-02',
            'status' => 'ready',
        ]);

        $this->thirdReadyAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => 'PS5-03',
            'status' => 'ready',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'code' => 'cash',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->staff->id,
            'shift_number' => 'SHF-TEST-001',
            'opening_cash_float' => 200000.00,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);
    }

    public function test_new_business_defaults_to_collateral_disabled_and_allows_collateral_free_checkout(): void
    {
        $headers = [
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];

        $getRes = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->getJson("/api/v1/businesses/{$this->business->id}/collateral-settings");

        $getRes->assertOk()
            ->assertJsonPath('data.enable_collateral', false)
            ->assertJsonPath('data.require_collateral', false)
            ->assertJsonCount(5, 'data.collateral_types')
            ->assertJsonPath('data.collateral_types.0.id', 'cash_deposit');

        // Booking without any collateral fields succeeds cleanly when disabled
        $rentRes = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->postJson('/api/v1/bookings', [
                'branch_id' => $this->branch->id,
                'is_scheduled' => false,
                'customer_name' => 'Andi Sans Collateral',
                'customer_phone' => '081234567800',
                'asset_ids' => [$this->readyAsset->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 150000,
            ]);

        $rentRes->assertStatus(201)
            ->assertJsonPath('data.collateral_type', null)
            ->assertJsonPath('data.collateral_type_id', null)
            ->assertJsonPath('data.collateral_type_name', null)
            ->assertJsonPath('data.collateral_storage_slot', null)
            ->assertJsonPath('data.collateral_deposit_amount', null)
            ->assertJsonPath('data.collateral_secured', false);

        // Staff cannot update collateral settings (403)
        $this->actingAs($this->staff)
            ->withHeaders($headers)
            ->putJson("/api/v1/businesses/{$this->business->id}/collateral-settings", [
                'enable_collateral' => true,
            ])
            ->assertStatus(403);

        // When enable_collateral is false, saving with all types disabled is allowed
        $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->putJson("/api/v1/businesses/{$this->business->id}/collateral-settings", [
                'enable_collateral' => false,
                'require_collateral' => false,
                'collateral_types' => [
                    ['id' => 'ektp', 'name' => 'e-KTP', 'input_mode' => 'vault_slot', 'is_active' => false],
                ],
            ])
            ->assertOk();

        // When enable_collateral is true and require_collateral is true, at least 1 type must be active (422)
        $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->putJson("/api/v1/businesses/{$this->business->id}/collateral-settings", [
                'enable_collateral' => true,
                'require_collateral' => true,
                'collateral_types' => [
                    ['id' => 'ektp', 'name' => 'e-KTP', 'input_mode' => 'vault_slot', 'is_active' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['collateral_types']);
    }

    public function test_enabling_collateral_validates_active_types_and_records_cash_deposit(): void
    {
        $headers = [
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];

        $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->putJson("/api/v1/businesses/{$this->business->id}/collateral-settings", [
                'enable_collateral' => true,
                'require_collateral' => true,
                'collateral_types' => [
                    ['id' => 'ektp', 'name' => 'e-KTP', 'input_mode' => 'vault_slot', 'is_active' => false],
                    ['id' => 'cash_deposit', 'name' => 'Deposit Tunai', 'input_mode' => 'cash_deposit', 'is_active' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.enable_collateral', true)
            ->assertJsonPath('data.require_collateral', true);

        // Inactive collateral type must fail with 422
        $badRes = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->postJson('/api/v1/bookings', [
                'branch_id' => $this->branch->id,
                'is_scheduled' => false,
                'customer_name' => 'Budi Santoso',
                'customer_phone' => '081234567890',
                'asset_ids' => [$this->readyAsset->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 150000,
                'collateral_type_id' => 'ektp',
                'collateral_storage_slot' => 'Brankas A, Slot 01',
            ]);

        $badRes->assertStatus(422)
            ->assertJsonValidationErrors(['collateral_type_id']);

        // Active Deposit Tunai persists collateral_type_name + collateral_deposit_amount
        $depositRes = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->postJson('/api/v1/bookings', [
                'branch_id' => $this->branch->id,
                'is_scheduled' => false,
                'customer_name' => 'Budi Santoso',
                'customer_phone' => '081234567890',
                'asset_ids' => [$this->secondReadyAsset->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 150000,
                'collateral_type_id' => 'cash_deposit',
                'collateral_deposit_amount' => 200000,
            ]);

        $depositRes->assertStatus(201)
            ->assertJsonPath('data.collateral_type_id', 'cash_deposit')
            ->assertJsonPath('data.collateral_type_name', 'Deposit Tunai')
            ->assertJsonPath('data.collateral_deposit_amount', '200000.00');

        // When require_collateral is false while enable_collateral is true, 'none' succeeds
        $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->putJson("/api/v1/businesses/{$this->business->id}/collateral-settings", [
                'enable_collateral' => true,
                'require_collateral' => false,
            ])
            ->assertOk();

        $noneRes = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->postJson('/api/v1/bookings', [
                'branch_id' => $this->branch->id,
                'is_scheduled' => false,
                'customer_name' => 'Siti Aminah',
                'customer_phone' => '081234567891',
                'asset_ids' => [$this->thirdReadyAsset->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 150000,
                'collateral_type_id' => 'none',
            ]);

        $noneRes->assertStatus(201)
            ->assertJsonPath('data.collateral_type_id', 'none')
            ->assertJsonPath('data.collateral_type_name', 'Tanpa Jaminan')
            ->assertJsonPath('data.collateral_secured', false);
    }
}
