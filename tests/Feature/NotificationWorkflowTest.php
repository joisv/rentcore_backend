<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\BranchMember;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\PaymentMethod;
use App\Models\TenantNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branchA;
    protected Affiliate $branchB;
    protected User $owner;
    protected User $staffA;
    protected User $staffB;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Utama',
            'email' => 'owner@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Bisnis Rental Prima',
            'slug' => 'bisnis-rental-prima',
            'phone' => '081234567890',
            'address' => 'Jl. Bisnis No. 1',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->branchA = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'CBG-A',
            'slug' => 'cabang-alpha',
            'name' => 'Cabang Alpha',
            'city' => 'Jakarta',
            'address' => 'Jl. Alpha No. 1',
            'phone' => '08111111111',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchB = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'CBG-B',
            'slug' => 'cabang-beta',
            'name' => 'Cabang Beta',
            'city' => 'Bandung',
            'address' => 'Jl. Beta No. 2',
            'phone' => '08222222222',
            'is_central' => false,
            'is_active' => true,
        ]);

        // Staff A
        $this->staffA = User::create([
            'name' => 'Staff Alpha',
            'email' => 'staffa@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'is_active' => true,
            'affiliate_id' => $this->branchA->id,
        ]);

        $bmA = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffA->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
        BranchMember::create([
            'business_member_id' => $bmA->id,
            'affiliate_id' => $this->branchA->id,
            'is_primary' => true,
        ]);

        // Staff B
        $this->staffB = User::create([
            'name' => 'Staff Beta',
            'email' => 'staffb@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'is_active' => true,
            'affiliate_id' => $this->branchB->id,
        ]);

        $bmB = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffB->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
        BranchMember::create([
            'business_member_id' => $bmB->id,
            'affiliate_id' => $this->branchB->id,
            'is_primary' => true,
        ]);

        // Owner BusinessMember
        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Asset
        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'category_id' => $category->id,
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro',
            'brand' => 'Apple',
            'is_active' => true,
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchA->id,
            'model_id' => $model->id,
            'asset_code' => 'IPH15-001',
            'serial_number' => 'SN-IPH15-001',
            'status' => 'ready',
        ]);
    }

    protected function requestAs(User $user, ?Affiliate $branch = null)
    {
        auth()->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string)$this->business->id,
        ];
        if ($branch) {
            $headers['X-Branch-Id'] = (string)$branch->id;
        }
        return $this->withHeaders($headers);
    }

    public function test_transfer_request_notifies_owner(): void
    {
        $response = $this->requestAs($this->staffA, $this->branchA)->postJson('/api/v1/transfers', [
            'asset_id' => $this->asset->id,
            'to_affiliate_id' => $this->branchB->id,
            'recipient_id' => $this->staffB->id,
            'notes' => 'Transfer unit untuk event',
        ]);

        $response->assertStatus(201);
        $transferId = $response->json('data.id');

        // Verify notification in database for Owner
        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'category' => 'transfer',
            'type' => 'transfer.requested',
            'read_at' => null,
        ]);

        // Fetch notifications as Owner via API
        $notifResponse = $this->requestAs($this->owner)->getJson('/api/v1/notifications');

        $notifResponse->assertStatus(200);
        $notifResponse->assertJson([
            'success' => true,
            'unread_count' => 1,
        ]);
        $this->assertCount(1, $notifResponse->json('data.data'));
        $this->assertEquals('Pengajuan Mutasi Unit', $notifResponse->json('data.data.0.title'));
    }

    public function test_transfer_approval_notifies_recipient_and_sender(): void
    {
        // Staff A requests transfer
        $createRes = $this->requestAs($this->staffA, $this->branchA)->postJson('/api/v1/transfers', [
            'asset_id' => $this->asset->id,
            'to_affiliate_id' => $this->branchB->id,
            'recipient_id' => $this->staffB->id,
        ]);
        $transferId = $createRes->json('data.id');

        // Owner approves transfer
        $approveRes = $this->requestAs($this->owner)->postJson("/api/v1/transfers/{$transferId}/approve");

        $approveRes->assertStatus(200);

        // Verify Staff B (Recipient) receives notification
        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->staffB->id,
            'category' => 'transfer',
            'type' => 'transfer.approved',
        ]);

        // Verify Staff A (Sender) receives notification
        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->staffA->id,
            'category' => 'transfer',
            'type' => 'transfer.approved',
        ]);
    }

    public function test_mark_as_read_and_mark_all_as_read(): void
    {
        // Seed 2 notifications for Staff A
        $notif1 = TenantNotification::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffA->id,
            'category' => 'system',
            'type' => 'system.welcome',
            'title' => 'Selamat Datang 1',
            'body' => 'Pesan selamat datang 1',
        ]);

        $notif2 = TenantNotification::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staffA->id,
            'category' => 'system',
            'type' => 'system.welcome',
            'title' => 'Selamat Datang 2',
            'body' => 'Pesan selamat datang 2',
        ]);

        // Verify initial unread count = 2
        $res = $this->requestAs($this->staffA)->getJson('/api/v1/notifications');
        $res->assertJson(['unread_count' => 2]);

        // Mark notif1 as read
        $readRes = $this->requestAs($this->staffA)->patchJson("/api/v1/notifications/{$notif1->id}/read");

        $readRes->assertStatus(200);
        $readRes->assertJson(['unread_count' => 1]);
        $this->assertNotNull($notif1->fresh()->read_at);
        $this->assertNull($notif2->fresh()->read_at);

        // Mark all as read
        $readAllRes = $this->requestAs($this->staffA)->postJson('/api/v1/notifications/read-all');

        $readAllRes->assertStatus(200);
        $readAllRes->assertJson(['unread_count' => 0]);
        $this->assertNotNull($notif2->fresh()->read_at);
    }

    public function test_resignation_workflow_notifies_owner_and_staff(): void
    {
        // Staff submits resignation
        $resignRes = $this->requestAs($this->staffA)->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests", [
            'reason' => 'Pindah ke luar kota',
        ]);

        $resignRes->assertStatus(201);
        $requestId = $resignRes->json('data.id');

        // Verify Owner receives notification
        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'category' => 'membership',
            'type' => 'staff.resignation_submitted',
        ]);

        // Owner approves resignation
        $approveRes = $this->requestAs($this->owner)->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests/{$requestId}/approve", [
            'decision_notes' => 'Terima kasih atas dedikasinya.',
        ]);

        $approveRes->assertStatus(200);

        // Verify Staff A receives approval notification
        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->staffA->id,
            'category' => 'membership',
            'type' => 'staff.resignation_approved',
        ]);
    }

    public function test_tenant_isolation_on_notifications(): void
    {
        // Business 2
        $owner2 = User::create([
            'name' => 'Owner 2',
            'email' => 'owner2@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $biz2 = Business::create([
            'name' => 'Bisnis Kedua',
            'slug' => 'bisnis-kedua',
            'phone' => '0899999999',
            'owner_id' => $owner2->id,
            'is_active' => true,
        ]);
        BusinessMember::create([
            'business_id' => $biz2->id,
            'user_id' => $owner2->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Notification for Owner 1 in Business 1
        TenantNotification::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'category' => 'system',
            'type' => 'system.alert',
            'title' => 'Rahasia Bisnis 1',
            'body' => 'Pesan rahasia bisnis 1',
        ]);

        // Owner 2 queries notifications with Biz 2 header
        $owner2Token = $owner2->createToken('test')->plainTextToken;
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$owner2Token}",
            'X-Business-Id' => (string)$biz2->id,
        ])->getJson('/api/v1/notifications');

        $res->assertStatus(200);
        $res->assertJson(['unread_count' => 0]);
        $this->assertEmpty($res->json('data.data'));
    }
}
