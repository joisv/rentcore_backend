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

/**
 * Regression suite: after iPhones are transferred from the main store to Branch A, the
 * receiving branch must see them in inventory, catalog, and booking-unit selection, while
 * the main store must not.
 *
 * Root cause covered here: TenantScope on Affiliate is fail-closed when no TenantContext is
 * bound. (1) TenantContextMiddleware resolved the branch BEFORE binding the context, so the
 * branch was always null; (2) the /businesses and /auth/me payloads returned no branches.
 * The client therefore sent no X-Branch-Id, and AssetController defaulted to the CENTRAL
 * branch, so Branch A staff got 403 (and owners silently got main-store data).
 *
 * Unlike older suites, these tests mimic the real client: the branch is discovered from the
 * /businesses payload, or no branch header is sent at all.
 */
class TransferredInventoryBranchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Business $foreignBusiness;
    protected Affiliate $mainStore;
    protected Affiliate $branchA;
    protected Affiliate $branchC;
    protected Affiliate $foreignBranch;
    protected User $owner;
    protected User $staffMain;
    protected User $staffX;
    protected User $staffOtherA;
    protected AssetModel $iphone11;
    protected Asset $purpleIphone;
    protected Asset $mainStoreOnlyIphone;
    protected Asset $branchCIphone;
    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cash = PaymentMethod::create(['code' => 'CASH', 'name' => 'Tunai', 'type' => 'cash', 'is_active' => true]);

        $this->owner = $this->makeUser('owner@visibility.test', 'owner');
        $this->business = Business::create([
            'name' => 'Business A', 'slug' => 'business-a', 'owner_id' => $this->owner->id, 'is_active' => true,
        ]);
        BusinessMember::create(['business_id' => $this->business->id, 'user_id' => $this->owner->id, 'role' => 'owner', 'is_active' => true, 'joined_at' => Carbon::now()]);

        // Branch A is created FIRST (lower id) on purpose: ordering must be "central first",
        // not "by id", otherwise the owner's default would not be the main store.
        $this->branchA = Affiliate::create(['business_id' => $this->business->id, 'code' => 'BR-A', 'name' => 'Branch A', 'slug' => 'branch-a', 'is_central' => false, 'is_active' => true]);
        $this->mainStore = Affiliate::create(['business_id' => $this->business->id, 'code' => 'MAIN', 'name' => 'Main Store', 'slug' => 'main-store', 'is_central' => true, 'is_active' => true]);
        $this->branchC = Affiliate::create(['business_id' => $this->business->id, 'code' => 'BR-C', 'name' => 'Branch C', 'slug' => 'branch-c', 'is_central' => false, 'is_active' => true]);

        $this->staffMain = $this->makeStaff('staff.main@visibility.test', $this->mainStore);
        $this->staffX = $this->makeStaff('staff.x@visibility.test', $this->branchA);
        $this->staffOtherA = $this->makeStaff('staff.other.a@visibility.test', $this->branchA);

        $foreignOwner = $this->makeUser('foreign.owner@visibility.test', 'owner');
        $this->foreignBusiness = Business::create(['name' => 'Foreign', 'slug' => 'foreign', 'owner_id' => $foreignOwner->id, 'is_active' => true]);
        $this->foreignBranch = Affiliate::create(['business_id' => $this->foreignBusiness->id, 'code' => 'FX', 'name' => 'Foreign Branch', 'slug' => 'foreign-branch', 'is_central' => true, 'is_active' => true]);

        $category = AssetCategory::create(['business_id' => $this->business->id, 'name' => 'Smartphones', 'slug' => 'smartphones']);
        $this->iphone11 = AssetModel::create([
            'business_id' => $this->business->id, 'category_id' => $category->id, 'brand' => 'Apple',
            'name' => 'iPhone 11 128GB Purple', 'slug' => 'iphone-11-purple', 'storage_capacity' => '128GB', 'color' => 'Purple', 'is_active' => true,
        ]);
        PricingTier::create([
            'model_id' => $this->iphone11->id, 'duration_hours' => 24, 'duration_unit' => 'day', 'duration_value' => 1,
            'price' => 150000, 'extension_hourly_rate' => 10000, 'is_active' => true,
        ]);

        $this->purpleIphone = $this->makeAsset('IP11-PURPLE', $this->mainStore);
        $this->mainStoreOnlyIphone = $this->makeAsset('IP11-STAYS-MAIN', $this->mainStore);
        $this->branchCIphone = $this->makeAsset('IP11-BRANCH-C', $this->branchC);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function makeUser(string $email, string $role): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('secret123'), 'role' => $role, 'is_active' => true]);
    }

    protected function makeStaff(string $email, Affiliate $branch): User
    {
        $user = $this->makeUser($email, 'staff');
        $member = BusinessMember::create(['business_id' => $this->business->id, 'user_id' => $user->id, 'role' => 'staff', 'is_active' => true, 'joined_at' => Carbon::now()]);
        BranchMember::create(['business_member_id' => $member->id, 'affiliate_id' => $branch->id]);
        return $user;
    }

    protected function makeAsset(string $code, Affiliate $branch, string $status = 'ready'): Asset
    {
        return Asset::create([
            'business_id' => $this->business->id, 'affiliate_id' => $branch->id, 'model_id' => $this->iphone11->id,
            'asset_code' => $code, 'serial_number' => 'SN-' . $code, 'status' => $status, 'condition_grade' => 'A', 'battery_health' => 95,
        ]);
    }

    /** Authenticated request with explicit tenant headers (null branch = header omitted). */
    protected function as(User $user, ?Affiliate $branch = null, ?int $businessId = null)
    {
        auth()->forgetGuards();
        app()->forgetInstance(\App\Tenancy\TenantContext::class);
        $headers = [
            'Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken,
            'X-Business-Id' => (string)($businessId ?? $this->business->id),
        ];
        if ($branch) {
            $headers['X-Branch-Id'] = (string)$branch->id;
        }
        return $this->withHeaders($headers);
    }

    /**
     * Mimics the Flutter client: fetch /businesses (non-tenant route), take the first branch
     * of the membership as the active branch, then send it as X-Branch-Id.
     */
    protected function asClient(User $user)
    {
        auth()->forgetGuards();
        app()->forgetInstance(\App\Tenancy\TenantContext::class);
        $token = $user->createToken('t')->plainTextToken;
        $this->flushHeaders();
        $list = $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/businesses');
        $list->assertOk();
        $biz = collect($list->json('data'))->firstWhere('id', $this->business->id);
        $this->assertNotNull($biz, 'Business must be listed for its member');
        $this->assertNotEmpty($biz['branches'], 'Membership payload must expose at least one authorized branch');
        $branchId = $biz['branches'][0]['id'];

        auth()->forgetGuards();
        app()->forgetInstance(\App\Tenancy\TenantContext::class);
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string)$this->business->id,
            'X-Branch-Id' => (string)$branchId,
        ]);
    }

    protected function ids($response, string $path = 'data.data'): array
    {
        return collect($response->json($path))->pluck('id')->map(fn($v) => (int)$v)->all();
    }

    protected function bookingPayload(Affiliate $branch, Asset $asset): array
    {
        return [
            'affiliate_id' => $branch->id,
            'customer_name' => 'Pelanggan Uji',
            'customer_phone' => '0812' . random_int(10000000, 99999999),
            'customer_address' => 'Jl. Uji No. 1',
            'asset_ids' => [$asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->cash->id,
            'cash_received' => 150000,
        ];
    }

    /** Runs the agreed workflow: staff submits -> owner approves -> assigned recipient receives. */
    protected function transferToBranchA(Asset $asset): int
    {
        $submit = $this->as($this->staffMain, $this->mainStore)->postJson('/api/v1/transfers', [
            'asset_id' => $asset->id, 'to_affiliate_id' => $this->branchA->id, 'recipient_id' => $this->staffX->id, 'notes' => 'Mutasi ke Branch A',
        ]);
        $submit->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $transferId = (int)$submit->json('data.id');

        $this->as($this->owner, $this->mainStore)->postJson("/api/v1/transfers/{$transferId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'in_transit');

        $this->as($this->staffX, $this->branchA)->postJson("/api/v1/transfers/{$transferId}/receive")
            ->assertOk()->assertJsonPath('data.status', 'received');

        return $transferId;
    }

    // ---------------------------------------------------------------------
    // Branch context payloads (root cause)
    // ---------------------------------------------------------------------

    public function test_membership_payload_lists_only_authorized_branches_central_first(): void
    {
        $staffList = $this->as($this->staffX)->getJson('/api/v1/businesses')->assertOk();
        $staffBiz = collect($staffList->json('data'))->firstWhere('id', $this->business->id);
        $this->assertSame([$this->branchA->id], collect($staffBiz['branches'])->pluck('id')->all());

        $ownerList = $this->as($this->owner)->getJson('/api/v1/businesses')->assertOk();
        $ownerBiz = collect($ownerList->json('data'))->firstWhere('id', $this->business->id);
        $this->assertSame($this->mainStore->id, $ownerBiz['branches'][0]['id'], 'Central branch must be listed first');
        $this->assertEqualsCanonicalizing(
            [$this->mainStore->id, $this->branchA->id, $this->branchC->id],
            collect($ownerBiz['branches'])->pluck('id')->all()
        );

        $me = $this->as($this->staffX)->getJson('/api/v1/auth/me')->assertOk();
        $meBiz = collect($me->json('data.businesses'))->firstWhere('id', $this->business->id);
        $this->assertSame([$this->branchA->id], collect($meBiz['branches'])->pluck('id')->all());
    }

    public function test_branch_staff_without_branch_header_resolves_to_assigned_branch(): void
    {
        $this->transferToBranchA($this->purpleIphone);

        // No X-Branch-Id at all: the middleware must resolve Branch A (not default to central).
        $res = $this->as($this->staffX)->getJson('/api/v1/assets');
        $res->assertOk();
        $this->assertContains($this->purpleIphone->id, $this->ids($res));
        $this->assertNotContains($this->mainStoreOnlyIphone->id, $this->ids($res));
    }

    // ---------------------------------------------------------------------
    // 1–5 Transfer and branch assignment
    // ---------------------------------------------------------------------

    public function test_01_to_03_completed_transfer_updates_branch_and_keeps_history(): void
    {
        $assetCountBefore = Asset::withoutGlobalScopes()->count();
        $transferId = $this->transferToBranchA($this->purpleIphone);

        $fresh = Asset::withoutGlobalScopes()->find($this->purpleIphone->id);
        $this->assertSame($this->branchA->id, (int)$fresh->affiliate_id);
        $this->assertSame($this->business->id, (int)$fresh->business_id);
        $this->assertSame('ready', $fresh->status);

        $transfer = InventoryTransfer::withoutGlobalScopes()->find($transferId);
        $this->assertSame('received', $transfer->status);
        $this->assertSame($this->mainStore->id, (int)$transfer->from_affiliate_id);
        $this->assertSame($this->branchA->id, (int)$transfer->to_affiliate_id);
        $this->assertSame($this->staffX->id, $transfer->received_by);

        // Test 14 (E2E): no duplicate physical record was created.
        $this->assertSame($assetCountBefore, Asset::withoutGlobalScopes()->count());
        $this->assertSame(1, Asset::withoutGlobalScopes()->where('serial_number', 'SN-IP11-PURPLE')->count());
    }

    public function test_04_05_unassigned_receipt_fails_and_leaves_assignment_consistent(): void
    {
        $submit = $this->as($this->staffMain, $this->mainStore)->postJson('/api/v1/transfers', [
            'asset_id' => $this->purpleIphone->id, 'to_affiliate_id' => $this->branchA->id, 'recipient_id' => $this->staffX->id,
        ])->assertStatus(201);
        $transferId = (int)$submit->json('data.id');
        $this->as($this->owner, $this->mainStore)->postJson("/api/v1/transfers/{$transferId}/approve")->assertOk();

        // Same destination branch, but not the assigned recipient.
        $this->as($this->staffOtherA, $this->branchA)->postJson("/api/v1/transfers/{$transferId}/receive")->assertStatus(403);
        // Owner is not the assigned recipient either.
        $this->as($this->owner, $this->branchA)->postJson("/api/v1/transfers/{$transferId}/receive")->assertStatus(403);

        $fresh = Asset::withoutGlobalScopes()->find($this->purpleIphone->id);
        $this->assertSame($this->mainStore->id, (int)$fresh->affiliate_id, 'Failed completion must not move the unit');
        $this->assertSame('in_transit', $fresh->status);
        $this->assertSame('in_transit', InventoryTransfer::withoutGlobalScopes()->find($transferId)->status);

        // Not visible as ready inventory at the destination until receipt.
        $this->assertNotContains($this->purpleIphone->id, $this->ids($this->asClient($this->staffX)->getJson('/api/v1/assets?status=ready')));
    }

    // ---------------------------------------------------------------------
    // 6–10 Inventory
    // ---------------------------------------------------------------------

    public function test_06_07_inventory_follows_authoritative_branch_after_receipt(): void
    {
        $this->transferToBranchA($this->purpleIphone);

        $branchA = $this->asClient($this->staffX)->getJson('/api/v1/assets');
        $branchA->assertOk();
        $this->assertContains($this->purpleIphone->id, $this->ids($branchA));

        foreach ([$this->staffMain, $this->owner] as $mainUser) {
            $main = $this->asClient($mainUser)->getJson('/api/v1/assets');
            $main->assertOk();
            $this->assertNotContains($this->purpleIphone->id, $this->ids($main));
            $this->assertContains($this->mainStoreOnlyIphone->id, $this->ids($main));
        }
    }

    public function test_08_branch_a_inventory_excludes_other_branches_units(): void
    {
        $this->transferToBranchA($this->purpleIphone);
        $ids = $this->ids($this->asClient($this->staffX)->getJson('/api/v1/assets'));
        $this->assertNotContains($this->branchCIphone->id, $ids);
        $this->assertNotContains($this->mainStoreOnlyIphone->id, $ids);
    }

    public function test_09_inventory_does_not_depend_on_creating_branch(): void
    {
        // Registered through the API by main-store staff.
        $created = $this->as($this->staffMain, $this->mainStore)->postJson('/api/v1/assets', [
            'model_id' => $this->iphone11->id, 'asset_code' => 'IP11-CREATED-BY-MAIN', 'serial_number' => 'SN-CREATED-BY-MAIN',
        ])->assertStatus(201);
        $asset = Asset::withoutGlobalScopes()->find($created->json('data.id'));
        $this->assertSame($this->mainStore->id, (int)$asset->affiliate_id);

        $this->transferToBranchA($asset);

        $this->assertContains($asset->id, $this->ids($this->asClient($this->staffX)->getJson('/api/v1/assets')));
        $this->assertNotContains($asset->id, $this->ids($this->asClient($this->staffMain)->getJson('/api/v1/assets')));
    }

    public function test_10_forged_branch_ids_are_rejected(): void
    {
        $this->transferToBranchA($this->purpleIphone);

        // Branch A staff forging the main-store branch via header or query.
        $this->as($this->staffX, $this->mainStore)->getJson('/api/v1/assets')->assertStatus(403);
        $this->as($this->staffX, $this->branchA)->getJson('/api/v1/assets?branch_id=' . $this->mainStore->id)->assertStatus(403);
        // Main-store staff forging Branch A to reach the transferred unit.
        $this->as($this->staffMain, $this->branchA)->getJson('/api/v1/assets')->assertStatus(403);
        // Branch from another business.
        $this->as($this->owner, $this->mainStore)->getJson('/api/v1/assets?branch_id=' . $this->foreignBranch->id)->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // 11–14 Catalog
    // ---------------------------------------------------------------------

    public function test_11_to_13_branch_a_catalog_shows_transferred_inventory(): void
    {
        $this->transferToBranchA($this->purpleIphone);

        $catalog = $this->asClient($this->staffX)->getJson('/api/v1/products?all=1');
        $catalog->assertOk();
        $entry = collect($catalog->json('data'))->firstWhere('id', $this->iphone11->id);
        $this->assertNotNull($entry, 'Model must not be hidden because another branch created it');
        $this->assertSame(1, (int)$entry['assets_count']);
        $this->assertSame(1, (int)$entry['ready_units_count']);
        $this->assertNotEmpty($entry['pricing_tiers']);

        $detail = $this->asClient($this->staffX)->getJson("/api/v1/products/{$this->iphone11->id}");
        $detail->assertOk();
        $units = collect($detail->json('data.assets'));
        $this->assertSame([$this->purpleIphone->id], $units->pluck('id')->map(fn($v) => (int)$v)->all());
        $this->assertSame($this->branchA->id, (int)$units->first()['affiliate_id']);

        // 13: existing availability semantics — a rented unit still counts in fleet but not ready.
        $this->as($this->staffX, $this->branchA)->postJson('/api/v1/bookings', $this->bookingPayload($this->branchA, $this->purpleIphone))->assertStatus(201);
        $after = collect($this->asClient($this->staffX)->getJson('/api/v1/products?all=1')->json('data'))->firstWhere('id', $this->iphone11->id);
        $this->assertSame(1, (int)$after['assets_count']);
        $this->assertSame(0, (int)$after['ready_units_count']);
    }

    public function test_14_main_store_catalog_does_not_count_transferred_units(): void
    {
        $this->transferToBranchA($this->purpleIphone);

        foreach ([$this->staffMain, $this->owner] as $mainUser) {
            $entry = collect($this->asClient($mainUser)->getJson('/api/v1/products?all=1')->json('data'))->firstWhere('id', $this->iphone11->id);
            $this->assertNotNull($entry);
            $this->assertSame(1, (int)$entry['assets_count'], 'Only the unit still at the main store');
            $this->assertSame(1, (int)$entry['ready_units_count']);

            $units = collect($this->asClient($mainUser)->getJson("/api/v1/products/{$this->iphone11->id}")->json('data.assets'))->pluck('id')->map(fn($v) => (int)$v)->all();
            $this->assertNotContains($this->purpleIphone->id, $units);
        }

        // Business-wide administrative view stays available to the Owner only.
        $all = collect($this->asClient($this->owner)->getJson('/api/v1/products?all=1&all_branches=1')->json('data'))->firstWhere('id', $this->iphone11->id);
        $this->assertSame(3, (int)$all['assets_count']);
        $restricted = collect($this->asClient($this->staffMain)->getJson('/api/v1/products?all=1&all_branches=1')->json('data'))->firstWhere('id', $this->iphone11->id);
        $this->assertSame(1, (int)$restricted['assets_count'], 'Branch staff cannot widen the scope with all_branches');
    }

    // ---------------------------------------------------------------------
    // 15–20 Booking
    // ---------------------------------------------------------------------

    public function test_15_16_branch_a_booking_units_and_booking_succeed(): void
    {
        $this->transferToBranchA($this->purpleIphone);

        $units = $this->asClient($this->staffX)->getJson('/api/v1/assets?status=ready&branch_id=' . $this->branchA->id);
        $units->assertOk();
        $this->assertContains($this->purpleIphone->id, $this->ids($units));

        $this->asClient($this->staffX)->postJson('/api/v1/bookings', $this->bookingPayload($this->branchA, $this->purpleIphone))
            ->assertStatus(201)->assertJsonPath('success', true);

        $fresh = Asset::withoutGlobalScopes()->find($this->purpleIphone->id);
        $this->assertSame('rented', $fresh->status);
        $this->assertSame($this->branchA->id, (int)$fresh->affiliate_id);
    }

    public function test_17_main_store_cannot_book_transferred_unit(): void
    {
        $this->transferToBranchA($this->purpleIphone);

        $this->assertNotContains($this->purpleIphone->id, $this->ids($this->asClient($this->staffMain)->getJson('/api/v1/assets?status=ready')));

        foreach ([$this->staffMain, $this->owner] as $mainUser) {
            $this->as($mainUser, $this->mainStore)->postJson('/api/v1/bookings', $this->bookingPayload($this->mainStore, $this->purpleIphone))
                ->assertStatus(422)->assertJsonValidationErrors(['asset_ids']);
        }
        $this->assertSame('ready', Asset::withoutGlobalScopes()->find($this->purpleIphone->id)->status);
    }

    public function test_18_branch_a_cannot_book_unit_of_another_branch(): void
    {
        $this->as($this->staffX, $this->branchA)->postJson('/api/v1/bookings', $this->bookingPayload($this->branchA, $this->branchCIphone))
            ->assertStatus(422)->assertJsonValidationErrors(['asset_ids']);
        // Nor by claiming Branch C as the booking branch (422 payload mismatch against active branch)
        $this->as($this->staffX, $this->branchA)->postJson('/api/v1/bookings', $this->bookingPayload($this->branchC, $this->branchCIphone))
            ->assertStatus(422)->assertJsonValidationErrors(['affiliate_id']);
        // Nor by forging Branch C in the header (middleware rejects with 403)
        $this->as($this->staffX, $this->branchC)->postJson('/api/v1/bookings', $this->bookingPayload($this->branchC, $this->branchCIphone))
            ->assertStatus(403);
    }

    public function test_19_unavailable_states_remain_unavailable(): void
    {
        $maintenance = $this->makeAsset('IP11-MAINT', $this->branchA, 'maintenance');
        $rented = $this->makeAsset('IP11-RENTED', $this->branchA, 'rented');
        $reserved = $this->makeAsset('IP11-RESERVED', $this->branchA, 'reserved');

        $ready = $this->ids($this->asClient($this->staffX)->getJson('/api/v1/assets?status=ready'));
        foreach ([$maintenance, $rented, $reserved] as $asset) {
            $this->assertNotContains($asset->id, $ready);
            $this->as($this->staffX, $this->branchA)->postJson('/api/v1/bookings', $this->bookingPayload($this->branchA, $asset))->assertStatus(422);
        }

        // In transit: bookable at neither branch.
        $submit = $this->as($this->staffMain, $this->mainStore)->postJson('/api/v1/transfers', [
            'asset_id' => $this->purpleIphone->id, 'to_affiliate_id' => $this->branchA->id, 'recipient_id' => $this->staffX->id,
        ])->assertStatus(201);
        $this->as($this->owner, $this->mainStore)->postJson('/api/v1/transfers/' . $submit->json('data.id') . '/approve')->assertOk();
        $this->as($this->staffMain, $this->mainStore)->postJson('/api/v1/bookings', $this->bookingPayload($this->mainStore, $this->purpleIphone))->assertStatus(422);
        $this->as($this->staffX, $this->branchA)->postJson('/api/v1/bookings', $this->bookingPayload($this->branchA, $this->purpleIphone))->assertStatus(422);
    }

    public function test_20_non_transferred_inventory_keeps_working(): void
    {
        $this->transferToBranchA($this->purpleIphone);

        $this->assertContains($this->mainStoreOnlyIphone->id, $this->ids($this->asClient($this->staffMain)->getJson('/api/v1/assets?status=ready')));
        $this->asClient($this->staffMain)->postJson('/api/v1/bookings', $this->bookingPayload($this->mainStore, $this->mainStoreOnlyIphone))
            ->assertStatus(201);
        $this->assertSame($this->mainStore->id, (int)Asset::withoutGlobalScopes()->find($this->mainStoreOnlyIphone->id)->affiliate_id);
    }
}
