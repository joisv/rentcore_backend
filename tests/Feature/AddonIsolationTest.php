<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\PricingTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AddonIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;
    private Affiliate $branchKonz;
    private Affiliate $branchOther;
    private AssetCategory $consoleCategory;
    private AssetCategory $controllerCategoryWithAddonFlag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Konz',
            'email' => 'owner-konz@rentcore.id',
            'password' => Hash::make('password123'),
            'role' => 'owner',
        ]);

        $this->business = Business::create([
            'name' => 'Konz',
            'slug' => 'konz-' . uniqid(),
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'addons_enabled' => true,
            'max_addons_per_rental' => 5,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->branchKonz = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'KON',
            'name' => 'Konz Pusat',
            'slug' => 'konz-pusat-' . uniqid(),
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchOther = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'CB2',
            'name' => 'Cabang Dua',
            'slug' => 'cabang-dua-' . uniqid(),
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->consoleCategory = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'PlayStation 5 & 4',
            'slug' => 'playstation-5-4-konz',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->controllerCategoryWithAddonFlag = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Stik & Controller',
            'slug' => 'stik-controller-konz',
            'is_addon' => true,
            'is_active' => true,
        ]);
    }

    public function test_non_addon_playstation4_is_never_returned_for_addon_query_even_with_addon_category(): void
    {
        $ps4Model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->controllerCategoryWithAddonFlag->id,
            'brand' => 'Sony',
            'name' => 'PlayStation 4',
            'slug' => 'sony-playstation-4',
            'is_addon' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $ps4Model->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 120000.00,
            'is_active' => true,
        ]);

        Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchKonz->id,
            'model_id' => $ps4Model->id,
            'asset_code' => 'AST-568362',
            'status' => 'ready',
        ]);

        // Add-on unit exists on a DIFFERENT branch and a DIFFERENT store (must not leak)
        $addonModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->controllerCategoryWithAddonFlag->id,
            'brand' => 'Sony',
            'name' => 'DualShock 4',
            'slug' => 'sony-dualshock-4',
            'is_addon' => true,
            'is_active' => true,
        ]);
        Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchOther->id,
            'model_id' => $addonModel->id,
            'asset_code' => 'AST-ADD-CB2',
            'status' => 'ready',
        ]);

        $otherBusiness = Business::create([
            'name' => 'Other Store',
            'slug' => 'other-store-' . uniqid(),
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);
        $otherStoreBranch = Affiliate::create([
            'business_id' => $otherBusiness->id,
            'code' => 'OTH',
            'name' => 'Other Branch',
            'slug' => 'other-branch-' . uniqid(),
            'is_central' => true,
            'is_active' => true,
        ]);
        $otherStoreCategory = AssetCategory::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Aksesoris Other',
            'slug' => 'aksesoris-other-' . uniqid(),
            'is_addon' => true,
            'is_active' => true,
        ]);
        $otherAddonModel = AssetModel::create([
            'business_id' => $otherBusiness->id,
            'category_id' => $otherStoreCategory->id,
            'brand' => 'Anker',
            'name' => 'Other Store Powerbank',
            'slug' => 'other-store-powerbank',
            'is_addon' => true,
            'is_active' => true,
        ]);
        Asset::create([
            'business_id' => $otherBusiness->id,
            'affiliate_id' => $otherStoreBranch->id,
            'model_id' => $otherAddonModel->id,
            'asset_code' => 'AST-OTH-ADD',
            'status' => 'ready',
        ]);

        $headers = [
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branchKonz->id,
        ];

        // 1. Add-on query on branch konz returns strictly empty array
        $addonResponse = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->getJson("/api/v1/assets?status=ready&branch_id={$this->branchKonz->id}&is_addon=1&all=1");

        $addonResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');

        // 2. Device (non-addon) query on branch konz returns PlayStation 4 with explicit false booleans
        $deviceResponse = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->getJson("/api/v1/assets?status=ready&branch_id={$this->branchKonz->id}&is_addon=0&all=1");

        $deviceResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.asset_code', 'AST-568362')
            ->assertJsonPath('data.0.is_addon', false)
            ->assertJsonPath('data.0.model.is_addon', false);

        // 3. Branch Dua add-on query returns only DualShock 4, never PlayStation 4 or other business
        $branch2Addons = $this->actingAs($this->owner)
            ->withHeaders([
                'X-Business-Id' => (string) $this->business->id,
                'X-Branch-Id' => (string) $this->branchOther->id,
            ])
            ->getJson("/api/v1/assets?status=ready&branch_id={$this->branchOther->id}&is_addon=1&all=1");

        $branch2Addons->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.asset_code', 'AST-ADD-CB2')
            ->assertJsonPath('data.0.is_addon', true);

        // 4. Floating model availability for konz includes PlayStation 4 (is_addon = false)
        $start = urlencode(now()->addDay()->toIso8601String());
        $end = urlencode(now()->addDays(2)->toIso8601String());
        $modelsResponse = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->getJson("/api/v1/assets/models?branch_id={$this->branchKonz->id}&start_at={$start}&end_at={$end}");

        $modelsResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'PlayStation 4')
            ->assertJsonPath('data.0.is_addon', false);
    }

    public function test_product_store_and_update_persist_is_addon_strictly_as_boolean(): void
    {
        $headers = [
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branchKonz->id,
        ];

        $createRes = $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->postJson('/api/v1/products', [
                'category_id' => $this->consoleCategory->id,
                'brand' => 'Sony',
                'name' => 'PlayStation 4 Pro',
                'is_addon' => false,
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.is_addon', false);

        $modelId = (int) $createRes->json('data.id');
        $this->assertFalse((bool) AssetModel::findOrFail($modelId)->is_addon);

        // Toggle ON then back OFF via multipart/string "0"
        $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->putJson("/api/v1/products/{$modelId}", [
                'is_addon' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_addon', true);

        $this->actingAs($this->owner)
            ->withHeaders($headers)
            ->putJson("/api/v1/products/{$modelId}", [
                'is_addon' => '0',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_addon', false);

        $this->assertFalse((bool) AssetModel::findOrFail($modelId)->is_addon);
    }
}
