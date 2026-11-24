<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QrisSelectionAndImageGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Business $business;
    protected Affiliate $branch;
    protected Customer $customer;
    protected Asset $asset;
    protected PaymentMethod $shopeePay;
    protected PaymentMethod $qris;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');

        // Notice: seed SHOPEEPAY first so its numeric ID precedes QRIS (reproducing real migrated DB order)
        $this->shopeePay = PaymentMethod::where('code', 'SHOPEEPAY')->first()
            ?? PaymentMethod::create(['code' => 'SHOPEEPAY', 'name' => 'ShopeePay', 'is_active' => true]);
        $this->qris = PaymentMethod::where('code', 'QRIS')->first()
            ?? PaymentMethod::create(['code' => 'QRIS', 'name' => 'QRIS', 'is_active' => true]);

        $this->owner = User::create([
            'name' => 'Owner Qris',
            'email' => 'owner.qris@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'AwanRental',
            'slug' => 'awanrental-qris',
            'owner_id' => $this->owner->id,
            'shift_enforcement_enabled' => false,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'AWA',
            'name' => 'AwanRental Pusat',
            'slug' => 'awanrental-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $cat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones-qris',
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $cat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro-qris',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $model->id,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'duration_hours' => 24,
            'price' => 150000.00,
            'is_active' => true,
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => 'AWA-IP15-01',
            'status' => 'ready',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
        ]);
    }

    protected function authHeaders(): array
    {
        $token = $this->owner->createToken('test')->plainTextToken;
        return [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];
    }

    public function test_enabling_static_or_dynamic_qris_without_uploaded_image_returns_422(): void
    {
        $headers = $this->authHeaders();

        // qris_enabled = true without image -> 422
        $this->withHeaders($headers)
            ->putJson('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'qris_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qris_statis_enabled']);

        // static_qris = true without image -> 422
        $this->withHeaders($headers)
            ->putJson('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'static_qris' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['qris_statis_enabled']);

        // dynamic_qris = true without image -> 422
        $this->withHeaders($headers)
            ->putJson('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'dynamic_qris' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_dynamic_qris_enabled']);

        // Unconfigured GET returns has_qris_image=false and qris_statis_enabled=false
        $this->withHeaders($headers)
            ->getJson('/api/v1/payment-settings?branch_id=' . $this->branch->id)
            ->assertOk()
            ->assertJsonPath('data.config.has_qris_image', false)
            ->assertJsonPath('data.config.qris_statis_enabled', false)
            ->assertJsonPath('data.config.is_dynamic_qris_enabled', false);
    }

    public function test_removing_qris_image_turns_off_static_and_dynamic_qris_toggles(): void
    {
        $headers = $this->authHeaders();
        $fixturePath = base_path('tests/Fixtures/sample_qris.png');
        $tempPath = tempnam(sys_get_temp_dir(), 'qris_') . '.png';
        copy($fixturePath, $tempPath);
        $file = new UploadedFile($tempPath, 'qris.png', 'image/png', null, true);

        // Upload valid QRIS image + enable static & dynamic QRIS
        $this->withHeaders($headers)
            ->post('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'qris_image' => $file,
                'qris_statis_enabled' => true,
                'is_dynamic_qris_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.config.has_qris_image', true)
            ->assertJsonPath('data.config.qris_statis_enabled', true)
            ->assertJsonPath('data.config.is_dynamic_qris_enabled', true);

        // Remove QRIS image -> automatically turns off both toggles
        $this->withHeaders($headers)
            ->putJson('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'remove_qris_image' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.config.has_qris_image', false)
            ->assertJsonPath('data.config.qris_statis_enabled', false)
            ->assertJsonPath('data.config.is_dynamic_qris_enabled', false);
    }

    public function test_qris_payment_records_qris_and_never_falls_back_to_shopeepay(): void
    {
        $headers = $this->authHeaders();

        // Simulate real DB state where QRIS image is configured and explicit 'qris' method/channel is passed
        // even if a stale client sends SHOPEEPAY's id!
        $this->business->update([
            'payment_methods_config' => [
                'qris_statis_enabled' => true,
                'qris_image_path' => 'qris/biz_1.png',
                'cash_enabled' => true,
                'ewallet_enabled' => true,
            ],
        ]);

        CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->owner->id,
            'shift_number' => 'SHF-QRIS-001',
            'opening_cash_float' => 100000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subMinute(),
        ]);

        // 1. Direct rental checkout with payment_method='qris' & payment_channel='qris'
        $bookingResp = $this->withHeaders($headers)
            ->postJson('/api/v1/bookings', [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'customer_name' => $this->customer->name,
                'customer_phone' => $this->customer->phone,
                'asset_ids' => [$this->asset->id],
                'duration_unit' => 'hour',
                'duration_value' => 24,
                'payment_method_id' => $this->shopeePay->id, // stale fallback ID!
                'payment_method' => 'qris',
                'payment_channel' => 'qris',
                'cash_received' => 150000,
            ])
            ->assertStatus(201);

        $bookingId = (int) $bookingResp->json('data.id');
        $tx = PaymentTransaction::where('booking_id', $bookingId)->first();
        $this->assertNotNull($tx);
        $this->assertSame($this->qris->id, $tx->payment_method_id);
        $this->assertNotSame($this->shopeePay->id, $tx->payment_method_id);

        // 2. Receipt returns 'QRIS'
        $this->withHeaders($headers)
            ->getJson("/api/v1/cashier/transactions/{$tx->id}/receipt")
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'QRIS');

        // 3. Cashier pay endpoint with only payment_method='qris' records QRIS on booking settlement
        $scheduledBooking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'booking_code' => 'RC-QRIS-SETTLE-01',
            'affiliate_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->owner->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'status' => 'booked',
            'subtotal_amount' => 100000.00,
            'total_bill' => 100000.00,
            'remaining_balance' => 100000.00,
            'payment_status' => 'unpaid',
        ]);

        $payResp = $this->withHeaders($headers)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $scheduledBooking->id,
                'payment_method' => 'qris',
                'payment_channel' => 'qris',
                'amount' => 100000,
            ])
            ->assertStatus(201);

        $payTxId = (int) $payResp->json('data.payment.id');
        $payTx = PaymentTransaction::findOrFail($payTxId);
        $this->assertSame($this->qris->id, $payTx->payment_method_id);

        // 4. Closing summary / Revenue report groups under 'QRIS' and NEVER 'ShopeePay'
        $summaryResp = $this->withHeaders($headers)
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branch->id)
            ->assertOk();

        $breakdown = $summaryResp->json('data.breakdown_by_method');
        $this->assertArrayHasKey('QRIS', $breakdown);
        $this->assertArrayNotHasKey('ShopeePay', $breakdown);
        $this->assertEquals(250000.0, (float) $breakdown['QRIS']['total']);
    }
}
