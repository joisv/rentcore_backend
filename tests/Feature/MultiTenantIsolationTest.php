<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BranchMember;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;
    protected User $ownerB;
    protected Business $bizA;
    protected Business $bizB;
    protected Affiliate $branchA;
    protected Affiliate $branchB;
    protected AssetModel $model;
    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashMethod = PaymentMethod::create(['code' => 'CASH', 'name' => 'Tunai', 'is_active' => true]);

        // 1. Create Business A
        $this->ownerA = User::create([
            'name' => 'Owner Business A',
            'email' => 'owner.a@tenant.com',
            'password' => bcrypt('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->bizA = Business::create([
            'name' => 'Rental Mega Jaya A',
            'slug' => 'rental-mega-jaya-a',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);

        $this->branchA = Affiliate::create([
            'business_id' => $this->bizA->id,
            'code' => 'MGA',
            'name' => 'Mega Jaya Cabang 1',
            'slug' => 'mega-jaya-cabang-1',
            'is_central' => true,
            'is_active' => true,
        ]);

        $memA = BusinessMember::create([
            'business_id' => $this->bizA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $memA->id,
            'affiliate_id' => $this->branchA->id,
        ]);

        // 2. Create Business B
        $this->ownerB = User::create([
            'name' => 'Owner Business B',
            'email' => 'owner.b@tenant.com',
            'password' => bcrypt('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->bizB = Business::create([
            'name' => 'Gadget Express B',
            'slug' => 'gadget-express-b',
            'owner_id' => $this->ownerB->id,
            'is_active' => true,
        ]);

        $this->branchB = Affiliate::create([
            'business_id' => $this->bizB->id,
            'code' => 'GXB',
            'name' => 'Gadget Express Pusat',
            'slug' => 'gadget-express-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $memB = BusinessMember::create([
            'business_id' => $this->bizB->id,
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $memB->id,
            'affiliate_id' => $this->branchB->id,
        ]);

        // Category & Model
        $cat = AssetCategory::create(['name' => 'Smartphones', 'slug' => 'smartphones', 'is_active' => true]);
        $this->model = AssetModel::create([
            'business_id' => $this->bizA->id,
            'category_id' => $cat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'price' => 300000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);
    }

    public function test_google_sign_in_authenticates_or_creates_user(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'dev_token_valid_123',
            'email' => 'google.user@example.com',
            'name' => 'Google Test User',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'email'],
                    'businesses',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'google.user@example.com',
            'name' => 'Google Test User',
        ]);
    }

    public function test_google_sign_in_rejects_invalid_tokens(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'forged_invalid_token',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_GOOGLE_TOKEN');
    }

    public function test_google_sign_in_rejects_email_collision_with_existing_password_account(): void
    {
        // Existing user registered with password
        User::create([
            'name' => 'Existing Password User',
            'email' => 'victim@rentcore.id',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        // Attacker attempts Google login with matching email
        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'dev_token_attacker_google_sub',
            'email' => 'victim@rentcore.id',
            'name' => 'Attacker With Same Email',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'EMAIL_ALREADY_REGISTERED_WITH_PASSWORD');
    }

    public function test_google_sign_in_rejects_inactive_user(): void
    {
        User::create([
            'name' => 'Disabled User',
            'email' => 'disabled@rentcore.id',
            'google_id' => 'google_sub_' . md5('disabled@rentcore.id'),
            'role' => 'staff',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'dev_token_disabled',
            'email' => 'disabled@rentcore.id',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_google_sign_in_two_distinct_accounts_create_isolated_identities_and_tokens(): void
    {
        // Device A sign in with Google Account A
        $responseA = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'dev_token_account_a',
            'email' => 'device.a@gmail.com',
            'name' => 'Device User A',
        ]);
        $responseA->assertStatus(200);
        $userAId = $responseA->json('data.user.id');
        $tokenA = $responseA->json('data.token');

        // Device B sign in with Google Account B
        $responseB = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'dev_token_account_b',
            'email' => 'device.b@gmail.com',
            'name' => 'Device User B',
        ]);
        $responseB->assertStatus(200);
        $userBId = $responseB->json('data.user.id');
        $tokenB = $responseB->json('data.token');

        // Verify distinct IDs, tokens, and Google subjects
        $this->assertNotEquals($userAId, $userBId);
        $this->assertNotEquals($tokenA, $tokenB);

        $userA = User::find($userAId);
        $userB = User::find($userBId);
        $this->assertNotEquals($userA->google_id, $userB->google_id);
        $this->assertFalse($userA->isPlatformAdmin());
        $this->assertFalse($userB->isPlatformAdmin());
    }

    public function test_authenticated_user_can_register_new_rental_business(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/businesses', [
                'name' => 'Kamera Rent Lombok',
                'phone' => '0877-0000-1111',
                'city' => 'Mataram',
                'initial_branch_name' => 'Cabang Mataram Kota',
                'initial_branch_code' => 'MTR',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Kamera Rent Lombok');

        $this->assertDatabaseHas('businesses', ['name' => 'Kamera Rent Lombok']);
        $this->assertDatabaseHas('business_members', [
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('affiliates', ['code' => 'MTR', 'name' => 'Cabang Mataram Kota']);
    }

    public function test_tenant_a_cannot_view_or_search_tenant_b_customers(): void
    {
        // Customer A in Business A
        $custA = Customer::create([
            'business_id' => $this->bizA->id,
            'name' => 'Nasabah Tenant A',
            'phone' => '0812-1111-2222',
            'email' => 'a@client.com',
        ]);

        // Customer B in Business B
        $custB = Customer::create([
            'business_id' => $this->bizB->id,
            'name' => 'Nasabah Tenant B',
            'phone' => '0813-3333-4444',
            'email' => 'b@client.com',
        ]);

        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        // User A fetches customer list
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson('/api/v1/customers');

        $response->assertStatus(200);
        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains('Nasabah Tenant A'));
        $this->assertFalse($names->contains('Nasabah Tenant B'));

        // User A lookups Customer B's phone
        $lookupResponse = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson('/api/v1/customers/lookup?query=0813-3333-4444');

        $lookupResponse->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_tenant_a_and_tenant_b_can_register_identical_customer_phone_independently(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;
        $tokenB = $this->ownerB->createToken('test')->plainTextToken;

        $sharedPhone = '081299998888';

        // Tenant A creates customer
        $respA = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/customers', [
                'name' => 'Budi Client A',
                'phone' => $sharedPhone,
            ]);
        $respA->assertStatus(201);

        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        // Tenant B creates customer with SAME phone without error
        $respB = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->postJson('/api/v1/customers', [
                'name' => 'Budi Client B',
                'phone' => $sharedPhone,
            ]);
        $respB->assertStatus(201);

        $this->assertDatabaseHas('customers', [
            'business_id' => $this->bizA->id,
            'name' => 'Budi Client A',
            'phone' => $sharedPhone,
        ]);

        $this->assertDatabaseHas('customers', [
            'business_id' => $this->bizB->id,
            'name' => 'Budi Client B',
            'phone' => $sharedPhone,
        ]);
    }

    public function test_tenant_a_cannot_view_or_manipulate_tenant_b_assets(): void
    {
        // Asset A
        $assetA = Asset::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $this->branchA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-A-01',
            'serial_number' => 'SN-A-01',
            'status' => 'ready',
        ]);

        // Asset B
        $assetB = Asset::create([
            'business_id' => $this->bizB->id,
            'affiliate_id' => $this->branchB->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-B-01',
            'serial_number' => 'SN-B-01',
            'status' => 'ready',
        ]);

        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        // User A lists assets
        $resp = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson('/api/v1/assets');

        $codes = collect($resp->json('data.data'))->pluck('asset_code');
        $this->assertTrue($codes->contains('AST-A-01'));
        $this->assertFalse($codes->contains('AST-B-01'));

        // User A attempts IDOR view on Asset B
        $respShow = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson("/api/v1/assets/{$assetB->id}");
        $respShow->assertStatus(404);

        // User A attempts IDOR update on Asset B
        $respUpdate = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->putJson("/api/v1/assets/{$assetB->id}", [
                'status' => 'retired',
            ]);
        $respUpdate->assertStatus(404);

        // Verify Asset B was untouched
        $this->assertEquals('ready', $assetB->fresh()->status);
    }

    public function test_idor_protection_on_bookings_and_returns(): void
    {
        // Create customer and asset for Business B
        $custB = Customer::create([
            'business_id' => $this->bizB->id,
            'name' => 'Customer B',
            'phone' => '0815-5555-6666',
        ]);

        $assetB = Asset::create([
            'business_id' => $this->bizB->id,
            'affiliate_id' => $this->branchB->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-B-99',
            'serial_number' => 'SN-B-99',
            'status' => 'rented',
        ]);

        $bookingB = Booking::create([
            'business_id' => $this->bizB->id,
            'booking_code' => 'RC-B-0001',
            'affiliate_id' => $this->branchB->id,
            'customer_id' => $custB->id,
            'created_by' => $this->ownerB->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000.00,
            'total_bill' => 300000.00,
            'payment_status' => 'paid',
        ]);

        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        // 1. User A tries to view Booking B details
        $respShow = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson("/api/v1/bookings/{$bookingB->id}");
        $respShow->assertStatus(404);

        // 2. User A tries to extend Booking B
        $respExtend = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson("/api/v1/bookings/{$bookingB->id}/extend", [
                'additional_hours' => 6,
            ]);
        $respExtend->assertStatus(404);

        // 3. User A tries to process return on Booking B
        $respReturn = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson("/api/v1/bookings/{$bookingB->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['screen_good' => true],
            ]);
        $respReturn->assertStatus(404);
    }

    public function test_user_cannot_specify_arbitrary_business_id_without_membership(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        // User A tries to access Business B by tampering X-Business-Id
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->getJson('/api/v1/assets');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'BUSINESS_ACCESS_DENIED');
    }

    public function test_tenant_a_cannot_transfer_asset_to_tenant_b_branch(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        $assetA = Asset::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $this->branchA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-TRF-A1',
            'serial_number' => 'SN-TRF-A1',
            'status' => 'ready',
        ]);

        // Attempt transfer to Tenant B's branch
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->withHeader('X-Branch-Id', (string)$this->branchA->id)
            ->postJson('/api/v1/transfers', [
                'asset_id' => $assetA->id,
                'to_affiliate_id' => $this->branchB->id,
                'recipient_id' => $this->ownerA->id,
            ]);

        // Should fail because branch B does not belong to business A
        $response->assertStatus(404);
    }

    public function test_tenant_a_cannot_redeem_or_view_tenant_b_voucher(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        $voucherB = \App\Models\Voucher::create([
            'business_id' => $this->bizB->id,
            'code' => 'PROMOB',
            'name' => 'Promo Diskon B',
            'discount_percentage' => 20,
            'min_transaction_amount' => 50000,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addDay(),
            'created_by' => $this->ownerB->id,
            'is_active' => true,
        ]);

        // User A tries to validate voucher B
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/vouchers/validate', [
                'code' => 'PROMOB',
                'subtotal' => 100000,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_code']);
    }

    public function test_user_without_business_membership_is_rejected_on_tenant_routes_with_403(): void
    {
        // Newly registered user with 0 businesses
        $newUser = User::create([
            'name' => 'Brand New Google User',
            'email' => 'new.user@gmail.com',
            'google_id' => 'google_sub_12345678',
            'role' => 'staff',
            'is_active' => true,
        ]);

        $token = $newUser->createToken('test')->plainTextToken;

        // 1. Assets endpoint
        $assetResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/assets');
        $assetResp->assertStatus(403)
            ->assertJsonPath('code', 'NO_BUSINESS_MEMBERSHIP');

        // 2. Products endpoint
        $prodResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/products');
        $prodResp->assertStatus(403)
            ->assertJsonPath('code', 'NO_BUSINESS_MEMBERSHIP');

        // 3. KPI endpoint
        $kpiResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/dashboard/kpi');
        $kpiResp->assertStatus(403)
            ->assertJsonPath('code', 'NO_BUSINESS_MEMBERSHIP');

        // Verify fail closed in Eloquent scope
        \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($newUser);
        $this->assertEquals(0, Asset::count());
        $this->assertEquals(0, AssetModel::count());
        $this->assertEquals(0, Booking::count());
    }

    public function test_newly_registered_business_starts_with_empty_inventory(): void
    {
        $newUser = User::create([
            'name' => 'Founder C',
            'email' => 'founder.c@rentcore.id',
            'role' => 'staff',
            'is_active' => true,
        ]);
        $token = $newUser->createToken('test')->plainTextToken;

        // Register new business
        $regResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/businesses', [
                'name' => 'Kamera Malang C',
                'city' => 'Malang',
                'initial_branch_name' => 'Malang Kota',
                'initial_branch_code' => 'MLG',
            ]);
        $regResp->assertStatus(201);
        $bizId = $regResp->json('data.id');

        // Fetch assets for newly created business
        $assetResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$bizId)
            ->getJson('/api/v1/assets');

        $assetResp->assertStatus(200);
        $this->assertEmpty($assetResp->json('data.data'));

        // Legacy assets (such as from Business A) MUST NOT appear
        $codes = collect($assetResp->json('data.data'))->pluck('asset_code');
        $this->assertFalse($codes->contains('AST-A-01'));
    }

    public function test_tenant_a_and_b_can_create_independent_iphone_models_with_same_name_and_custom_rates(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;
        $tokenB = $this->ownerB->createToken('test')->plainTextToken;

        $globalCat = AssetCategory::withoutGlobalScopes()->where('slug', 'smartphones')->first();

        // 1. Tenant A creates iPhone 15 Pro with rate 350.000 / day
        $respA = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/products', [
                'category_id' => $globalCat->id,
                'brand' => 'Apple',
                'name' => 'iPhone 15 Pro Max',
                'storage_capacity' => '256GB',
                'color' => 'Natural Titanium',
                'rates' => [
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 1,
                        'price' => 350000.00,
                        'extension_hourly_rate' => 25000.00,
                    ],
                ],
            ]);
        $respA->assertStatus(201);
        $modelAId = $respA->json('data.id');

        // 2. Tenant B creates iPhone 15 Pro with SAME name but different rate 280.000 / day
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $respB = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->postJson('/api/v1/products', [
                'category_id' => $globalCat->id,
                'brand' => 'Apple',
                'name' => 'iPhone 15 Pro Max',
                'storage_capacity' => '256GB',
                'color' => 'Natural Titanium',
                'rates' => [
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 1,
                        'price' => 280000.00,
                        'extension_hourly_rate' => 20000.00,
                    ],
                ],
            ]);
        $respB->assertStatus(201);
        $modelBId = $respB->json('data.id');

        $this->assertNotEquals($modelAId, $modelBId);

        // 3. Tenant A lists products: only sees their own iPhone 15 Pro Max
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $listA = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson('/api/v1/products');
        $listA->assertStatus(200);
        $idsA = collect($listA->json('data.data'))->pluck('id');
        $this->assertTrue($idsA->contains($modelAId));
        $this->assertFalse($idsA->contains($modelBId));

        // 4. Tenant B lists products: only sees their own iPhone 15 Pro Max
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $listB = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->getJson('/api/v1/products');
        $listB->assertStatus(200);
        $idsB = collect($listB->json('data.data'))->pluck('id');
        $this->assertTrue($idsB->contains($modelBId));
        $this->assertFalse($idsB->contains($modelAId));

        // 5. Tenant A calculates price for Model A -> Rp 350.000
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $calcA = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/pricing/calculate', [
                'model_id' => $modelAId,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ]);
        $calcA->assertStatus(200)
            ->assertJsonPath('data.price', 350000);

        // 6. Tenant B calculates price for Model B -> Rp 280.000
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $calcB = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->postJson('/api/v1/pricing/calculate', [
                'model_id' => $modelBId,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ]);
        $calcB->assertStatus(200)
            ->assertJsonPath('data.price', 280000);

        // 7. Cross-tenant calculate price attempt: Tenant A cannot calculate price for Model B
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $crossCalc = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/pricing/calculate', [
                'model_id' => $modelBId,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ]);
        $crossCalc->assertStatus(404);
    }

    public function test_tenant_cannot_register_asset_under_another_tenants_model_or_branch(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        // Model B belongs to Business B
        $globalCat = AssetCategory::withoutGlobalScopes()->where('slug', 'smartphones')->first();
        $modelB = AssetModel::create([
            'business_id' => $this->bizB->id,
            'category_id' => $globalCat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 13 Mini',
            'slug' => 'iphone-13-mini-biz-b',
            'is_active' => true,
        ]);

        // Tenant A tries to register asset using Tenant B's model_id
        $resp = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/assets', [
                'model_id' => $modelB->id,
                'asset_code' => 'AST-HACK-01',
                'serial_number' => 'SN-HACK-01',
            ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['model_id']);

        // Tenant A tries to register asset targeting Tenant B's branch
        $respBranch = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/assets', [
                'model_id' => $this->model->id,
                'affiliate_id' => $this->branchB->id,
                'asset_code' => 'AST-HACK-02',
                'serial_number' => 'SN-HACK-02',
            ]);

        $respBranch->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_BRANCH');
    }

    public function test_global_and_tenant_categories_isolation(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;
        $tokenB = $this->ownerB->createToken('test')->plainTextToken;

        // Tenant A creates custom category "Lensa Bioskop"
        $createCat = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/categories', [
                'name' => 'Lensa Bioskop',
                'description' => 'Cinema lens collection',
            ]);
        $createCat->assertStatus(201);
        $catAId = $createCat->json('data.id');

        // Tenant B lists categories: should see global "Smartphones" but NOT "Lensa Bioskop"
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $listB = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->getJson('/api/v1/categories');

        $listB->assertStatus(200);
        $namesB = collect($listB->json('data.data'))->pluck('name');
        $this->assertTrue($namesB->contains('Smartphones'));
        $this->assertFalse($namesB->contains('Lensa Bioskop'));

        // Tenant A tries to edit global category: rejected with 403
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $globalCat = AssetCategory::withoutGlobalScopes()->where('slug', 'smartphones')->first();
        $editGlobal = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->putJson("/api/v1/categories/{$globalCat->id}", [
                'name' => 'Smartphones Modified',
            ]);
        $editGlobal->assertStatus(403)
            ->assertJsonPath('code', 'GLOBAL_CATEGORY_IMMUTABLE');
    }

    public function test_saas_user_can_create_booking_without_affiliate_id_in_request(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        // Create asset for Business A at branch A
        $assetA = Asset::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $this->branchA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-A-BOOKING',
            'serial_number' => 'SN-A-BOOKING',
            'status' => 'ready',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/bookings', [
                'customer_name' => 'Budi Santoso',
                'customer_phone' => '081234567899',
                'asset_ids' => [$assetA->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals($this->bizA->id, $data['business_id']);
        $this->assertEquals($this->branchA->id, $data['affiliate_id']);
        $this->assertEquals('rented', $assetA->fresh()->status);
    }

    public function test_saas_user_can_create_booking_with_explicit_valid_affiliate_id(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        $assetA = Asset::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $this->branchA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-A-BOOKING-2',
            'serial_number' => 'SN-A-BOOKING-2',
            'status' => 'ready',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branchA->id,
                'customer_name' => 'Dewi Sartika',
                'customer_phone' => '081234567898',
                'asset_ids' => [$assetA->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals($this->bizA->id, $data['business_id']);
        $this->assertEquals($this->branchA->id, $data['affiliate_id']);
        $this->assertEquals('rented', $assetA->fresh()->status);
    }

    public function test_user_cannot_create_booking_with_unauthorized_affiliate_from_other_tenant(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        $assetA = Asset::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $this->branchA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-A-BOOKING-3',
            'serial_number' => 'SN-A-BOOKING-3',
            'status' => 'ready',
        ]);

        // Tenant A tries to explicitly specify Tenant B's branch
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branchB->id,
                'customer_name' => 'Attacker',
                'customer_phone' => '081234567897',
                'asset_ids' => [$assetA->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['affiliate_id']);
    }

    public function test_user_cannot_create_booking_with_unauthorized_asset_belonging_to_other_tenant(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        // Asset belongs to Tenant B
        $assetB = Asset::create([
            'business_id' => $this->bizB->id,
            'affiliate_id' => $this->branchB->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-B-BOOKING-CROSS',
            'serial_number' => 'SN-B-BOOKING-CROSS',
            'status' => 'ready',
        ]);

        // Tenant A tries to create booking with Tenant B's asset
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/bookings', [
                'customer_name' => 'Victim',
                'customer_phone' => '081234567896',
                'asset_ids' => [$assetB->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ]);

        $response->assertStatus(422);
        // Asset B must remain ready
        $this->assertEquals('ready', $assetB->fresh()->status);
    }

    public function test_branch_restricted_staff_cannot_create_booking_for_unassigned_branch(): void
    {
        // Create second branch for Business A
        $branchA2 = Affiliate::create([
            'business_id' => $this->bizA->id,
            'code' => 'MG2',
            'name' => 'Mega Jaya Cabang 2',
            'slug' => 'mega-jaya-cabang-2',
            'is_central' => false,
            'is_active' => true,
        ]);

        // Create staff user for Business A
        $staffUser = User::create([
            'name' => 'Staff Cabang 1',
            'email' => 'staff1@tenant.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $memStaff = BusinessMember::create([
            'business_id' => $this->bizA->id,
            'user_id' => $staffUser->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        // Assign staff ONLY to branchA (Cabang 1)
        BranchMember::create([
            'business_member_id' => $memStaff->id,
            'affiliate_id' => $this->branchA->id,
        ]);

        // Asset at Cabang 2
        $assetA2 = Asset::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $branchA2->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-A2-DEVICE',
            'serial_number' => 'SN-A2-DEVICE',
            'status' => 'ready',
        ]);

        $staffToken = $staffUser->createToken('test')->plainTextToken;

        // Staff tries to book asset at Branch 2
        $response = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->withHeader('X-Branch-Id', (string)$this->branchA->id)
            ->postJson('/api/v1/bookings', [
                'affiliate_id' => $branchA2->id,
                'customer_name' => 'Staff Customer',
                'customer_phone' => '081234567895',
                'asset_ids' => [$assetA2->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['affiliate_id']);
    }

    public function test_owner_can_view_and_create_branches(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // 1. List branches
        $listRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson("/api/v1/businesses/{$this->bizA->id}/branches");

        $listRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.code', 'MGA');

        // 2. Create new branch
        $createRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson("/api/v1/businesses/{$this->bizA->id}/branches", [
                'name' => 'Mega Jaya Cabang Canggu',
                'code' => 'CGU',
                'city' => 'Badung',
                'phone' => '081234567888',
                'address' => 'Jl. Pantai Batu Bolong No. 10',
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'CGU')
            ->assertJsonPath('data.name', 'Mega Jaya Cabang Canggu');

        $this->assertDatabaseHas('affiliates', [
            'business_id' => $this->bizA->id,
            'code' => 'CGU',
            'name' => 'Mega Jaya Cabang Canggu',
        ]);
    }

    public function test_owner_can_view_members_and_invite_staff_with_branches(): void
    {
        $token = $this->ownerA->createToken('test')->plainTextToken;

        // 1. View members
        $viewRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson("/api/v1/businesses/{$this->bizA->id}/members");

        $viewRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.role', 'owner');

        // 2. Invite staff to branch A
        $inviteRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson("/api/v1/businesses/{$this->bizA->id}/members", [
                'email' => 'new.kasir@example.com',
                'name' => 'Kasir Baru Canggu',
                'role' => 'staff',
                'branch_ids' => [$this->branchA->id],
            ]);

        $inviteRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.user.email', 'new.kasir@example.com');

        $this->assertDatabaseHas('business_members', [
            'business_id' => $this->bizA->id,
            'role' => 'staff',
        ]);

        // 3. User logs in via Google with that invited email: links seamlessly
        $googleLoginRes = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'dev_token_valid',
            'email' => 'new.kasir@example.com',
            'name' => 'Kasir Baru Google Verified',
        ]);

        $googleLoginRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'new.kasir@example.com')
            ->assertJsonPath('data.businesses.0.id', $this->bizA->id);
    }

    public function test_staff_cannot_create_branches_or_invite_members(): void
    {
        $staffUser = User::create([
            'name' => 'Restricted Staff',
            'email' => 'staff.nopriv@tenant.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->bizA->id,
            'user_id' => $staffUser->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $token = $staffUser->createToken('test')->plainTextToken;

        // Attempt to create branch -> 403 Forbidden
        $resBranch = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson("/api/v1/businesses/{$this->bizA->id}/branches", [
                'name' => 'Unauthorized Branch',
                'code' => 'UNAUTH',
            ]);

        $resBranch->assertStatus(403);

        // Attempt to invite member -> 403 Forbidden
        $resMember = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson("/api/v1/businesses/{$this->bizA->id}/members", [
                'email' => 'hacked@example.com',
                'role' => 'admin',
            ]);

        $resMember->assertStatus(403);
    }

    public function test_cross_business_member_view_and_foreign_branch_assignment_rejected(): void
    {
        $tokenB = $this->ownerB->createToken('test')->plainTextToken;

        // Owner B attempts to view members of Business A -> 403
        $resViewA = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->getJson("/api/v1/businesses/{$this->bizA->id}/members");

        $resViewA->assertStatus(403);

        // Owner B attempts to add a member to Business B, but passes Branch A (which belongs to Business A) -> 422
        $resCrossBranch = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->postJson("/api/v1/businesses/{$this->bizB->id}/members", [
                'email' => 'victim.staff@tenant.com',
                'role' => 'staff',
                'branch_ids' => [$this->branchA->id], // Belongs to bizA!
            ]);

        $resCrossBranch->assertStatus(422)
            ->assertJsonValidationErrors(['branch_ids']);
    }
}


