<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\TenantNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchUnitRegistrationGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $owner;
    protected User $staff;
    protected AssetModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner SewaKasir',
            'email' => 'owner.gov@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Budi Kasir',
            'email' => 'budi.staff@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'SewaKasir Governance Store',
            'slug' => 'rentcore-governance-store',
            'email' => 'owner.gov@rentcore.id',
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'branch_unit_registration_policy' => 'self_service',
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'name' => 'Cabang Kemang',
            'slug' => 'cabang-kemang',
            'code' => 'KMG',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->owner->update([
            'business_id' => $this->business->id,
            'current_business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
        ]);
        $this->staff->update([
            'business_id' => $this->business->id,
            'current_business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $staffMember = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $staffMember->id,
            'affiliate_id' => $this->branch->id,
        ]);

        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphone',
            'slug' => 'smartphone-gov',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'iPhone 16 Pro Max',
            'slug' => 'iphone-16-pro-max-gov',
            'brand' => 'Apple',
            'is_active' => true,
        ]);
    }

    public function test_owner_can_update_branch_unit_registration_policy_and_staff_cannot(): void
    {
        $ownerToken = $this->owner->createToken('owner')->plainTextToken;
        $staffToken = $this->staff->createToken('staff')->plainTextToken;

        // Staff is blocked from changing policy
        $staffResp = $this->withHeaders([
            'Authorization' => "Bearer {$staffToken}",
            'X-Business-Id' => (string) $this->business->id,
        ])->putJson("/api/v1/businesses/{$this->business->id}/rental-rules", [
            'branch_unit_registration_policy' => 'owner_only',
        ]);
        $staffResp->assertStatus(403);

        $this->app['auth']->forgetGuards();

        // Owner updates policy to require_owner_approval
        $ownerResp = $this->withHeaders([
            'Authorization' => "Bearer {$ownerToken}",
            'X-Business-Id' => (string) $this->business->id,
        ])->putJson("/api/v1/businesses/{$this->business->id}/rental-rules", [
            'branch_unit_registration_policy' => 'require_owner_approval',
        ]);

        $ownerResp->assertOk()
            ->assertJsonPath('data.branch_unit_registration_policy', 'require_owner_approval');

        // Active business & auth context expose updated policy
        $bizList = $this->withHeaders([
            'Authorization' => "Bearer {$ownerToken}",
        ])->getJson('/api/v1/businesses');

        $bizList->assertOk()
            ->assertJsonPath('data.0.branch_unit_registration_policy', 'require_owner_approval');
    }

    public function test_owner_only_policy_blocks_staff_with_403_while_owner_creates_ready_unit(): void
    {
        $this->business->update(['branch_unit_registration_policy' => 'owner_only']);

        $staffToken = $this->staff->createToken('staff')->plainTextToken;
        $ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $staffResp = $this->withHeaders([
            'Authorization' => "Bearer {$staffToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->postJson('/api/v1/assets', [
            'model_id' => $this->model->id,
            'asset_code' => 'IP16-STAFF-01',
            'serial_number' => 'SN-STAFF-001',
        ]);

        $staffResp->assertStatus(403)
            ->assertJsonPath('message', 'Hanya Owner yang dapat mendaftarkan unit baru.');
        $this->assertEquals(0, Asset::withoutGlobalScopes()->count());

        $this->app['auth']->forgetGuards();

        // Owner can still register directly with status = ready
        $ownerResp = $this->withHeaders([
            'Authorization' => "Bearer {$ownerToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->postJson('/api/v1/assets', [
            'model_id' => $this->model->id,
            'asset_code' => 'IP16-OWN-01',
            'serial_number' => 'SN-OWN-001',
        ]);

        $ownerResp->assertStatus(201)
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_require_owner_approval_creates_pending_unit_excludes_from_rentable_and_approves_with_two_way_notifications(): void
    {
        $this->business->update(['branch_unit_registration_policy' => 'require_owner_approval']);

        $staffToken = $this->staff->createToken('staff')->plainTextToken;
        $ownerToken = $this->owner->createToken('owner')->plainTextToken;

        // 1. Staff registers unit -> status is pending_approval
        $createResp = $this->withHeaders([
            'Authorization' => "Bearer {$staffToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->postJson('/api/v1/assets', [
            'model_id' => $this->model->id,
            'asset_code' => 'IP16-KMG-01',
            'serial_number' => 'SN-KMG-001',
        ]);

        $createResp->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_approval');

        $assetId = $createResp->json('data.id');

        // 2. Excluded from rentable ready cashier unit list
        $readyList = $this->withHeaders([
            'Authorization' => "Bearer {$staffToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->getJson('/api/v1/assets?status=ready');

        $readyList->assertOk();
        $this->assertEmpty($readyList->json('data.data'));

        // 3. Owner received unit_approval_requested notification
        $ownerNotif = TenantNotification::where('user_id', $this->owner->id)
            ->where('type', 'unit_approval_requested')
            ->first();

        $this->assertNotNull($ownerNotif);
        $this->assertEquals('Pengajuan Unit Baru Cabang', $ownerNotif->title);
        $this->assertStringContainsString('Budi Kasir', $ownerNotif->body);
        $this->assertStringContainsString('Cabang Kemang', $ownerNotif->body);
        $this->assertStringContainsString('iPhone 16 Pro Max', $ownerNotif->body);
        $this->assertStringContainsString('IP16-KMG-01', $ownerNotif->body);
        $this->assertEquals('inventory', $ownerNotif->action_payload['target_route']);
        $this->assertEquals($assetId, $ownerNotif->action_payload['asset_id']);

        // 4. Staff cannot self-approve (403)
        $selfApprove = $this->withHeaders([
            'Authorization' => "Bearer {$staffToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->postJson("/api/v1/assets/{$assetId}/approve");
        $selfApprove->assertStatus(403);

        $this->app['auth']->forgetGuards();

        // 5. Owner approves unit -> transitions to ready + notifies staff
        $approveResp = $this->withHeaders([
            'Authorization' => "Bearer {$ownerToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->postJson("/api/v1/assets/{$assetId}/approve");

        $approveResp->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.approved_by', $this->owner->id);

        $asset = Asset::withoutGlobalScopes()->find($assetId);
        $this->assertEquals('ready', $asset->status);
        $this->assertNotNull($asset->approved_at);

        // Now visible in ready cashier unit list
        $readyListAfter = $this->withHeaders([
            'Authorization' => "Bearer {$ownerToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->getJson('/api/v1/assets?status=ready');

        $readyListAfter->assertOk();
        $this->assertCount(1, $readyListAfter->json('data.data'));

        // Staff received unit_approved notification
        $staffNotif = TenantNotification::where('user_id', $this->staff->id)
            ->where('type', 'unit_approved')
            ->first();

        $this->assertNotNull($staffNotif);
        $this->assertEquals('Unit Baru Disetujui', $staffNotif->title);
    }

    public function test_owner_can_reject_pending_unit_with_reason_and_notify_staff(): void
    {
        $this->business->update(['branch_unit_registration_policy' => 'require_owner_approval']);

        $staffToken = $this->staff->createToken('staff')->plainTextToken;
        $ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $createResp = $this->withHeaders([
            'Authorization' => "Bearer {$staffToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->postJson('/api/v1/assets', [
            'model_id' => $this->model->id,
            'asset_code' => 'IP16-REJ-01',
            'serial_number' => 'SN-REJ-001',
        ]);
        $createResp->assertStatus(201);
        $assetId = $createResp->json('data.id');

        $this->app['auth']->forgetGuards();

        $rejectResp = $this->withHeaders([
            'Authorization' => "Bearer {$ownerToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->postJson("/api/v1/assets/{$assetId}/reject", [
            'reason' => 'Nomor seri tidak sesuai faktur pembelian.',
        ]);

        $rejectResp->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Nomor seri tidak sesuai faktur pembelian.');

        // Excluded from default inventory list
        $defaultList = $this->withHeaders([
            'Authorization' => "Bearer {$ownerToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->getJson('/api/v1/assets');
        $defaultList->assertOk();
        $this->assertEmpty($defaultList->json('data.data'));

        // Staff received unit_rejected notification with reason
        $staffNotif = TenantNotification::where('user_id', $this->staff->id)
            ->where('type', 'unit_rejected')
            ->first();

        $this->assertNotNull($staffNotif);
        $this->assertEquals('Pengajuan Unit Ditolak', $staffNotif->title);
        $this->assertStringContainsString('Nomor seri tidak sesuai faktur pembelian.', $staffNotif->body);
    }
}
