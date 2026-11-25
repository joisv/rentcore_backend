<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use App\Services\IdempotencyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class NetworkReliabilityAndIdempotencyHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Affiliate $branchA;
    protected User $userA;
    protected User $userB;
    protected PaymentMethod $cashMethod;
    protected Customer $customer;
    protected AssetModel $model;
    protected Asset $asset;
    protected CashDrawerShift $openShift;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->userA = User::create([
            'name' => 'Kasir Cabang A',
            'email' => 'kasir.a@hardening.id',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->userB = User::create([
            'name' => 'Kasir Cabang B',
            'email' => 'kasir.b@hardening.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->businessA = Business::create([
            'name' => 'SewaKasir Hardening Rental',
            'slug' => 'rentcore-hardening-rental',
            'email' => 'owner@hardening.id',
            'owner_id' => $this->userA->id,
            'is_active' => true,
            'shift_enforcement_enabled' => true,
        ]);

        $this->branchA = Affiliate::create([
            'business_id' => $this->businessA->id,
            'name' => 'Cabang Utama',
            'slug' => 'cabang-utama-hardening',
            'code' => 'UTM',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->userA->update([
            'business_id' => $this->businessA->id,
            'current_business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA->id,
        ]);
        $this->userB->update([
            'business_id' => $this->businessA->id,
            'current_business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA->id,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->userA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->userB->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Tunai',
            'code' => 'CASH',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->businessA->id,
            'name' => 'Pelanggan Uji',
            'phone' => '081234567899',
        ]);

        $category = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Smartphone',
            'slug' => 'smartphone-hardening',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $category->id,
            'name' => 'iPhone 16 Pro',
            'slug' => 'iphone-16-pro-hardening',
            'brand' => 'Apple',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'price' => 250000,
            'extension_hourly_rate' => 20000,
            'is_active' => true,
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IP16-001',
            'serial_number' => 'SN-IP16-001',
            'status' => 'ready',
        ]);

        $this->openShift = CashDrawerShift::create([
            'business_id' => $this->businessA->id,
            'branch_id' => $this->branchA->id,
            'shift_number' => 'SHF-UTM-HARD-0001',
            'opened_by' => $this->userA->id,
            'opened_at' => Carbon::now()->subHour(),
            'opening_cash_float' => 500000,
            'status' => 'open',
        ]);
    }

    public function test_duplicate_x_idempotency_key_on_checkout_deduplicates_via_cache(): void
    {
        $token = $this->userA->createToken('test')->plainTextToken;
        $key = (string) Str::uuid();

        $payload = [
            'branch_id' => $this->branchA->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->phone,
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000,
        ];

        $resp1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Idempotency-Key' => $key,
        ])->postJson('/api/v1/bookings', $payload);

        $resp1->assertStatus(201)->assertJsonPath('success', true);
        $this->assertTrue(Cache::has(IdempotencyService::cacheKey($this->businessA->id, $key)));
        $this->assertEquals(1, Booking::count());
        $this->assertEquals(1, PaymentTransaction::count());

        // Duplicate submission with identical X-Idempotency-Key replays cleanly without double-booking
        $resp2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Idempotency-Key' => $key,
        ])->postJson('/api/v1/bookings', $payload);

        $resp2->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_idempotent', true);

        $this->assertEquals(1, Booking::count());
        $this->assertEquals(1, PaymentTransaction::count());
    }

    public function test_duplicate_x_idempotency_key_on_payment_settlement_and_in_flight_conflict(): void
    {
        $token = $this->userA->createToken('test')->plainTextToken;

        $booking = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA->id,
            'booking_code' => 'RC-PAY-IDEMP-01',
            'customer_id' => $this->customer->id,
            'created_by' => $this->userA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 250000,
            'total_bill' => 250000,
            'remaining_balance' => 250000,
            'payment_status' => 'unpaid',
        ]);

        // 1. Concurrent in-flight lock returns 409 Conflict
        $inFlightKey = (string) Str::uuid();
        Cache::add(IdempotencyService::cacheKey($this->businessA->id, $inFlightKey), ['scope' => 'cashier.pay'], 300);

        $conflictResp = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Idempotency-Key' => $inFlightKey,
        ])->postJson('/api/v1/cashier/pay', [
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'settlement',
            'amount' => 250000,
            'cash_received' => 250000,
        ]);

        $conflictResp->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_IN_PROGRESS');
        $this->assertEquals(0, PaymentTransaction::count());

        // 2. Fresh key processes payment once, second call replays from cache without double charge
        $payKey = (string) Str::uuid();
        $payPayload = [
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'settlement',
            'amount' => 250000,
            'cash_received' => 250000,
        ];

        $payResp1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Idempotency-Key' => $payKey,
        ])->postJson('/api/v1/cashier/pay', $payPayload);

        $payResp1->assertStatus(201)->assertJsonPath('success', true);
        $this->assertEquals(1, PaymentTransaction::count());

        $payResp2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Idempotency-Key' => $payKey,
        ])->postJson('/api/v1/cashier/pay', $payPayload);

        $payResp2->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.is_idempotent', true);
        $this->assertEquals(1, PaymentTransaction::count());
    }

    public function test_shift_close_releases_idempotency_key_on_422_and_deduplicates_on_success(): void
    {
        $token = $this->userA->createToken('test')->plainTextToken;
        $key = (string) Str::uuid();

        // First attempt has variance > 5000 without difference_reason -> 422, must release lock
        $failResp = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Idempotency-Key' => $key,
        ])->postJson('/api/v1/cashier/shift/close', [
            'actual_cash_counted' => 400000,
        ]);

        $failResp->assertStatus(422)
            ->assertJsonPath('requires_difference_reason', true);
        $this->assertFalse(Cache::has(IdempotencyService::cacheKey($this->businessA->id, $key)));

        // Retry with same key and valid reason succeeds
        $okResp1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Idempotency-Key' => $key,
        ])->postJson('/api/v1/cashier/shift/close', [
            'actual_cash_counted' => 400000,
            'difference_reason' => 'Selisih kas kecil untuk kembalian',
        ]);

        $okResp1->assertStatus(200)->assertJsonPath('success', true);

        // Replay with same key returns cached response instead of 422 "no open shift"
        $okResp2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Idempotency-Key' => $key,
        ])->postJson('/api/v1/cashier/shift/close', [
            'actual_cash_counted' => 400000,
            'difference_reason' => 'Selisih kas kecil untuk kembalian',
        ]);

        $okResp2->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('is_idempotent', true);
    }

    public function test_tenant_and_user_scoped_rate_limiting_isolates_users_sharing_carrier_ip(): void
    {
        $tokenA = $this->userA->createToken('token-a')->plainTextToken;
        $tokenB = $this->userB->createToken('token-b')->plainTextToken;
        $sharedCarrierIp = '203.0.113.55';

        // Simulate User A exhausting 120 req/min bucket on the shared carrier IP
        $rateKeyA = "api:biz:{$this->businessA->id}:user:{$this->userA->id}";
        $hashedKeyA = md5('api' . $rateKeyA);
        RateLimiter::clear($hashedKeyA);
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($hashedKeyA, 60);
        }

        // User A's 121st request must be throttled with 429
        $respA = $this->withServerVariables(['REMOTE_ADDR' => $sharedCarrierIp])
            ->withHeaders([
                'Authorization' => "Bearer {$tokenA}",
                'X-Business-Id' => (string) $this->businessA->id,
            ])
            ->getJson('/api/v1/customers');

        $respA->assertStatus(429);

        // Reset Sanctum guard cache between different Bearer tokens in the same test process
        $this->app['auth']->forgetGuards();

        // User B on the EXACT same mobile carrier IP must NOT be affected
        $respB = $this->withServerVariables(['REMOTE_ADDR' => $sharedCarrierIp])
            ->withHeaders([
                'Authorization' => "Bearer {$tokenB}",
                'X-Business-Id' => (string) $this->businessA->id,
            ])
            ->getJson('/api/v1/customers');

        $respB->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_list_endpoints_enforce_max_50_per_page_and_never_return_raw_base64(): void
    {
        $token = $this->userA->createToken('test')->plainTextToken;

        // Seed a legacy base64 value in CustomerIdentity & AssetModel to verify sanitization
        CustomerIdentity::create([
            'customer_id' => $this->customer->id,
            'identity_type' => 'KTP',
            'identity_number' => '3271001122334455',
            'file_path' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/fakebase64blob',
            'is_verified' => true,
        ]);

        $this->model->update([
            'image_path' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk',
        ]);

        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
        ];

        $bookingsResp = $this->withHeaders($headers)->getJson('/api/v1/bookings?per_page=500');
        $bookingsResp->assertOk();
        $this->assertLessThanOrEqual(50, $bookingsResp->json('data.per_page'));

        $customersResp = $this->withHeaders($headers)->getJson('/api/v1/customers?per_page=999');
        $customersResp->assertOk();
        $this->assertLessThanOrEqual(50, $customersResp->json('data.per_page'));
        $this->assertStringNotContainsString('data:image/', $customersResp->getContent());

        $shiftsResp = $this->withHeaders($headers)->getJson('/api/v1/cashier/shift/history?per_page=250');
        $shiftsResp->assertOk();
        $this->assertLessThanOrEqual(50, $shiftsResp->json('meta.per_page'));

        $assetsResp = $this->withHeaders($headers)->getJson('/api/v1/assets?per_page=200');
        $assetsResp->assertOk();
        $this->assertLessThanOrEqual(50, $assetsResp->json('data.per_page'));
        $this->assertNull($assetsResp->json('data.data.0.model.image_url'));

        $closingResp = $this->withHeaders($headers)->getJson('/api/v1/cashier/closing-summary?per_page=300');
        $closingResp->assertOk();
        $this->assertLessThanOrEqual(50, $closingResp->json('data.transactions_pagination.per_page'));
    }
}
