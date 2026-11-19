<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchTenantAvailabilityIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Affiliate $branchPusat;
    protected Affiliate $branchSelatan;
    protected User $ownerA;
    protected User $staffSelatan;
    protected AssetModel $pusatOnlyIphone;
    protected AssetModel $selatanIphone;
    protected Asset $pusatUnit;
    protected Asset $selatanUnit;

    protected Business $businessB;
    protected Affiliate $branchForeign;
    protected AssetModel $foreignModel;
    protected Asset $foreignUnit;

    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'type' => 'cash',
            'is_active' => true,
        ]);

        // Business A
        $this->ownerA = User::create([
            'name' => 'Owner A',
            'email' => 'owner.iso.a@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->businessA = Business::create([
            'name' => 'Rental A',
            'slug' => 'rental-a-iso',
            'owner_id' => $this->ownerA->id,
            'shift_enforcement_enabled' => false,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->branchPusat = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'PST',
            'slug' => 'cabang-pusat-iso',
            'name' => 'Cabang Pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchSelatan = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'SLT',
            'slug' => 'cabang-selatan-iso',
            'name' => 'Cabang Selatan',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->staffSelatan = User::create([
            'name' => 'Staff Selatan',
            'email' => 'staff.slt@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $bmSelatan = BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffSelatan->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
        BranchMember::create([
            'business_member_id' => $bmSelatan->id,
            'affiliate_id' => $this->branchSelatan->id,
        ]);

        $catA = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones-iso-a',
            'is_addon' => false,
            'is_active' => true,
        ]);

        // Model 1: Only stocked at Cabang Pusat
        $this->pusatOnlyIphone = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $catA->id,
            'brand' => 'Apple',
            'name' => 'iPhone 16 Pro Max Pusat',
            'slug' => 'iphone-16-promax-pusat',
            'is_addon' => false,
            'is_active' => true,
        ]);
        PricingTier::create([
            'model_id' => $this->pusatOnlyIphone->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 450000,
            'is_active' => true,
        ]);
        $this->pusatUnit = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchPusat->id,
            'model_id' => $this->pusatOnlyIphone->id,
            'asset_code' => 'PST-IP16-01',
            'serial_number' => 'SN-PST-1601',
            'status' => 'ready',
        ]);

        // Model 2: Stocked at Cabang Selatan
        $this->selatanIphone = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $catA->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Selatan',
            'slug' => 'iphone-15-selatan',
            'is_addon' => false,
            'is_active' => true,
        ]);
        PricingTier::create([
            'model_id' => $this->selatanIphone->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 300000,
            'is_active' => true,
        ]);
        $this->selatanUnit = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchSelatan->id,
            'model_id' => $this->selatanIphone->id,
            'asset_code' => 'SLT-IP15-01',
            'serial_number' => 'SN-SLT-1501',
            'status' => 'ready',
        ]);

        // Foreign Business B
        $ownerB = User::create([
            'name' => 'Owner B',
            'email' => 'owner.iso.b@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $this->businessB = Business::create([
            'name' => 'Rental B',
            'slug' => 'rental-b-iso',
            'owner_id' => $ownerB->id,
            'shift_enforcement_enabled' => false,
            'is_active' => true,
        ]);
        $this->branchForeign = Affiliate::create([
            'business_id' => $this->businessB->id,
            'code' => 'FGN',
            'slug' => 'foreign-branch-iso',
            'name' => 'Cabang Bisnis B',
            'is_central' => true,
            'is_active' => true,
        ]);
        $catB = AssetCategory::create([
            'business_id' => $this->businessB->id,
            'name' => 'Smartphones B',
            'slug' => 'smartphones-iso-b',
            'is_addon' => false,
            'is_active' => true,
        ]);
        $this->foreignModel = AssetModel::create([
            'business_id' => $this->businessB->id,
            'category_id' => $catB->id,
            'brand' => 'Samsung',
            'name' => 'Galaxy S25 Foreign',
            'slug' => 'galaxy-s25-foreign',
            'is_addon' => false,
            'is_active' => true,
        ]);
        $this->foreignUnit = Asset::create([
            'business_id' => $this->businessB->id,
            'affiliate_id' => $this->branchForeign->id,
            'model_id' => $this->foreignModel->id,
            'asset_code' => 'FGN-S25-01',
            'serial_number' => 'SN-FGN-01',
            'status' => 'ready',
        ]);
    }

    protected function authHeaders(User $user, Business $business, ?Affiliate $branch = null): array
    {
        $this->flushHeaders();
        auth()->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $business->id,
        ];
        if ($branch !== null) {
            $headers['X-Branch-Id'] = (string) $branch->id;
        }
        return $headers;
    }

    public function test_scheduled_availability_excludes_zero_stock_pusat_models_and_rejects_consolidated_or_foreign_branch(): void
    {
        $startAt = Carbon::now()->addHours(4)->toIso8601String();
        $endAt = Carbon::now()->addHours(28)->toIso8601String();

        // 1. Secondary branch (Cabang Selatan) only sees iPhone 15 Selatan, never iPhone 16 Pro Max Pusat
        foreach (['/api/v1/bookings/availability', '/api/v1/assets/models/availability', '/api/v1/assets/models'] as $uri) {
            $res = $this->withHeaders($this->authHeaders($this->staffSelatan, $this->businessA, $this->branchSelatan))
                ->getJson($uri . '?' . http_build_query([
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'branch_id' => $this->branchSelatan->id,
                ]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $this->selatanIphone->id)
                ->assertJsonPath('data.0.total_units', 1)
                ->assertJsonPath('data.0.available_slots', 1);

            $returnedIds = collect($res->json('data'))->pluck('id')->all();
            $this->assertNotContains($this->pusatOnlyIphone->id, $returnedIds);
            $this->assertNotContains($this->foreignModel->id, $returnedIds);
        }

        // 2. Owner omitting branch_id/X-Branch-Id or passing all_branches/all is rejected with 422 (no silent central fallback)
        $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA))
            ->getJson('/api/v1/bookings/availability?' . http_build_query([
                'start_at' => $startAt,
                'end_at' => $endAt,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'BRANCH_REQUIRED');

        $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA))
            ->getJson('/api/v1/bookings/availability?' . http_build_query([
                'start_at' => $startAt,
                'end_at' => $endAt,
                'branch_id' => 'all',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'BRANCH_REQUIRED');

        // 3. Cross-tenant IDOR: Owner A passing foreign Business B branch_id is blocked with 422 INVALID_BRANCH
        $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA))
            ->getJson('/api/v1/bookings/availability?' . http_build_query([
                'start_at' => $startAt,
                'end_at' => $endAt,
                'branch_id' => $this->branchForeign->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_BRANCH');

        $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA))
            ->getJson('/api/v1/assets?' . http_build_query([
                'start_at' => $startAt,
                'end_at' => $endAt,
                'branch_id' => $this->branchForeign->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_BRANCH');

        // 4. Branch staff of Cabang Selatan probing Cabang Pusat is blocked with 403 BRANCH_ACCESS_DENIED
        $this->withHeaders($this->authHeaders($this->staffSelatan, $this->businessA, $this->branchSelatan))
            ->getJson('/api/v1/bookings/availability?' . http_build_query([
                'start_at' => $startAt,
                'end_at' => $endAt,
                'branch_id' => $this->branchPusat->id,
            ]))
            ->assertStatus(403);
    }

    public function test_booking_creation_rejects_models_and_units_from_other_branch_or_foreign_business(): void
    {
        CashDrawerShift::create([
            'business_id' => $this->businessA->id,
            'branch_id' => $this->branchSelatan->id,
            'opened_by' => $this->ownerA->id,
            'shift_number' => 'SHF-SLT-01',
            'opening_cash_float' => 100000,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        $startAt = Carbon::now()->addHours(4)->toIso8601String();

        // 1. Floating scheduled booking in Cabang Selatan using Pusat-only model -> 422
        $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA, $this->branchSelatan))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'affiliate_id' => $this->branchSelatan->id,
                'customer_name' => 'Rina',
                'customer_phone' => '081234567890',
                'asset_model_id' => $this->pusatOnlyIphone->id,
                'start_at' => $startAt,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.']);

        // 2. Pinned scheduled booking in Cabang Selatan using Pusat physical unit -> 422
        $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA, $this->branchSelatan))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'affiliate_id' => $this->branchSelatan->id,
                'customer_name' => 'Rina',
                'customer_phone' => '081234567890',
                'asset_ids' => [$this->pusatUnit->id],
                'start_at' => $startAt,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.']);

        // 3. Direct counter rental in Cabang Selatan using foreign Business B unit -> 422
        $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA, $this->branchSelatan))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => false,
                'booking_mode' => 'direct',
                'affiliate_id' => $this->branchSelatan->id,
                'customer_name' => 'Rina',
                'customer_phone' => '081234567890',
                'asset_ids' => [$this->foreignUnit->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 300000,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.']);

        // 4. Valid scheduled floating booking in Cabang Selatan using explicit branch_id (without affiliate_id or X-Branch-Id) -> 201
        $selatanRes = $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'branch_id' => $this->branchSelatan->id,
                'customer_name' => 'Rina',
                'customer_phone' => '081234567890',
                'asset_model_id' => $this->selatanIphone->id,
                'start_at' => $startAt,
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 100000,
                'cash_received' => 100000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.affiliate_id', $this->branchSelatan->id)
            ->assertJsonPath('data.asset_model_id', $this->selatanIphone->id);

        $selatanBookingId = (int) $selatanRes->json('data.id');
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $selatanBookingId,
            'branch_id' => $this->branchSelatan->id,
        ]);

        // 5. Missing branch_id as Owner (no X-Branch-Id) -> 422
        $this->withHeaders($this->authHeaders($this->ownerA, $this->businessA))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'customer_name' => 'Rina',
                'customer_phone' => '081234567890',
                'asset_model_id' => $this->selatanIphone->id,
                'start_at' => $startAt,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);

        // 6. Staff of Cabang Selatan passing branch_id of Cabang Pusat -> 403/422 rejected
        $this->withHeaders($this->authHeaders($this->staffSelatan, $this->businessA, $this->branchSelatan))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'branch_id' => $this->branchPusat->id,
                'customer_name' => 'Rina',
                'customer_phone' => '081234567890',
                'asset_model_id' => $this->pusatOnlyIphone->id,
                'start_at' => $startAt,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ])
            ->assertStatus(422);
    }
}
