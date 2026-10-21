<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryEditingTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Business $businessB;
    protected Affiliate $branchA1;
    protected Affiliate $branchA2;
    protected Affiliate $branchB1;
    protected User $ownerA;
    protected User $staffA1;
    protected User $ownerB;
    protected AssetCategory $category;
    protected AssetModel $modelA;
    protected Asset $assetA;

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

        // Staff assigned only to branchA1
        $this->staffA1 = User::create([
            'name' => 'Staff Alpha 1',
            'email' => 'staff1@alpha.com',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA1->id,
            'is_active' => true,
        ]);

        $staffMember = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA1->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $staffMember->id,
            'affiliate_id' => $this->branchA1->id,
        ]);

        // Business B (for cross-tenant checks)
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
            'code' => 'BT1',
            'name' => 'Beta Branch 1',
            'slug' => 'beta-branch-1',
            'is_central' => true,
            'is_active' => true,
        ]);

        $ownerBMember = BusinessMember::create([
            'business_id' => $this->businessB->id,
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $ownerBMember->id,
            'affiliate_id' => $this->branchB1->id,
        ]);

        // Category and Model for Business A
        $this->category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Kamera & Lensa',
            'slug' => 'kamera-lensa',
            'is_active' => true,
        ]);

        $this->modelA = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'name' => 'Sony Alpha A7 III',
            'slug' => 'sony-alpha-a7-iii',
            'description' => 'Kamera mirrorless full-frame 24MP',
            'color' => 'Hitam',
            'storage_capacity' => null,
            'standard_accessories' => ['Battery', 'Strap'],
            'has_serial_number' => true,
            'requires_frp_check' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->businessA->id,
            'model_id' => $this->modelA->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 250000.00,
            'extension_hourly_rate' => 20000.00,
            'is_active' => true,
        ]);

        // Physical unit under branchA1
        $this->assetA = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelA->id,
            'asset_code' => 'CAM-001',
            'serial_number' => 'SN-CAM-001',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => null,
            'notes' => 'Unit prima dengan lensa kit',
        ]);
    }

    /**
     * Scenario 1: An authorized user can edit an inventory catalog successfully.
     */
    public function test_authorized_user_can_edit_inventory_catalog_successfully(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/products/{$this->modelA->id}", [
                'name' => 'Sony Alpha A7 III Mark Pro',
                'description' => 'Deskripsi diperbarui dengan resolusi tinggi',
                'brand' => 'Sony Pro',
                'color' => 'Black Matte',
                'standard_accessories' => ['2x Battery', 'Dual Charger', 'Strap', 'Bag'],
                'rates' => [
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 1,
                        'price' => 300000.00,
                        'extension_hourly_rate' => 25000.00,
                    ],
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 3,
                        'price' => 800000.00,
                        'extension_hourly_rate' => 25000.00,
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Sony Alpha A7 III Mark Pro')
            ->assertJsonPath('data.brand', 'Sony Pro')
            ->assertJsonPath('data.color', 'Black Matte');

        $this->assertEquals(2, $this->modelA->fresh()->pricingTiers()->where('is_active', true)->count());
    }

    /**
     * Scenario 2: An authorized user can edit an individual inventory unit successfully.
     */
    public function test_authorized_user_can_edit_individual_inventory_unit_successfully(): void
    {
        $token = $this->staffA1->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->withHeader('X-Branch-Id', (string)$this->branchA1->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'asset_code' => 'CAM-001-REV',
                'serial_number' => 'SN-CAM-001-REV',
                'condition_grade' => 'B',
                'battery_health' => 95,
                'notes' => 'Lensa ada sedikit goresan tipis',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asset_code', 'CAM-001-REV')
            ->assertJsonPath('data.serial_number', 'SN-CAM-001-REV')
            ->assertJsonPath('data.condition_grade', 'B')
            ->assertJsonPath('data.battery_health', 95);
    }

    /**
     * Scenario 3: Updated values persist correctly in the database.
     */
    public function test_updated_values_persist_correctly_in_database(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'asset_code' => 'PERSIST-001',
                'serial_number' => 'SN-PERSIST-001',
                'notes' => 'Catatan tersimpan dengan benar',
            ])->assertStatus(200);

        $fresh = $this->assetA->fresh();
        $this->assertEquals('PERSIST-001', $fresh->asset_code);
        $this->assertEquals('SN-PERSIST-001', $fresh->serial_number);
        $this->assertEquals('Catatan tersimpan dengan benar', $fresh->notes);
    }

    /**
     * Scenario 4: Invalid prices and malformed identifiers are rejected.
     */
    public function test_invalid_prices_and_malformed_identifiers_are_rejected(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Negative price in catalog edit
        $responseNegativePrice = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/products/{$this->modelA->id}", [
                'rates' => [
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 1,
                        'price' => -50000,
                    ],
                ],
            ]);
        $responseNegativePrice->assertStatus(422)
            ->assertJsonValidationErrors(['rates.0.price']);

        // Invalid condition grade and battery health out of range in unit edit
        $responseInvalidUnit = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'condition_grade' => 'Z',
                'battery_health' => 150,
            ]);
        $responseInvalidUnit->assertStatus(422)
            ->assertJsonValidationErrors(['condition_grade', 'battery_health']);
    }

    /**
     * Scenario 5: Duplicate serial numbers or asset codes are rejected within the correct scope.
     */
    public function test_duplicate_serial_numbers_or_asset_codes_are_rejected_within_same_business(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Create second asset in Business A
        $asset2 = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->modelA->id,
            'asset_code' => 'CAM-002',
            'serial_number' => 'SN-CAM-002',
            'status' => 'ready',
        ]);

        // Attempt to change assetA's code to CAM-002
        $responseDuplicateCode = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'asset_code' => 'CAM-002',
            ]);
        $responseDuplicateCode->assertStatus(422)
            ->assertJsonValidationErrors(['asset_code']);

        // Attempt to change assetA's serial number to SN-CAM-002
        $responseDuplicateSN = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'serial_number' => 'SN-CAM-002',
            ]);
        $responseDuplicateSN->assertStatus(422)
            ->assertJsonValidationErrors(['serial_number']);

        // Keeping its own code and serial should succeed without collision
        $responseSelfUpdate = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'asset_code' => 'CAM-001',
                'serial_number' => 'SN-CAM-001',
                'notes' => 'Self update note',
            ]);
        $responseSelfUpdate->assertStatus(200);
    }

    /**
     * Scenario 6: Users cannot edit inventory belonging to another business.
     */
    public function test_users_cannot_edit_inventory_belonging_to_another_business(): void
    {
        // Owner B attempts to edit Business A's product
        $tokenB = $this->ownerB->createToken('test')->plainTextToken;

        $responseProduct = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->businessB->id)
            ->putJson("/api/v1/products/{$this->modelA->id}", [
                'name' => 'Hacked Product Name',
            ]);
        $responseProduct->assertStatus(404);

        // Owner B attempts to edit Business A's asset
        $responseAsset = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->businessB->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'notes' => 'Hacked Asset Note',
            ]);
        $responseAsset->assertStatus(404);
    }

    /**
     * Scenario 7: Users cannot edit inventory belonging to an unauthorized branch.
     */
    public function test_users_cannot_edit_inventory_belonging_to_an_unauthorized_branch(): void
    {
        // Asset located at branchA2
        $assetBranch2 = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA2->id,
            'model_id' => $this->modelA->id,
            'asset_code' => 'CAM-BRANCH2',
            'serial_number' => 'SN-BRANCH2',
            'status' => 'ready',
        ]);

        $tokenStaff1 = $this->staffA1->createToken('test')->plainTextToken;

        // Staff assigned only to branchA1 attempts to edit asset at branchA2
        $response = $this->withHeader('Authorization', "Bearer {$tokenStaff1}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->withHeader('X-Branch-Id', (string)$this->branchA1->id)
            ->putJson("/api/v1/assets/{$assetBranch2->id}", [
                'notes' => 'Unauthorized branch edit attempt',
            ]);

        // Denied via branch access check (403 or 404 under TenantScope)
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    /**
     * Scenario 8: Users without the required permission are denied access.
     */
    public function test_users_without_the_required_permission_are_denied_access(): void
    {
        // Unauthenticated
        $this->putJson("/api/v1/assets/{$this->assetA->id}", ['notes' => 'No token'])
            ->assertStatus(401);

        // Authenticated user with no membership in business A
        $stranger = User::create([
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $tokenStranger = $stranger->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenStranger}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", ['notes' => 'Stranger edit'])
            ->assertStatus(403);
    }

    /**
     * Scenario 9: Existing bookings and completed transactions remain intact after inventory edits.
     */
    public function test_existing_bookings_and_completed_transactions_remain_intact_after_inventory_edits(): void
    {
        $customer = Customer::create([
            'business_id' => $this->businessA->id,
            'name' => 'John Doe Customer',
            'phone' => '081234567890',
        ]);

        $booking = Booking::create([
            'business_id' => $this->businessA->id,
            'booking_code' => 'BK-TEST-001',
            'affiliate_id' => $this->branchA1->id,
            'customer_id' => $customer->id,
            'created_by' => $this->ownerA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addDay(),
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 250000.00,
            'total_bill' => 250000.00,
            'payment_status' => 'paid',
        ]);

        $item = BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $this->assetA->id,
            'unit_price' => 250000.00,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'rate_snapshot' => [
                'price' => 250000.00,
                'extension_hourly_rate' => 20000.00,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ],
        ]);

        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Now edit catalog model rate from 250k to 500k
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/products/{$this->modelA->id}", [
                'rates' => [
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 1,
                        'price' => 500000.00,
                    ],
                ],
            ])->assertStatus(200);

        // Now edit asset serial number
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'serial_number' => 'SN-CAM-001-NEW',
            ])->assertStatus(200);

        // Verify booking item snapshot and subtotal remain completely unchanged!
        $freshItem = $item->fresh();
        $this->assertEquals(250000.00, (float)$freshItem->unit_price);
        $this->assertEquals(250000.00, (float)$freshItem->rate_snapshot['price']);
        $this->assertEquals(250000.00, (float)$booking->fresh()->total_bill);

        // Changing model_id on an asset that has booking history must be rejected
        $model2 = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'name' => 'Sony Alpha A7 IV',
            'slug' => 'sony-alpha-a7-iv',
            'is_active' => true,
        ]);

        $respModelChange = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'model_id' => $model2->id,
            ]);
        $respModelChange->assertStatus(422)
            ->assertJsonPath('code', 'MODEL_CHANGE_RESTRICTED');
    }

    /**
     * Scenario 10: Active rental units can receive safe descriptive updates without corrupting their rental status.
     */
    public function test_active_rental_units_can_receive_safe_descriptive_updates_without_corrupting_rental_status(): void
    {
        // Mark asset as rented
        $this->assetA->update(['status' => 'rented']);

        $token = $this->ownerA->createToken('test')->plainTextToken;

        // 1. Safe descriptive edit: update notes and serial number without changing status
        $safeResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'serial_number' => 'SN-CAM-001-CORRECTED',
                'notes' => 'Koreksi serial saat unit masih disewa',
                'condition_grade' => 'A',
            ]);

        $safeResponse->assertStatus(200)
            ->assertJsonPath('data.serial_number', 'SN-CAM-001-CORRECTED')
            ->assertJsonPath('data.status', 'rented');

        $this->assertEquals('rented', $this->assetA->fresh()->status);

        // 2. Unsafe edit: attempt to manually flip status to 'ready' while rented must be rejected
        $unsafeResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'status' => 'ready',
            ]);

        $unsafeResponse->assertStatus(422)
            ->assertJsonPath('code', 'RENTED_STATUS_LOCKED');

        $this->assertEquals('rented', $this->assetA->fresh()->status);
    }

    /**
     * Scenario 11: Existing inventory creation, search, filtering, and pagination continue to work.
     */
    public function test_existing_inventory_creation_search_filtering_and_pagination_continue_to_work(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Creation of new unit
        $createResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->withHeader('X-Branch-Id', (string)$this->branchA1->id)
            ->postJson('/api/v1/assets', [
                'model_id' => $this->modelA->id,
                'asset_code' => 'CAM-NEW-099',
                'serial_number' => 'SN-NEW-099',
                'condition_grade' => 'A',
            ]);
        $createResp->assertStatus(201);

        // Search by asset code
        $searchResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/assets?search=099');
        $searchResp->assertStatus(200)
            ->assertJsonPath('data.data.0.asset_code', 'CAM-NEW-099');

        // Filter by category
        $catFilterResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson("/api/v1/assets?category_id={$this->category->id}");
        $catFilterResp->assertStatus(200);

        // Filter by status
        $statusResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->getJson('/api/v1/assets?status=ready');
        $statusResp->assertStatus(200);
    }

    /**
     * Scenario 12: API validation and authorization behave consistently for supported Flutter workflows.
     */
    public function test_api_validation_and_authorization_behave_consistently_for_flutter_workflows(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // Reassigning branch directly via edit must be rejected
        $respBranchReassign = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'affiliate_id' => $this->branchA2->id,
            ]);
        $respBranchReassign->assertStatus(422)
            ->assertJsonPath('code', 'BRANCH_REASSIGNMENT_FORBIDDEN');

        // Reassigning business directly via edit must be rejected
        $respBizReassign = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->businessA->id)
            ->putJson("/api/v1/assets/{$this->assetA->id}", [
                'business_id' => $this->businessB->id,
            ]);
        $respBizReassign->assertStatus(422)
            ->assertJsonPath('code', 'BUSINESS_REASSIGNMENT_FORBIDDEN');
    }
}
