<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BranchExpense;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class Wave1FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Business $businessB;
    protected Affiliate $branchA1;
    protected Affiliate $branchA2;
    protected Affiliate $branchB1;
    protected User $ownerA;
    protected User $adminA1;
    protected User $adminB1;
    protected User $staffA1;
    protected AssetModel $iphoneModel;
    protected Asset $assetA1;
    protected Customer $customerA;
    protected PaymentMethod $cashMethod;
    protected CashDrawerShift $shiftA1;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('manager-pin:verify:0');

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai (Cash)',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->ownerA = User::create([
            'name' => 'Owner A',
            'email' => 'owner.a@wave1.test',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
            'manager_override_pin' => Hash::make('111111'),
        ]);

        $ownerB = User::create([
            'name' => 'Owner B',
            'email' => 'owner.b@wave1.test',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
            'manager_override_pin' => Hash::make('222222'),
        ]);

        $this->businessA = Business::create([
            'name' => 'Rental A',
            'slug' => 'rental-a',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'Rental B',
            'slug' => 'rental-b',
            'owner_id' => $ownerB->id,
            'is_active' => true,
        ]);

        $this->branchA1 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'A01',
            'slug' => 'a-01',
            'name' => 'Cabang A1',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'A02',
            'slug' => 'a-02',
            'name' => 'Cabang A2',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->branchB1 = Affiliate::create([
            'business_id' => $this->businessB->id,
            'code' => 'B01',
            'slug' => 'b-01',
            'name' => 'Cabang B1',
            'is_central' => true,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->adminA1 = User::create([
            'name' => 'Admin A1',
            'email' => 'admin.a1@wave1.test',
            'password' => Hash::make('secret123'),
            'role' => 'admin',
            'affiliate_id' => $this->branchA1->id,
            'is_active' => true,
            'manager_override_pin' => Hash::make('333333'),
        ]);
        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->adminA1->id,
            'affiliate_id' => $this->branchA1->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->adminB1 = User::create([
            'name' => 'Admin B1',
            'email' => 'admin.b1@wave1.test',
            'password' => Hash::make('secret123'),
            'role' => 'admin',
            'affiliate_id' => $this->branchB1->id,
            'is_active' => true,
            'manager_override_pin' => Hash::make('444444'),
        ]);
        BusinessMember::create([
            'business_id' => $this->businessB->id,
            'user_id' => $this->adminB1->id,
            'affiliate_id' => $this->branchB1->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staffA1 = User::create([
            'name' => 'Kasir A1',
            'email' => 'staff.a1@wave1.test',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA1->id,
            'is_active' => true,
            // Even if staff somehow had a legacy PIN column, role 'staff' must NOT verify!
            'manager_override_pin' => Hash::make('555555'),
        ]);
        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA1->id,
            'affiliate_id' => $this->branchA1->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $cat = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Phones',
            'slug' => 'phones',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->iphoneModel = AssetModel::create([
            'category_id' => $cat->id,
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro-w1',
            'brand' => 'Apple',
            'is_addon' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->iphoneModel->id,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'duration_hours' => 24,
            'price' => 250000.00,
            'extension_hourly_rate' => 20000.00,
            'is_active' => true,
        ]);

        $this->assetA1 = Asset::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'W1-IP15-01',
            'serial_number' => 'SN-W1-01',
            'status' => 'ready',
        ]);

        $this->customerA = Customer::create([
            'business_id' => $this->businessA->id,
            'name' => 'Pelanggan Wave 1',
            'phone' => '081299990000',
            'email' => 'customer.w1@test.com',
        ]);

        $this->shiftA1 = CashDrawerShift::create([
            'business_id' => $this->businessA->id,
            'branch_id' => $this->branchA1->id,
            'opened_by' => $this->staffA1->id,
            'shift_number' => 'SHF-W1-001',
            'opening_cash_float' => 500000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHours(2),
        ]);
    }

    protected function authHeaders(User $user, ?Affiliate $branch = null): array
    {
        auth()->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        return [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->businessA->id,
            'X-Branch-Id' => (string) ($branch?->id ?? $this->branchA1->id),
        ];
    }

    public function test_blind_close_redacts_expected_cash_until_shift_closes(): void
    {
        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'branch_id' => $this->branchA1->id,
            'shift_id' => $this->shiftA1->id,
            'transaction_code' => 'TX-BLIND-01',
            'receipt_number' => 'REC-BLIND-01',
            'booking_id' => Booking::create([
                'business_id' => $this->businessA->id,
                'affiliate_id' => $this->branchA1->id,
                'booking_code' => 'BK-BLIND-01',
                'customer_id' => $this->customerA->id,
                'created_by' => $this->staffA1->id,
                'start_at' => Carbon::now()->subHour(),
                'end_at' => Carbon::now()->addHours(23),
                'duration_hours' => 24,
                'duration_unit' => 'hour',
                'duration_value' => 24,
                'status' => 'rented',
                'total_bill' => 250000.00,
                'paid_amount' => 250000.00,
                'remaining_balance' => 0.00,
                'payment_status' => 'paid',
            ])->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'rental',
            'direction' => 'inflow',
            'status' => 'completed',
            'amount' => 250000.00,
            'cash_received' => 250000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now()->subMinutes(10),
        ]);

        BranchExpense::create([
            'business_id' => $this->businessA->id,
            'branch_id' => $this->branchA1->id,
            'shift_id' => $this->shiftA1->id,
            'expense_number' => 'EXP-BLIND-01',
            'category' => 'supplies',
            'title' => 'Beli Lakban',
            'amount' => 25000.00,
            'payment_source' => 'cash_drawer',
            'status' => 'disbursed',
            'requested_by' => $this->staffA1->id,
            'approved_by' => $this->adminA1->id,
            'disbursed_at' => Carbon::now()->subMinutes(5),
        ]);

        // 1. currentShift must omit expected cash & flow columns while shift is open
        $this->withHeaders($this->authHeaders($this->staffA1))
            ->getJson('/api/v1/cashier/shift/current?branch_id=' . $this->branchA1->id)
            ->assertOk()
            ->assertJsonPath('data.shift.is_blind_close', true)
            ->assertJsonMissingPath('data.shift.expected_cash_now')
            ->assertJsonMissingPath('data.shift.expected_cash_total')
            ->assertJsonMissingPath('data.shift.current_cash_inflow')
            ->assertJsonMissingPath('data.shift.current_cash_refund')
            ->assertJsonMissingPath('data.shift.current_cash_expenses');

        // 2. closingSummary must omit expected_cash_in_drawer & active_shift expected cash while open
        $this->withHeaders($this->authHeaders($this->staffA1))
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branchA1->id)
            ->assertOk()
            ->assertJsonPath('data.cash_reconciliation.active_shift.is_blind_close', true)
            ->assertJsonMissingPath('data.cash_reconciliation.expected_cash_in_drawer')
            ->assertJsonMissingPath('data.cash_reconciliation.active_shift.expected_cash_now')
            ->assertJsonMissingPath('data.cash_reconciliation.active_shift.current_cash_inflow');

        // 3. Closing shift reveals authoritative expected_cash_total (500k + 250k - 25k = 725k) & difference
        $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson('/api/v1/cashier/shift/close', [
                'shift_id' => $this->shiftA1->id,
                'actual_cash_counted' => 720000.00,
                'difference_reason' => 'Selisih minor tidak wajib > 5k',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_cash_total', 725000)
            ->assertJsonPath('data.actual_cash_counted', 720000)
            ->assertJsonPath('data.cash_difference', -5000);
    }

    public function test_manager_pin_scoping_and_rate_limiting(): void
    {
        $booking = Booking::create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'booking_code' => 'BK-PIN-01',
            'customer_id' => $this->customerA->id,
            'created_by' => $this->staffA1->id,
            'start_at' => Carbon::now()->addDay(),
            'end_at' => Carbon::now()->addDays(2),
            'duration_hours' => 24,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'status' => 'booked',
            'total_bill' => 250000.00,
            'paid_amount' => 100000.00,
            'remaining_balance' => 150000.00,
            'payment_status' => 'partial',
        ]);

        PaymentTransaction::create([
            'business_id' => $this->businessA->id,
            'branch_id' => $this->branchA1->id,
            'shift_id' => $this->shiftA1->id,
            'transaction_code' => 'TX-PIN-DP-01',
            'receipt_number' => 'REC-PIN-DP-01',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staffA1->id,
            'type' => 'dp',
            'direction' => 'inflow',
            'status' => 'completed',
            'amount' => 100000.00,
            'cash_received' => 100000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now()->subMinutes(10),
        ]);

        // Cross-tenant admin PIN (444444) and non-manager staff PIN (555555) must BOTH fail
        foreach (['444444', '555555', '000001', '000002', '000003'] as $badPin) {
            $this->withHeaders($this->authHeaders($this->staffA1))
                ->postJson("/api/v1/bookings/{$booking->id}/cancel", [
                    'refund_dp' => true,
                    'manager_pin' => $badPin,
                ])
                ->assertStatus(422);
        }

        // 6th attempt within 5 minutes triggers 429 PIN_RATE_LIMITED even with valid PIN
        $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson("/api/v1/bookings/{$booking->id}/cancel", [
                'refund_dp' => true,
                'manager_pin' => '333333',
            ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'PIN_RATE_LIMITED');
    }

    public function test_server_pricing_authority_blocks_client_tampering_without_manager_pin(): void
    {
        // Client passes forged unit_prices (50k instead of server tier 250k) WITHOUT manager_pin
        $createRes = $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => false,
                'booking_mode' => 'direct',
                'customer_name' => 'Tamper Check',
                'customer_phone' => '081122334455',
                'asset_ids' => [$this->assetA1->id],
                'duration_unit' => 'hour',
                'duration_value' => 24,
                'unit_prices' => [
                    (string) $this->assetA1->id => 50000,
                ],
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 250000,
            ])
            ->assertStatus(201);

        // Server MUST use tier price 250.000, ignoring forged 50.000!
        $this->assertEquals(250000.0, (float) $createRes->json('data.total_bill'));
        $bookingId = (int) $createRes->json('data.id');

        // Return inspection with damage_fee > 0 without manager_pin must fail with 422
        $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson("/api/v1/bookings/{$bookingId}/return", [
                'condition_grade' => 'B',
                'checklist_results' => ['icloud_removed' => true],
                'damage_fee' => 75000,
            ])
            ->assertStatus(422);

        // With valid branch admin PIN (333333), return inspection with damage_fee succeeds
        $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson("/api/v1/bookings/{$bookingId}/return", [
                'condition_grade' => 'B',
                'checklist_results' => ['icloud_removed' => true],
                'damage_fee' => 75000,
                'manager_pin' => '333333',
            ])
            ->assertOk();
    }

    public function test_booking_cancel_endpoint_releases_unit_refunds_dp_and_recalculates_financials(): void
    {
        $createRes = $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'status' => 'booked',
                'customer_name' => 'Cancel DP Customer',
                'customer_phone' => '081344556677',
                'asset_ids' => [$this->assetA1->id],
                'asset_model_id' => $this->iphoneModel->id,
                'start_at' => Carbon::now()->addDay()->toIso8601String(),
                'duration_unit' => 'hour',
                'duration_value' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 100000,
                'dp_amount' => 100000,
                'cash_received' => 100000,
            ])
            ->assertStatus(201);

        $bookingId = (int) $createRes->json('data.id');
        $this->assertEquals(100000.0, (float) $createRes->json('data.paid_amount'));
        $this->assertEquals(150000.0, (float) $createRes->json('data.remaining_balance'));
        $this->assertEquals('partial', $createRes->json('data.payment_status'));

        // Cancel with DP refund WITHOUT Manager PIN must be rejected
        $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson("/api/v1/bookings/{$bookingId}/cancel", [
                'refund_dp' => true,
                'reason' => 'Batal karena reschedule gagal',
            ])
            ->assertStatus(422);

        // Cancel WITH valid branch Admin PIN succeeds, releases asset, records refund outflow & recalculates financials
        $cancelRes = $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson("/api/v1/bookings/{$bookingId}/cancel", [
                'refund_dp' => true,
                'reason' => 'Batal atas permintaan pelanggan',
                'manager_pin' => '333333',
                'payment_method_id' => $this->cashMethod->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertEquals(0.0, (float) $cancelRes->json('data.paid_amount'));
        $this->assertEquals(250000.0, (float) $cancelRes->json('data.remaining_balance'));
        $this->assertEquals('ready', $this->assetA1->fresh()->status);

        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $bookingId,
            'type' => 'refund',
            'direction' => 'outflow',
            'amount' => 100000.00,
        ]);
    }

    public function test_idempotency_key_deduplicates_booking_and_payment_creation(): void
    {
        $key = (string) Str::uuid();
        $payload = [
            'is_scheduled' => false,
            'booking_mode' => 'direct',
            'customer_name' => 'Double Tap User',
            'customer_phone' => '081566778899',
            'asset_ids' => [$this->assetA1->id],
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 300000,
            'idempotency_key' => $key,
        ];

        $first = $this->withHeaders($this->authHeaders($this->staffA1))
            ->postJson('/api/v1/bookings', $payload)
            ->assertStatus(201);

        $second = $this->withHeaders($this->authHeaders($this->staffA1))
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/v1/bookings', $payload)
            ->assertStatus(200)
            ->assertJsonPath('is_idempotent', true);

        $this->assertEquals($first->json('data.id'), $second->json('data.id'));
        $this->assertEquals(1, Booking::where('idempotency_key', $key)->count());
        $this->assertEquals(1, PaymentTransaction::where('idempotency_key', $key)->count());
    }
}
