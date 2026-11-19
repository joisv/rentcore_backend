<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ExpenseController;
use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Wave2ReconciliationAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $owner;
    protected User $staff;
    protected PaymentMethod $cashMethod;
    protected AssetModel $model;
    protected Asset $asset;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai (Cash)',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Owner Wave 2',
            'email' => 'owner.w2@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
            'manager_override_pin' => Hash::make('123456'),
        ]);

        $this->business = Business::create([
            'name' => 'Rental Wave 2',
            'slug' => 'rental-wave-2',
            'owner_id' => $this->owner->id,
            'shift_enforcement_enabled' => true,
            'timezone' => 'Asia/Jakarta',
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'W2C1',
            'slug' => 'w2-c1',
            'name' => 'Cabang Wave 2',
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
            'name' => 'Staff Wave 2',
            'email' => 'staff.w2@test.com',
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
            'name' => 'Smartphones',
            'slug' => 'smartphones-w2',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'category_id' => $cat->id,
            'name' => 'iPhone 16 Pro',
            'slug' => 'iphone-16-pro-w2',
            'brand' => 'Apple',
            'is_addon' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
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
            'model_id' => $this->model->id,
            'asset_code' => 'W2-IP16-01',
            'serial_number' => 'SN-W2-01',
            'status' => 'ready',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Pelanggan Wave 2',
            'phone' => '081200001111',
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

    public function test_cash_drawer_expenses_require_shift_bind_shift_id_and_ignore_non_drawer_and_orphaned_txs(): void
    {
        // 1. Recording cash_drawer expense without an open shift must fail with HTTP 422
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Tisu Kasir',
                'amount' => 20000,
                'category' => 'supplies',
                'payment_source' => 'cash_drawer',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['Buka shift kasir terlebih dahulu untuk pengeluaran dari laci tunai.']);

        // 2. Open Shift 1 (float 300k), record cash_drawer expense (50k) + bank_transfer expense (200k)
        $shift1 = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->staff->id,
            'shift_number' => 'SHF-W2-001',
            'opening_cash_float' => 300000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHours(2),
        ]);

        $expResp = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Air Galon',
                'amount' => 50000,
                'category' => 'operational',
                'payment_source' => 'cash_drawer',
            ])
            ->assertStatus(201);
        $this->assertEquals($shift1->id, (int) $expResp->json('data.shift_id'));

        // Non-cash_drawer expense does not affect cash drawer
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Tagihan Listrik Transfer',
                'amount' => 200000,
                'category' => 'utilities',
                'payment_source' => 'bank_transfer',
                'manager_pin' => '123456',
            ])
            ->assertStatus(201);

        // Orphaned cash transaction (shift_id = null) must be ignored when shift_enforcement_enabled is true
        $dummyBooking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'BK-ORPHAN-01',
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'start_at' => Carbon::now()->subHour(),
            'end_at' => Carbon::now()->addHours(23),
            'duration_hours' => 24,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'status' => 'rented',
            'total_bill' => 100000.00,
            'paid_amount' => 100000.00,
            'remaining_balance' => 0.00,
            'payment_status' => 'paid',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => null,
            'transaction_code' => 'TX-ORPHAN-01',
            'receipt_number' => 'REC-ORPHAN-01',
            'booking_id' => $dummyBooking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staff->id,
            'type' => 'settlement',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 100000.00,
            'cash_received' => 100000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now()->subMinutes(10),
        ]);

        // Close Shift 1: expected = 300k float + 0 shift tx - 50k drawer expense = 250k
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/shift/close', [
                'shift_id' => $shift1->id,
                'actual_cash_counted' => 250000,
            ])
            ->assertOk()
            ->assertJsonPath('data.expected_cash_total', 250000)
            ->assertJsonPath('data.cash_expenses', 50000)
            ->assertJsonPath('data.cash_inflow', 0);

        // 3. Open Shift 2 (float 400k), create & approve pending expense keeping original shift_id, then close
        $shift2 = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->staff->id,
            'shift_number' => 'SHF-W2-002',
            'opening_cash_float' => 400000.00,
            'status' => 'open',
            'opened_at' => Carbon::now(),
        ]);

        // Approve preserves null -> binds to open shift2 if shift_id was null
        $pendingExp = BranchExpense::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => null,
            'expense_number' => 'EXP-PENDING-01',
            'category' => 'operational',
            'title' => 'Materai',
            'amount' => 25000,
            'payment_source' => 'cash_drawer',
            'status' => 'pending_approval',
            'requested_by' => $this->staff->id,
        ]);

        $this->withHeaders($this->authHeaders($this->owner))
            ->postJson("/api/v1/cashier/expenses/{$pendingExp->id}/approve")
            ->assertOk();
        $this->assertEquals($shift2->id, $pendingExp->fresh()->shift_id);

        // Pre-bound pending expense preserves original shift1 even when approved during shift2
        $preboundExp = BranchExpense::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $shift1->id,
            'expense_number' => 'EXP-PREBOUND-01',
            'category' => 'operational',
            'title' => 'Klaim Parkir Shift 1',
            'amount' => 10000,
            'payment_source' => 'cash_drawer',
            'status' => 'pending_approval',
            'requested_by' => $this->staff->id,
        ]);
        $this->withHeaders($this->authHeaders($this->owner))
            ->postJson("/api/v1/cashier/expenses/{$preboundExp->id}/approve")
            ->assertOk();
        $this->assertEquals($shift1->id, $preboundExp->fresh()->shift_id);

        // Close Shift 2: expected = 400k float - 25k shift2 expense = 375k
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/shift/close', [
                'shift_id' => $shift2->id,
                'actual_cash_counted' => 375000,
            ])
            ->assertOk()
            ->assertJsonPath('data.expected_cash_total', 375000);

        // 4. Closing Summary when NO shift is open sums opening floats across both shifts (300k + 400k = 700k)
        $summaryRes = $this->withHeaders($this->authHeaders($this->owner))
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branch->id . '&date=' . Carbon::now('Asia/Jakarta')->toDateString())
            ->assertOk();

        $this->assertEquals(700000.0, (float) $summaryRes->json('data.cash_reconciliation.opening_cash_float'));
        // Expected drawer = 700k float + 100k cash tx - (50k + 25k + 10k) disbursed shift cash expenses = 715k
        $this->assertEquals(715000.0, (float) $summaryRes->json('data.cash_reconciliation.expected_cash_in_drawer'));
    }

    public function test_security_deposit_excluded_from_net_revenue_but_included_in_cash_drawer_balance(): void
    {
        $shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->staff->id,
            'shift_number' => 'SHF-DEP-01',
            'opening_cash_float' => 500000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        // Counter rental: 250k rental + 100k deposit = 350k total_bill
        $createRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => false,
                'booking_mode' => 'direct',
                'customer_name' => 'Deposit Customer',
                'customer_phone' => '081233445566',
                'asset_ids' => [$this->asset->id],
                'duration_unit' => 'hour',
                'duration_value' => 24,
                'deposit_fee' => 100000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 400000,
            ])
            ->assertStatus(201);

        $bookingId = (int) $createRes->json('data.id');
        $this->assertEquals('paid', $createRes->json('data.payment_status'));
        $this->assertEquals(0.0, (float) $createRes->json('data.remaining_balance'));

        // Distinct settlement (250k) and deposit (100k) transactions recorded
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $bookingId,
            'type' => 'settlement',
            'direction' => 'inflow',
            'amount' => 250000.00,
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $bookingId,
            'type' => 'deposit',
            'direction' => 'inflow',
            'amount' => 100000.00,
        ]);

        // Net daily revenue (DashboardController@kpi + closingSummary) MUST be 250k (not 350k)
        $kpiRes = $this->withHeaders($this->authHeaders($this->owner))
            ->getJson('/api/v1/dashboard/kpi?branch_id=' . $this->branch->id)
            ->assertOk();
        $this->assertEquals(250000.0, (float) $kpiRes->json('data.kpi.today_revenue'));

        $todayStr = Carbon::now('Asia/Jakarta')->toDateString();
        $closingPreReturn = $this->withHeaders($this->authHeaders($this->owner))
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branch->id . '&date=' . $todayStr)
            ->assertOk();
        $this->assertEquals(250000.0, (float) $closingPreReturn->json('data.total_revenue'));

        // Process on-time clean return -> records 100k deposit_refund outflow
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingId}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
            ])
            ->assertOk();

        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $bookingId,
            'type' => 'deposit_refund',
            'direction' => 'outflow',
            'amount' => 100000.00,
        ]);

        // Net revenue remains 250k (NOT 150k after deposit refund!)
        $kpiAfterReturn = $this->withHeaders($this->authHeaders($this->owner))
            ->getJson('/api/v1/dashboard/kpi?branch_id=' . $this->branch->id)
            ->assertOk();
        $this->assertEquals(250000.0, (float) $kpiAfterReturn->json('data.kpi.today_revenue'));

        $closingAfterReturn = $this->withHeaders($this->authHeaders($this->owner))
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branch->id . '&date=' . $todayStr)
            ->assertOk();
        $this->assertEquals(250000.0, (float) $closingAfterReturn->json('data.total_revenue'));

        // Closing shift tracks physical drawer cash: 500k float + 350k cash inflows - 100k deposit_refund = 750k
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/shift/close', [
                'shift_id' => $shift->id,
                'actual_cash_counted' => 750000,
            ])
            ->assertOk()
            ->assertJsonPath('data.expected_cash_total', 750000)
            ->assertJsonPath('data.cash_inflow', 350000)
            ->assertJsonPath('data.cash_refund', 100000);
    }

    public function test_payment_settings_does_not_cache_is_owner_or_admin_across_roles(): void
    {
        // Owner requests settings first -> warms branch_{id}_payment_settings cache
        $ownerRes = $this->withHeaders($this->authHeaders($this->owner))
            ->getJson('/api/v1/payment-settings?branch_id=' . $this->branch->id)
            ->assertOk();
        $this->assertTrue((bool) $ownerRes->json('data.is_owner_or_admin'));

        // Verify cached entry itself has NO is_owner_or_admin key
        $cachedPayload = Cache::get("branch_{$this->branch->id}_payment_settings");
        $this->assertIsArray($cachedPayload);
        $this->assertArrayNotHasKey('is_owner_or_admin', $cachedPayload);

        // Staff requests settings -> must get is_owner_or_admin = false even on cache hit
        $staffRes = $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/payment-settings?branch_id=' . $this->branch->id)
            ->assertOk();
        $this->assertFalse((bool) $staffRes->json('data.is_owner_or_admin'));
    }

    public function test_current_shift_exposes_shift_enforcement_enabled_even_when_shift_is_null(): void
    {
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/cashier/shift/current?branch_id=' . $this->branch->id)
            ->assertOk()
            ->assertJsonPath('data.has_active_shift', false)
            ->assertJsonPath('data.shift_enforcement_enabled', true)
            ->assertJsonPath('data.shift', null);

        $this->business->update(['shift_enforcement_enabled' => false]);

        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/cashier/shift/current?branch_id=' . $this->branch->id)
            ->assertOk()
            ->assertJsonPath('data.has_active_shift', false)
            ->assertJsonPath('data.shift_enforcement_enabled', false)
            ->assertJsonPath('data.shift', null);
    }
}
