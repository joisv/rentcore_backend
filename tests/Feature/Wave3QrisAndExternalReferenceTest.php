<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use App\Services\QrisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Wave3QrisAndExternalReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $owner;
    protected User $staff;
    protected PaymentMethod $qrisMethod;
    protected PaymentMethod $bcaMethod;
    protected Asset $asset;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->qrisMethod = PaymentMethod::create([
            'code' => 'QRIS',
            'name' => 'QRIS',
            'type' => 'qris',
            'is_active' => true,
        ]);

        $this->bcaMethod = PaymentMethod::create([
            'code' => 'BCA',
            'name' => 'Transfer BCA',
            'type' => 'bank_transfer',
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Owner Wave 3',
            'email' => 'owner.w3@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Rental Wave 3',
            'slug' => 'rental-wave-3',
            'owner_id' => $this->owner->id,
            'shift_enforcement_enabled' => true,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'W3C1',
            'slug' => 'w3-c1',
            'name' => 'Cabang Wave 3',
            'is_central' => true,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Staff Wave 3',
            'email' => 'staff.w3@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'affiliate_id' => $this->branch->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'affiliate_id' => $this->branch->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $cat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones W3',
            'slug' => 'smartphones-w3',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $cat->id,
            'name' => 'iPhone 16 Pro Max',
            'slug' => 'iphone-16-promax-w3',
            'brand' => 'Apple',
            'is_addon' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $model->id,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'duration_hours' => 24,
            'price' => 250000.00,
            'extension_hourly_rate' => 20000.00,
            'is_active' => true,
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => 'W3-IP16-01',
            'serial_number' => 'SN-W3-01',
            'status' => 'ready',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Pelanggan Wave 3',
            'phone' => '081200003333',
        ]);
    }

    protected function authHeaders(User $user): array
    {
        auth()->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        return [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];
    }

    private static function tlv(string $tag, string $value): string
    {
        return $tag . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    public function test_qris_service_mutates_only_root_tlv_nodes_and_preserves_nested_lookalikes(): void
    {
        // Build byte-accurate EMVCo TLV where nested tags contain '010211' and '540299'
        $tag26Value = self::tlv('00', 'ID.CO.GOPAY.WWW') . self::tlv('01', '11') . self::tlv('02', '936005402991234');
        $merchantNameWithLookalikes = 'TOKO 540299 010211';
        $tag62Value = self::tlv('01', '11') . self::tlv('05', '540299');

        $body = self::tlv('00', '01')
            . self::tlv('01', '11')
            . self::tlv('26', $tag26Value)
            . self::tlv('52', '5812')
            . self::tlv('53', '360')
            . self::tlv('54', '10000')
            . self::tlv('58', 'ID')
            . self::tlv('59', $merchantNameWithLookalikes)
            . self::tlv('60', 'JAKARTA')
            . self::tlv('61', '12340')
            . self::tlv('62', $tag62Value)
            . '6304';

        $qrisService = app(QrisService::class);
        $staticPayload = $body . $qrisService->calculateCrc16($body);
        $this->assertTrue($qrisService->hasValidCrc($staticPayload));

        $dynamicPayload = $qrisService->generateDynamicQris($staticPayload, 175000);

        $this->assertTrue($qrisService->hasValidCrc($dynamicPayload));

        // Walk root TLVs sequentially to verify root-only mutation and nested preservation
        $rootBody = substr($dynamicPayload, 0, -4);
        $byTag = [];
        $offset = 0;
        while ($offset < strlen($rootBody)) {
            $tag = substr($rootBody, $offset, 2);
            $len = (int) substr($rootBody, $offset + 2, 2);
            $val = substr($rootBody, $offset + 4, $len);
            $byTag[$tag] = $val;
            $offset += 4 + $len;
        }

        // Root tag 01 changed to dynamic ('12') and root tag 54 replaced with integer amount
        $this->assertSame('12', $byTag['01']);
        $this->assertSame('175000', $byTag['54']);

        // Nested lookalikes inside tag 26, tag 59, and tag 62 remain completely untouched
        $this->assertSame($tag26Value, $byTag['26']);
        $this->assertSame($merchantNameWithLookalikes, $byTag['59']);
        $this->assertSame($tag62Value, $byTag['62']);
    }

    public function test_external_reference_persisted_on_booking_and_cashier_pay_and_shift_close_returns_metadata(): void
    {
        $shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->staff->id,
            'shift_number' => 'SHF-W3-001',
            'opening_cash_float' => 300000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        // 1. Booking with DP via QRIS + external_reference
        $bookingRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'customer_name' => 'Pelanggan QRIS Ref',
                'customer_phone' => '081233445599',
                'asset_model_id' => $this->asset->model_id,
                'start_at' => Carbon::now()->addHours(2)->toIso8601String(),
                'duration_unit' => 'hour',
                'duration_value' => 24,
                'paid_amount' => 100000,
                'payment_method_id' => $this->qrisMethod->id,
                'external_reference' => 'REF-QRIS-99812',
            ])
            ->assertStatus(201);

        $bookingId = (int) $bookingRes->json('data.id');
        $bookingTx = PaymentTransaction::where('booking_id', $bookingId)->first();
        $this->assertNotNull($bookingTx);
        $this->assertSame('REF-QRIS-99812', $bookingTx->external_reference);

        // 2. Settlement via Transfer BCA + external_reference
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $bookingId,
                'payment_method_id' => $this->bcaMethod->id,
                'payment_type' => 'settlement',
                'amount' => 150000,
                'external_reference' => 'APR-BANK-77441',
            ])
            ->assertStatus(201);

        $settlementTx = PaymentTransaction::where('booking_id', $bookingId)
            ->where('type', 'settlement')
            ->first();
        $this->assertNotNull($settlementTx);
        $this->assertSame('APR-BANK-77441', $settlementTx->external_reference);

        // 3. Close shift as owner -> response includes full closing slip metadata
        $closeRes = $this->withHeaders($this->authHeaders($this->owner))
            ->postJson('/api/v1/cashier/shift/close', [
                'shift_id' => $shift->id,
                'actual_cash_counted' => 300000,
            ])
            ->assertOk()
            ->assertJsonPath('data.branch_name', 'Cabang Wave 3')
            ->assertJsonPath('data.opened_by_name', 'Staff Wave 3')
            ->assertJsonPath('data.closed_by_name', 'Owner Wave 3')
            ->assertJsonPath('data.total_transactions', 2);

        $this->assertEquals(250000.0, (float) $closeRes->json('data.non_cash_inflow'));
        $this->assertEquals(300000.0, (float) $closeRes->json('data.expected_cash_total'));
    }
}
