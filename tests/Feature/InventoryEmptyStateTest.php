<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\PricingTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Business $businessB;
    protected Affiliate $branchA1;
    protected Affiliate $branchA2;
    protected Affiliate $branchB1;
    protected User $ownerA;
    protected User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        // Business A
        $this->ownerA = User::create([
            'name' => 'Owner Alpha',
            'email' => 'owner@alpha.com',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->businessA = Business::create([
            'name' => 'Rental Alpha',
            'slug' => 'rental-alpha',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);

        $this->branchA1 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'AL1',
            'name' => 'Alpha Branch Pusat',
            'slug' => 'alpha-branch-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'AL2',
            'name' => 'Alpha Branch Cabang',
            'slug' => 'alpha-branch-cabang',
            'is_central' => false,
            'is_active' => true,
        ]);

        $ownerMember = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $ownerMember->id,
            'affiliate_id' => $this->branchA1->id,
        ]);

        // Business B
        $this->ownerB = User::create([
            'name' => 'Owner Beta',
            'email' => 'owner@beta.com',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'Rental Beta',
            'slug' => 'rental-beta',
            'owner_id' => $this->ownerB->id,
            'is_active' => true,
        ]);

        $this->branchB1 = Affiliate::create([
            'business_id' => $this->businessB->id,
            'code' => 'BE1',
            'name' => 'Beta Branch Pusat',
            'slug' => 'beta-branch-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $ownerMemberB = BusinessMember::create([
            'business_id' => $this->businessB->id,
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $ownerMemberB->id,
            'affiliate_id' => $this->branchB1->id,
        ]);
    }

    /**
     * Scenario 1: An inventory catalog list with zero records.
     */
    public function test_catalog_list_with_zero_records(): void
    {
        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [],
                    'total' => 0,
                ],
            ]);

        // When all=true
        $responseAll = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/products?all=1');

        $responseAll->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [],
            ]);
    }

    /**
     * Scenario 2: An inventory unit list with zero records.
     */
    public function test_unit_list_with_zero_records(): void
    {
        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->withHeader('X-Branch-Id', (string) $this->branchA1->id)
            ->getJson('/api/v1/assets');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [],
                    'total' => 0,
                ],
            ]);

        // When all=true
        $responseAll = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->withHeader('X-Branch-Id', (string) $this->branchA1->id)
            ->getJson('/api/v1/assets?all=1');

        $responseAll->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [],
            ]);
    }

    /**
     * Scenario 3: A catalog that exists but has no units.
     */
    public function test_catalog_that_exists_with_zero_units_is_not_hidden(): void
    {
        $category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Smartphone',
            'slug' => 'smartphone',
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'name' => 'iPhone 15 Pro Max',
            'brand' => 'Apple',
            'slug' => 'iphone-15-pro-max',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/products?all=1');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'iPhone 15 Pro Max',
                'assets_count' => 0,
                'ready_units_count' => 0,
            ]);
    }

    /**
     * Scenario 4: A search query with no matching records.
     */
    public function test_search_query_with_no_matches_returns_empty(): void
    {
        $category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Smartphone',
            'slug' => 'smartphone',
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'name' => 'iPhone 15',
            'brand' => 'Apple',
            'slug' => 'iphone-15',
            'is_active' => true,
        ]);

        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $model->id,
            'asset_code' => 'IP15-001',
            'serial_number' => 'SN-12345',
            'status' => 'ready',
        ]);

        // Search for non-existent product
        $responseProd = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/products?search=NonExistentDevice12345');

        $responseProd->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [],
                    'total' => 0,
                ],
            ]);

        // Search for non-existent asset
        $responseAsset = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->withHeader('X-Branch-Id', (string) $this->branchA1->id)
            ->getJson('/api/v1/assets?search=NonExistentSerialXYZ');

        $responseAsset->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [],
                    'total' => 0,
                ],
            ]);
    }

    /**
     * Scenario 5: Filters that return no results.
     */
    public function test_filters_returning_no_results(): void
    {
        $category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Smartphone',
            'slug' => 'smartphone',
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'name' => 'iPhone 15',
            'brand' => 'Apple',
            'slug' => 'iphone-15',
            'is_active' => true,
        ]);

        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $model->id,
            'asset_code' => 'IP15-001',
            'serial_number' => 'SN-12345',
            'status' => 'ready',
        ]);

        // Filter by status 'maintenance' (0 results)
        $responseStatus = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->withHeader('X-Branch-Id', (string) $this->branchA1->id)
            ->getJson('/api/v1/assets?status=maintenance');

        $responseStatus->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [],
                    'total' => 0,
                ],
            ]);

        // Filter by category 9999 (0 results)
        $responseCat = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/products?category_id=9999');

        $responseCat->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [],
                    'total' => 0,
                ],
            ]);
    }

    /**
     * Scenario 6: Pagination after the last record on a page is removed.
     */
    public function test_pagination_resets_to_last_page_when_page_out_of_bounds(): void
    {
        $category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Camera',
            'slug' => 'camera',
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'name' => 'Sony A7 IV',
            'brand' => 'Sony',
            'slug' => 'sony-a7-iv',
            'is_active' => true,
        ]);

        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $model->id,
            'asset_code' => 'CAM-001',
            'serial_number' => 'SN-CAM-1',
            'status' => 'ready',
        ]);

        // Request page=5 with per_page=10 (only 1 item exists)
        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->withHeader('X-Branch-Id', (string) $this->branchA1->id)
            ->getJson('/api/v1/assets?page=5&per_page=10');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(1, $data['current_page']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals('CAM-001', $data['data'][0]['asset_code']);
    }

    /**
     * Scenario 7: Existing records remain visible to authorized users.
     */
    public function test_existing_records_remain_visible(): void
    {
        $category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Audio',
            'slug' => 'audio',
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'name' => 'DJI Mic 2',
            'brand' => 'DJI',
            'slug' => 'dji-mic-2',
            'is_active' => true,
        ]);

        Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $model->id,
            'asset_code' => 'MIC-001',
            'serial_number' => 'SN-MIC-1',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->withHeader('X-Branch-Id', (string) $this->branchA1->id)
            ->getJson('/api/v1/assets');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonFragment(['asset_code' => 'MIC-001']);
    }

    /**
     * Scenario 8: Tenant and branch isolation remain intact.
     */
    public function test_tenant_and_branch_isolation_intact_for_empty_and_populated(): void
    {
        // Business B has 1 asset
        $categoryB = AssetCategory::create([
            'business_id' => $this->businessB->id,
            'name' => 'Drone',
            'slug' => 'drone',
            'is_active' => true,
        ]);

        $modelB = AssetModel::create([
            'business_id' => $this->businessB->id,
            'category_id' => $categoryB->id,
            'name' => 'DJI Mini 4 Pro',
            'brand' => 'DJI',
            'slug' => 'dji-mini-4-pro',
            'is_active' => true,
        ]);

        Asset::create([
            'business_id' => $this->businessB->id,
            'affiliate_id' => $this->branchB1->id,
            'model_id' => $modelB->id,
            'asset_code' => 'DRN-001',
            'serial_number' => 'SN-DRN-1',
            'status' => 'ready',
        ]);

        // Owner A queries assets: should NOT see Business B's asset (empty for A)
        $responseA = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->withHeader('X-Branch-Id', (string) $this->branchA1->id)
            ->getJson('/api/v1/assets');

        $responseA->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'data' => [],
                    'total' => 0,
                ],
            ]);

        // Owner B queries: sees DRN-001
        $responseB = $this->actingAs($this->ownerB, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessB->id)
            ->withHeader('X-Branch-Id', (string) $this->branchB1->id)
            ->getJson('/api/v1/assets');

        $responseB->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonFragment(['asset_code' => 'DRN-001']);
    }

    /**
     * Scenario 9: AssetController::models filters by category_id and search correctly.
     */
    public function test_models_endpoint_filters_by_category_and_search(): void
    {
        $cat1 = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Phone',
            'slug' => 'phone',
            'is_active' => true,
        ]);

        $cat2 = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Tablet',
            'slug' => 'tablet',
            'is_active' => true,
        ]);

        AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $cat1->id,
            'name' => 'iPhone 15',
            'brand' => 'Apple',
            'slug' => 'iphone-15',
            'is_active' => true,
        ]);

        AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $cat2->id,
            'name' => 'iPad Air 5',
            'brand' => 'Apple',
            'slug' => 'ipad-air-5',
            'is_active' => true,
        ]);

        // Filter by category Phone
        $responseCat = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/assets/models?category_id=' . $cat1->id);

        $responseCat->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'iPhone 15']);

        // Search for iPad
        $responseSearch = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->businessA->id)
            ->getJson('/api/v1/assets/models?search=iPad');

        $responseSearch->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'iPad Air 5']);
    }
}
