<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\User;
use App\Services\BookingService;
use App\Services\LateFeeCalculatorService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CascadingLateFeePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private Affiliate $branch;
    private User $owner;
    private User $cashier;
    private AssetCategory $category;
    private PaymentMethod $cashMethod;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Penalty Test',
            'email' => 'owner.penalty@test.com',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);
        $this->cashier = User::create([
            'name' => 'Cashier Penalty Test',
            'email' => 'cashier.penalty@test.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Penalty SaaS',
            'slug' => 'rentcore-penalty-saas-' . uniqid(),
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
            'return_grace_period_minutes' => 15,
            'late_fee_rounding_mode' => 'hourly_ceil',
            'cap_late_fee_at_daily_rate' => true,
            'default_hourly_late_fee' => 10000.00,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'name' => 'Cabang Pusat',
            'slug' => 'cabang-pusat-' . uniqid(),
            'code' => 'PST',
            'city' => 'Jakarta',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->owner->update(['affiliate_id' => $this->branch->id]);
        $this->cashier->update(['affiliate_id' => $this->branch->id]);

        $ownerMember = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $cashierMember = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->cashier->id,
            'role' => 'staff',
            'status' => 'active',
        ]);
        $cashierMember->branchMembers()->create(['affiliate_id' => $this->branch->id]);

        $this->category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Kamera & Lensa',
            'slug' => 'kamera-lensa-' . uniqid(),
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Tunai',
            'code' => 'CASH',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Rizky Pratama',
            'phone' => '081234567890',
        ]);

        CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_number' => 'SHF-PENALTY-' . uniqid(),
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'opening_cash' => 200000.00,
            'expected_cash' => 200000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        app(TenantContext::class)->set($this->business, $ownerMember, $this->branch);
    }

    private function createModelWithDailyTier(?float $modelHourlyLateFee, float $dailyPrice = 200000.0): AssetModel
    {
        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'name' => 'Alpha A7 IV',
            'slug' => 'sony-a7-iv-' . uniqid(),
            'has_serial_number' => true,
            'is_active' => true,
            'hourly_late_fee' => $modelHourlyLateFee,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $model->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => $dailyPrice,
            'extension_hourly_rate' => $modelHourlyLateFee ?? 10000.0,
            'is_active' => true,
        ]);

        return $model;
    }

    private function createUnit(AssetModel $model, string $code, ?float $unitHourlyLateFee = null): Asset
    {
        return Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => $code,
            'serial_number' => 'SN-' . $code,
            'status' => 'ready',
            'condition_grade' => 'A',
            'hourly_late_fee' => $unitHourlyLateFee,
        ]);
    }

    public function test_cascading_hourly_rate_resolution_and_optional_unit_override(): void
    {
        $calculator = app(LateFeeCalculatorService::class);

        // 1. Model null + Unit null -> falls back to Business default (10,000)
        $modelInherited = $this->createModelWithDailyTier(null);
        $unitInherited = $this->createUnit($modelInherited, 'CAM-001', null);
        $this->assertEquals(10000.0, $calculator->resolveEffectiveHourlyLateFee($unitInherited, $modelInherited, $this->business));

        // 2. Model rate 25,000 + Unit null -> inherits Model rate (25,000)
        $modelWithRate = $this->createModelWithDailyTier(25000.0);
        $unitFromModel = $this->createUnit($modelWithRate, 'CAM-002', null);
        $this->assertEquals(25000.0, $calculator->resolveEffectiveHourlyLateFee($unitFromModel, $modelWithRate, $this->business));

        // 3. Unit override 45,000 -> overrides Model & Business
        $unitOverridden = $this->createUnit($modelWithRate, 'CAM-003', 45000.0);
        $this->assertEquals(45000.0, $calculator->resolveEffectiveHourlyLateFee($unitOverridden, $modelWithRate, $this->business));

        // 4. Clearing unit override via API reverts back to Model default
        $token = $this->owner->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->putJson("/api/v1/assets/{$unitOverridden->id}", [
                'hourly_late_fee' => null,
            ])
            ->assertStatus(200);

        $unitOverridden->refresh();
        $this->assertNull($unitOverridden->hourly_late_fee);
        $this->assertEquals(25000.0, $unitOverridden->resolveEffectiveHourlyLateFee($this->business));
    }

    public function test_grace_period_immunity_and_rounding_modes(): void
    {
        $calculator = app(LateFeeCalculatorService::class);
        $model = $this->createModelWithDailyTier(25000.0, 300000.0);
        $unit = $this->createUnit($model, 'CAM-GRACE');

        $dueAt = Carbon::parse('2026-11-22 10:00:00', 'UTC');
        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'RC-GRACE-100',
            'asset_model_id' => $model->id,
            'asset_unit_id' => $unit->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'start_at' => $dueAt->copy()->subDay(),
            'end_at' => $dueAt,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'rented',
            'subtotal_amount' => 300000.0,
            'total_bill' => 300000.0,
            'payment_status' => 'paid',
        ]);
        $booking->items()->create([
            'asset_id' => $unit->id,
            'asset_model_id' => $model->id,
            'item_type' => 'primary',
            'unit_price' => 300000.0,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'applied_hourly_late_fee' => 25000.0,
            'applied_grace_period_minutes' => 15,
            'applied_daily_rate_cap' => 300000.0,
            'applied_late_fee_rounding_mode' => 'hourly_ceil',
            'status' => 'active',
        ]);

        // Exactly 15 minutes overdue -> immune (0 fee)
        $resImmune = $calculator->calculate($booking->fresh(), $dueAt->copy()->addMinutes(15));
        $this->assertEquals(15, $resImmune['overdue_minutes']);
        $this->assertEquals(0.0, $resImmune['calculated_late_fee']);

        // 16 minutes overdue -> 1m chargeable -> ceil(1/60) = 1h -> 25,000 under hourly_ceil
        $resOneHour = $calculator->calculate($booking->fresh(), $dueAt->copy()->addMinutes(16));
        $this->assertEquals(1.0, $resOneHour['billable_hours']);
        $this->assertEquals(25000.0, $resOneHour['calculated_late_fee']);

        // 25 minutes overdue under half_hourly_ceil -> 10m chargeable -> 0.5h -> 12,500
        $booking->items()->update(['applied_late_fee_rounding_mode' => 'half_hourly_ceil']);
        $resHalfHour = $calculator->calculate($booking->fresh(), $dueAt->copy()->addMinutes(25));
        $this->assertEquals(0.5, $resHalfHour['billable_hours']);
        $this->assertEquals(12500.0, $resHalfHour['calculated_late_fee']);
    }

    public function test_daily_rate_cap_per_24h_cycle(): void
    {
        $calculator = app(LateFeeCalculatorService::class);
        $model = $this->createModelWithDailyTier(50000.0, 200000.0);
        $unit = $this->createUnit($model, 'CAM-CAP');

        $dueAt = Carbon::parse('2026-11-22 10:00:00', 'UTC');
        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'RC-CAP-200',
            'asset_model_id' => $model->id,
            'asset_unit_id' => $unit->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'start_at' => $dueAt->copy()->subDay(),
            'end_at' => $dueAt,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'rented',
            'subtotal_amount' => 200000.0,
            'total_bill' => 200000.0,
            'payment_status' => 'paid',
        ]);
        $item = $booking->items()->create([
            'asset_id' => $unit->id,
            'asset_model_id' => $model->id,
            'item_type' => 'primary',
            'unit_price' => 200000.0,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'applied_hourly_late_fee' => 50000.0,
            'applied_grace_period_minutes' => 15,
            'applied_daily_rate_cap' => 200000.0,
            'applied_late_fee_rounding_mode' => 'hourly_ceil',
            'status' => 'active',
        ]);

        // 6h 15m overdue -> 6 billable hours * 50,000 = 300,000 -> capped at 200,000
        $capped6h = $calculator->calculate($booking->fresh(), $dueAt->copy()->addHours(6)->addMinutes(15));
        $this->assertTrue($capped6h['was_capped']);
        $this->assertEquals(300000.0, $capped6h['raw_late_fee']);
        $this->assertEquals(200000.0, $capped6h['calculated_late_fee']);

        // 26h 15m overdue -> Cycle 1 capped at 200,000 + Cycle 2 (2h * 50,000 = 100,000) = 300,000
        $capped26h = $calculator->calculate($booking->fresh(), $dueAt->copy()->addHours(26)->addMinutes(15));
        $this->assertTrue($capped26h['was_capped']);
        $this->assertEquals(300000.0, $capped26h['calculated_late_fee']);

        // Disable cap -> full uncapped 6h * 50,000 = 300,000
        $item->update(['applied_daily_rate_cap' => null]);
        $uncapped6h = $calculator->calculate($booking->fresh(), $dueAt->copy()->addHours(6)->addMinutes(15));
        $this->assertFalse($uncapped6h['was_capped']);
        $this->assertEquals(300000.0, $uncapped6h['calculated_late_fee']);
    }

    public function test_mid_rental_catalog_edit_does_not_alter_snapshotted_contract_and_cashier_waiver_is_audited(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00', 'UTC'));

        $model = $this->createModelWithDailyTier(25000.0, 250000.0);
        $unit = $this->createUnit($model, 'CAM-SNAP');

        $bookingService = app(BookingService::class);
        $booking = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->phone,
            'asset_ids' => [$unit->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.0,
        ], $this->cashier);

        $item = $booking->items()->first();
        $this->assertEquals(25000.0, (float) $item->applied_hourly_late_fee);
        $this->assertEquals(15, (int) $item->applied_grace_period_minutes);
        $this->assertEquals(250000.0, (float) $item->applied_daily_rate_cap);

        // Owner doubles model late fee & changes business grace period mid-rental
        $model->update(['hourly_late_fee' => 80000.0]);
        $unit->update(['hourly_late_fee' => 95000.0]);
        $this->business->update(['return_grace_period_minutes' => 0]);

        // Advance time to 3h 15m past due (due was 2026-11-23 10:00 -> return at 13:15 -> 195m - 15m = 180m = 3h)
        // Calculated late fee must remain 3 * 25,000 = 75,000 (NOT 4 * 95,000)!
        $returnTime = Carbon::parse('2026-11-23 13:15:00', 'UTC');
        $token = $this->cashier->createToken('test')->plainTextToken;

        // 1. Attempting waiver without reason must fail 422
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'returned_at' => $returnTime->toIso8601String(),
                'condition_grade' => 'A',
                'checklist_results' => ['unit_operational' => true],
                'waived_late_fee' => 25000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['late_fee_waive_reason']);

        // 2. Submitting waiver with reason stores calculated, waived, final, and audit log
        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'returned_at' => $returnTime->toIso8601String(),
                'condition_grade' => 'A',
                'checklist_results' => ['unit_operational' => true],
                'waived_late_fee' => 25000,
                'late_fee_waive_reason' => 'Pelanggan terjebak macet dan sudah konfirmasi admin.',
                'penalty_payment_method_id' => $this->cashMethod->id,
                'cash_received' => 50000,
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.calculated_late_fee', '75000.00')
            ->assertJsonPath('data.waived_late_fee', '25000.00')
            ->assertJsonPath('data.final_late_fee', '50000.00')
            ->assertJsonPath('data.late_fee', '50000.00')
            ->assertJsonPath('data.late_fee_waive_reason', 'Pelanggan terjebak macet dan sudah konfirmasi admin.');

        $audit = AuditLog::where('action', 'booking.returned')
            ->where('entity_id', (string) $res->json('data.id'))
            ->first();
        $this->assertNotNull($audit);
        $this->assertEquals(75000, (float) $audit->new_values['calculated_late_fee']);
        $this->assertEquals(25000, (float) $audit->new_values['waived_late_fee']);
        $this->assertEquals(50000, (float) $audit->new_values['final_late_fee']);
        $this->assertEquals('Pelanggan terjebak macet dan sudah konfirmasi admin.', $audit->new_values['late_fee_waive_reason']);

        Carbon::setTestNow();
    }

    public function test_owner_can_update_tenant_late_fee_policy_settings(): void
    {
        $token = $this->owner->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/businesses/{$this->business->id}", [
                'return_grace_period_minutes' => 30,
                'late_fee_rounding_mode' => 'half_hourly_ceil',
                'cap_late_fee_at_daily_rate' => false,
                'default_hourly_late_fee' => 18000,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.return_grace_period_minutes', 30)
            ->assertJsonPath('data.late_fee_rounding_mode', 'half_hourly_ceil')
            ->assertJsonPath('data.cap_late_fee_at_daily_rate', false);

        $this->business->refresh();
        $this->assertEquals(30, $this->business->return_grace_period_minutes);
        $this->assertEquals('half_hourly_ceil', $this->business->late_fee_rounding_mode);
        $this->assertFalse($this->business->cap_late_fee_at_daily_rate);
        $this->assertEquals(18000.0, (float) $this->business->default_hourly_late_fee);
    }
}
