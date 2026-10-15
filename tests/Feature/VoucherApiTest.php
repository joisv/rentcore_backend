<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VoucherApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $owner;
    protected User $branchAdmin;
    protected User $staff;
    protected Business $business;
    protected Affiliate $affiliateA;
    protected Affiliate $affiliateB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Pak Owner',
            'email' => 'owner@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'SkyRental Store Pusat',
            'slug' => 'skyrental-store-pusat',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->affiliateA = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'GNT',
            'name' => 'Genteng Central Hub',
            'slug' => 'genteng-central-hub',
            'is_central' => true,
        ]);

        $this->affiliateB = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'SBY',
            'name' => 'Surabaya Barat',
            'slug' => 'surabaya-barat',
            'is_central' => false,
        ]);

        $this->superAdmin = User::create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->branchAdmin = User::create([
            'name' => 'Branch Admin',
            'email' => 'admin@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'affiliate_id' => $this->affiliateA->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->branchAdmin->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Budi Kasir',
            'email' => 'staff@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->affiliateA->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_super_admin_can_create_percentage_voucher_with_min_transaction(): void
    {
        $token = $this->superAdmin->createToken('Test')->plainTextToken;

        $payload = [
            'code' => 'VCR-SUPER15',
            'name' => 'Voucher 15% Spesial',
            'discount_percentage' => 15.00,
            'min_transaction_amount' => 300000.00,
            'max_discount_cap' => 100000.00,
            'barcode_type' => 'qr_code',
            'start_at' => Carbon::now()->subDay()->toDateTimeString(),
            'end_at' => Carbon::now()->addMonth()->toDateTimeString(),
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'VCR-SUPER15')
            ->assertJsonPath('data.discount_percentage', 15)
            ->assertJsonPath('data.min_transaction_amount', 300000);

        $this->assertDatabaseHas('vouchers', [
            'code' => 'VCR-SUPER15',
            'discount_percentage' => 15.00,
            'min_transaction_amount' => 300000.00,
        ]);
    }

    public function test_owner_can_create_branch_specific_voucher(): void
    {
        $token = $this->owner->createToken('Test')->plainTextToken;

        $payload = [
            'code' => 'VCR-GNT20',
            'name' => 'Diskon Cabang Genteng 20%',
            'discount_percentage' => 20.00,
            'min_transaction_amount' => 200000.00,
            'affiliate_id' => $this->affiliateA->id,
            'start_at' => Carbon::now()->subDay()->toDateTimeString(),
            'end_at' => Carbon::now()->addMonth()->toDateTimeString(),
            'usage_limit_total' => 50,
            'usage_limit_per_customer' => 2,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'VCR-GNT20')
            ->assertJsonPath('data.affiliate_id', $this->affiliateA->id)
            ->assertJsonPath('data.usage_limit_total', 50)
            ->assertJsonPath('data.usage_limit_per_customer', 2);
    }

    public function test_branch_admin_and_staff_cannot_create_or_mutate_vouchers(): void
    {
        // Branch admin cannot create
        $adminToken = $this->branchAdmin->createToken('Test')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers', [
                'name' => 'Admin Attempt',
                'discount_percentage' => 10,
                'min_transaction_amount' => 100000,
                'start_at' => Carbon::now()->toDateTimeString(),
                'end_at' => Carbon::now()->addMonth()->toDateTimeString(),
            ]);
        $response->assertStatus(403);

        // Staff cannot create
        $staffToken = $this->staff->createToken('Test')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers', [
                'name' => 'Staff Attempt',
                'discount_percentage' => 10,
                'min_transaction_amount' => 100000,
                'start_at' => Carbon::now()->toDateTimeString(),
                'end_at' => Carbon::now()->addMonth()->toDateTimeString(),
            ]);
        $response->assertStatus(403);
    }

    public function test_owner_can_list_vouchers_with_status_filter_and_search(): void
    {
        $v1 = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'ACTIVE01',
            'name' => 'Promo Aktif',
            'discount_percentage' => 10,
            'min_transaction_amount' => 100000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $v2 = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'INACTIVE02',
            'name' => 'Promo Nonaktif',
            'discount_percentage' => 15,
            'min_transaction_amount' => 200000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => false,
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        // Status all
        $resAll = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson('/api/v1/vouchers?status=all');
        $resAll->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Status active
        $resActive = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson('/api/v1/vouchers?status=active');
        $resActive->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ACTIVE01');

        // Status inactive
        $resInactive = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson('/api/v1/vouchers?status=inactive');
        $resInactive->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'INACTIVE02');

        // Search by keyword
        $resSearch = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson('/api/v1/vouchers?search=Nonaktif');
        $resSearch->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'INACTIVE02');
    }

    public function test_cashier_only_sees_active_vouchers(): void
    {
        Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'ACTIVE01',
            'name' => 'Promo Aktif',
            'discount_percentage' => 10,
            'min_transaction_amount' => 100000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'INACTIVE02',
            'name' => 'Promo Nonaktif',
            'discount_percentage' => 15,
            'min_transaction_amount' => 200000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => false,
        ]);

        $token = $this->staff->createToken('Test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson('/api/v1/vouchers');

        $res->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ACTIVE01');
    }

    public function test_owner_can_view_single_voucher_details(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'DETAIL10',
            'name' => 'Detail Voucher',
            'discount_percentage' => 10,
            'min_transaction_amount' => 100000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson("/api/v1/vouchers/{$voucher->id}");

        $res->assertStatus(200)
            ->assertJsonPath('data.id', $voucher->id)
            ->assertJsonPath('data.code', 'DETAIL10');
    }

    public function test_owner_can_update_unredeemed_voucher(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'EDIT10',
            'name' => 'Original Name',
            'discount_percentage' => 10,
            'min_transaction_amount' => 100000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->putJson("/api/v1/vouchers/{$voucher->id}", [
                'code' => 'EDIT-UPDATED',
                'name' => 'Updated Name',
                'discount_percentage' => 25,
                'min_transaction_amount' => 250000,
                'start_at' => Carbon::now()->subDay()->toDateTimeString(),
                'end_at' => Carbon::now()->addMonths(2)->toDateTimeString(),
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.code', 'EDIT-UPDATED')
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.discount_percentage', 25)
            ->assertJsonPath('data.min_transaction_amount', 250000);
    }

    public function test_owner_can_toggle_voucher_status(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'TOGGLE01',
            'name' => 'Toggle Voucher',
            'discount_percentage' => 10,
            'min_transaction_amount' => 100000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        // Toggle to inactive
        $res1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->patchJson("/api/v1/vouchers/{$voucher->id}/toggle-status");

        $res1->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        // Toggle back to active
        $res2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->patchJson("/api/v1/vouchers/{$voucher->id}/toggle-status");

        $res2->assertStatus(200)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_owner_can_delete_unredeemed_voucher(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'DELETE-ME',
            'name' => 'To Delete',
            'discount_percentage' => 10,
            'min_transaction_amount' => 100000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->deleteJson("/api/v1/vouchers/{$voucher->id}");

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('vouchers', ['id' => $voucher->id]);
    }

    public function test_cannot_delete_voucher_that_has_been_used_in_transactions(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'USED-VCR',
            'name' => 'Used Voucher',
            'discount_percentage' => 10,
            'min_transaction_amount' => 100000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'usage_count' => 3,
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->deleteJson("/api/v1/vouchers/{$voucher->id}");

        $res->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id]);
    }

    public function test_updating_redeemed_voucher_retains_code_and_discount_percentage(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'USED-KEEP-CODE',
            'name' => 'Old Name',
            'discount_percentage' => 15,
            'min_transaction_amount' => 100000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'usage_count' => 1,
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $token = $this->owner->createToken('Test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->putJson("/api/v1/vouchers/{$voucher->id}", [
                'name' => 'New Safe Name',
                'min_transaction_amount' => 150000,
                'start_at' => Carbon::now()->subDay()->toDateTimeString(),
                'end_at' => Carbon::now()->addMonths(2)->toDateTimeString(),
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.code', 'USED-KEEP-CODE')
            ->assertJsonPath('data.name', 'New Safe Name')
            ->assertJsonPath('data.discount_percentage', 15)
            ->assertJsonPath('data.min_transaction_amount', 150000);
    }

    public function test_branch_restricted_voucher_accepted_for_matching_branch_and_rejected_for_other(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'BRANCH-GNT',
            'name' => 'Khusus Cabang Genteng',
            'discount_percentage' => 15.00,
            'min_transaction_amount' => 300000.00,
            'affiliate_id' => $this->affiliateA->id,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $token = $this->staff->createToken('Test')->plainTextToken;

        // 1. Validation with matching branch (Affiliate A)
        $resMatch = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers/validate', [
                'code' => 'BRANCH-GNT',
                'subtotal' => 400000.00,
                'affiliate_id' => $this->affiliateA->id,
            ]);

        $resMatch->assertStatus(200)
            ->assertJsonPath('data.valid', true);

        // 2. Validation with mismatch branch (Affiliate B)
        $resMismatch = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers/validate', [
                'code' => 'BRANCH-GNT',
                'subtotal' => 400000.00,
                'affiliate_id' => $this->affiliateB->id,
            ]);

        $resMismatch->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_code']);
    }

    public function test_business_wide_voucher_accepted_across_branches(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'BIZ-WIDE',
            'name' => 'Semua Cabang Promo',
            'discount_percentage' => 10.00,
            'min_transaction_amount' => 100000.00,
            'affiliate_id' => null, // Business-wide
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->owner->id,
            'is_active' => true,
        ]);

        $token = $this->staff->createToken('Test')->plainTextToken;

        // Valid on Branch A
        $resA = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers/validate', [
                'code' => 'BIZ-WIDE',
                'subtotal' => 200000.00,
                'affiliate_id' => $this->affiliateA->id,
            ]);
        $resA->assertStatus(200)->assertJsonPath('data.valid', true);

        // Valid on Branch B
        $resB = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers/validate', [
                'code' => 'BIZ-WIDE',
                'subtotal' => 200000.00,
                'affiliate_id' => $this->affiliateB->id,
            ]);
        $resB->assertStatus(200)->assertJsonPath('data.valid', true);
    }

    public function test_voucher_validation_fails_if_transaction_below_min_threshold(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'MIN300K',
            'name' => 'Min 300K Promo',
            'discount_percentage' => 15.00,
            'min_transaction_amount' => 300000.00,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->superAdmin->id,
            'is_active' => true,
        ]);

        $token = $this->staff->createToken('Test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/v1/vouchers/validate', [
                'code' => 'MIN300K',
                'subtotal' => 250000.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_code']);
    }

    public function test_thermal_slip_payload_endpoint_returns_2d_barcode(): void
    {
        $voucher = Voucher::create([
            'business_id' => $this->business->id,
            'code' => 'THERMAL2D',
            'name' => 'Thermal Test Voucher',
            'discount_percentage' => 20.00,
            'min_transaction_amount' => 500000.00,
            'barcode_type' => 'qr_code',
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->superAdmin->id,
            'is_active' => true,
        ]);

        $token = $this->staff->createToken('Test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson("/api/v1/vouchers/{$voucher->code}/print-slip");

        $response->assertStatus(200)
            ->assertJsonPath('data.voucher_code', 'THERMAL2D')
            ->assertJsonPath('data.barcode_format', 'qr_code')
            ->assertJsonPath('data.qr_payload', 'THERMAL2D');
    }
}
