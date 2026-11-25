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
use App\Services\LateFeeCalculatorService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LateFeeCalculatorEngineTest extends TestCase
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
            'name' => 'Owner SewaKasir',
            'email' => 'owner.latefee@test.com',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);
        $this->cashier = User::create([
            'name' => 'Kasir SewaKasir',
            'email' => 'cashier.latefee@test.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        // Use defaults (grace_minutes=15, grace_mode='forfeit', rounding='hourly_ceil', cap_daily=true, allow_waiver=true)
        $this->business = Business::create([
            'name' => 'SewaKasir Studio',
            'slug' => 'sewakasir-studio-' . uniqid(),
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
            'default_hourly_late_fee' => 15000.00,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'name' => 'Cabang Utama',
            'slug' => 'cabang-utama-' . uniqid(),
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
            'slug' => 'kamera-' . uniqid(),
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
            'name' => 'Dewi Lestari',
            'phone' => '081234567890',
        ]);

        CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_number' => 'SHF-LF-' . uniqid(),
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'opening_cash' => 250000.00,
            'expected_cash' => 250000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        app(TenantContext::class)->set($this->business, $ownerMember, $this->branch);
    }

    private function createBookingWithUnit(?float $modelRate, float $dailyPrice = 200000.0, ?Carbon $dueAt = null): Booking
    {
        $due = ($dueAt ?? Carbon::parse('2026-11-25 10:00:00', 'UTC'))->copy();

        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'name' => 'A7 IV ' . uniqid(),
            'slug' => 'a7-iv-' . uniqid(),
            'has_serial_number' => true,
            'is_active' => true,
            'hourly_late_fee' => $modelRate,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $model->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => $dailyPrice,
            'extension_hourly_rate' => $modelRate ?? 15000.0,
            'is_active' => true,
        ]);

        $unit = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => 'CAM-' . strtoupper(substr(uniqid(), -4)),
            'serial_number' => 'SN-' . uniqid(),
            'status' => 'rented',
            'condition_grade' => 'A',
        ]);

        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'SK-LF-' . strtoupper(substr(uniqid(), -5)),
            'asset_model_id' => $model->id,
            'asset_unit_id' => $unit->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->cashier->id,
            'start_at' => $due->copy()->subDay(),
            'end_at' => $due,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'rented',
            'subtotal_amount' => $dailyPrice,
            'total_bill' => $dailyPrice,
            'payment_status' => 'paid',
        ]);

        $booking->items()->create([
            'asset_id' => $unit->id,
            'asset_model_id' => $model->id,
            'item_type' => 'primary',
            'unit_price' => $dailyPrice,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'active',
        ]);

        return $booking->fresh(['business', 'items.asset.model']);
    }

    public function test_step1_and_step2_within_grace_returns_zero_and_forfeit_vs_deductive_grace_modes(): void
    {
        $calc = app(LateFeeCalculatorService::class);
        $dueAt = Carbon::parse('2026-11-25 10:00:00', 'UTC');
        $booking = $this->createBookingWithUnit(20000.0, 200000.0, $dueAt);

        // Within grace (15m <= 15m) => is_late=false, calculated_fee=0
        $withinGrace = $calc->calculate($booking, $dueAt->copy()->addMinutes(15));
        $this->assertFalse($withinGrace['is_late']);
        $this->assertEquals(15, $withinGrace['raw_overdue_minutes']);
        $this->assertEquals(0, $withinGrace['billable_minutes']);
        $this->assertEquals(0, $withinGrace['billed_units']);
        $this->assertEquals(0.0, $withinGrace['calculated_fee']);
        $this->assertEquals('model', $withinGrace['rate_source']);

        // Default grace_mode = 'forfeit': 70m overdue (> 15m grace) -> full 70m billed -> ceil(70/60) = 2 jam * 20,000 = 40,000
        $forfeit = $calc->calculate($booking, $dueAt->copy()->addMinutes(70));
        $this->assertTrue($forfeit['is_late']);
        $this->assertEquals('forfeit', $forfeit['grace_mode']);
        $this->assertEquals(70, $forfeit['raw_overdue_minutes']);
        $this->assertEquals(70, $forfeit['billable_minutes']);
        $this->assertEquals(2, $forfeit['billed_units']);
        $this->assertEquals('jam', $forfeit['unit_label']);
        $this->assertEquals(40000.0, $forfeit['calculated_fee']);

        // Switch grace_mode = 'deductive': 70m - 15m = 55m -> ceil(55/60) = 1 jam * 20,000 = 20,000
        $this->business->update(['late_fee_grace_mode' => 'deductive']);
        $deductive = $calc->calculate($booking->fresh(['business', 'items.asset.model']), $dueAt->copy()->addMinutes(70));
        $this->assertTrue($deductive['is_late']);
        $this->assertEquals('deductive', $deductive['grace_mode']);
        $this->assertEquals(55, $deductive['billable_minutes']);
        $this->assertEquals(1, $deductive['billed_units']);
        $this->assertEquals(20000.0, $deductive['calculated_fee']);
    }

    public function test_step3_rounding_modes_hourly_half_hourly_and_exact_minute(): void
    {
        $calc = app(LateFeeCalculatorService::class);
        $dueAt = Carbon::parse('2026-11-25 10:00:00', 'UTC');
        $booking = $this->createBookingWithUnit(20000.0, 200000.0, $dueAt);

        // 45 minutes overdue under forfeit:
        // 1) hourly_ceil -> ceil(45/60) = 1 jam -> 20,000
        $this->business->update(['late_fee_rounding_mode' => 'hourly_ceil', 'late_fee_grace_mode' => 'forfeit']);
        $hourly = $calc->calculate($booking->fresh(['business', 'items.asset.model']), $dueAt->copy()->addMinutes(45));
        $this->assertEquals(1, $hourly['billed_units']);
        $this->assertEquals('jam', $hourly['unit_label']);
        $this->assertEquals(20000.0, $hourly['calculated_fee']);

        // 2) half_hourly_ceil -> ceil(45/30) = 2 blocks of 30m (1.0h) -> 20,000; 75m -> 3 blocks (1.5h) -> 30,000
        $this->business->update(['late_fee_rounding_mode' => 'half_hourly_ceil']);
        $halfHourly = $calc->calculate($booking->fresh(['business', 'items.asset.model']), $dueAt->copy()->addMinutes(75));
        $this->assertEquals(3, $halfHourly['billed_units']);
        $this->assertEquals('30 menit', $halfHourly['unit_label']);
        $this->assertEquals(30000.0, $halfHourly['calculated_fee']);

        // 3) exact_minute -> 45m -> (45/60) * 20,000 = 15,000
        $this->business->update(['late_fee_rounding_mode' => 'exact_minute']);
        $exact = $calc->calculate($booking->fresh(['business', 'items.asset.model']), $dueAt->copy()->addMinutes(45));
        $this->assertEquals(45, $exact['billed_units']);
        $this->assertEquals('menit', $exact['unit_label']);
        $this->assertEquals(15000.0, $exact['calculated_fee']);
    }

    public function test_step4_daily_cap_and_model_override_vs_store_percent_daily_fallback(): void
    {
        $calc = app(LateFeeCalculatorService::class);
        $dueAt = Carbon::parse('2026-11-25 10:00:00', 'UTC');

        // Store default = 10% of daily price (200,000 * 10% = 20,000/jam)
        $this->business->update([
            'default_hourly_late_fee' => 10,
            'late_fee_default_type' => 'percent_daily',
            'cap_late_fee_at_daily_rate' => true,
        ]);

        // Model without override -> falls back to store percent_daily (20,000/jam)
        $fallbackBooking = $this->createBookingWithUnit(null, 200000.0, $dueAt);
        $resFallback = $calc->calculate($fallbackBooking, $dueAt->copy()->addHours(2));
        $this->assertEquals(20000.0, $resFallback['applied_rate']);
        $this->assertEquals('business_percent_daily', $resFallback['rate_source']);
        $this->assertEquals(40000.0, $resFallback['calculated_fee']);
        $this->assertFalse($resFallback['cap_applied']);

        // Model with explicit override (60,000/jam) over 5 hours = 300,000 -> capped at daily rate 200,000
        $overrideBooking = $this->createBookingWithUnit(60000.0, 200000.0, $dueAt);
        $resCapped = $calc->calculate($overrideBooking, $dueAt->copy()->addHours(5));
        $this->assertEquals(60000.0, $resCapped['applied_rate']);
        $this->assertEquals('model', $resCapped['rate_source']);
        $this->assertTrue($resCapped['cap_applied']);
        $this->assertEquals(200000.0, $resCapped['calculated_fee']);
    }

    public function test_cashier_waiver_role_guard_403_mandatory_reason_422_and_owner_override_with_audit(): void
    {
        $dueAt = Carbon::parse('2026-11-25 10:00:00', 'UTC');
        $booking = $this->createBookingWithUnit(25000.0, 250000.0, $dueAt);
        $returnTime = $dueAt->copy()->addHours(2); // 2h * 25,000 = 50,000

        // Disable cashier waiver permission
        $this->business->update(['allow_cashier_late_fee_waiver' => false]);

        $cashierToken = $this->cashier->createToken('test')->plainTextToken;
        $ownerToken = $this->owner->createToken('test')->plainTextToken;

        // 1. Preview endpoint returns deterministic breakdown
        $this->withHeader('Authorization', "Bearer {$cashierToken}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->getJson("/api/v1/bookings/{$booking->id}/late-fee-preview?returned_at=" . urlencode($returnTime->toIso8601String()))
            ->assertOk()
            ->assertJsonPath('data.is_late', true)
            ->assertJsonPath('data.billed_units', 2)
            ->assertJsonPath('data.unit_label', 'jam')
            ->assertJsonPath('data.calculated_fee', 50000)
            ->assertJsonPath('data.allow_cashier_late_fee_waiver', false);

        // 2. Cashier trying to waive when allow_cashier_late_fee_waiver == false -> 403 Forbidden
        $this->withHeader('Authorization', "Bearer {$cashierToken}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'returned_at' => $returnTime->toIso8601String(),
                'condition_grade' => 'A',
                'checklist_results' => ['unit_operational' => true],
                'final_late_fee' => 0,
                'late_fee_waiver_reason' => 'Macet / Hujan',
            ])
            ->assertStatus(403);

        // 3. Owner waiving without reason -> 422 Validation Error on late_fee_waiver_reason
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'returned_at' => $returnTime->toIso8601String(),
                'condition_grade' => 'A',
                'checklist_results' => ['unit_operational' => true],
                'final_late_fee' => 10000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['late_fee_waiver_reason']);

        // 4. Owner waiving with reason -> 200 OK, persists calculated/final/reason + AuditLog
        $res = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", [
                'returned_at' => $returnTime->toIso8601String(),
                'condition_grade' => 'A',
                'checklist_results' => ['unit_operational' => true],
                'final_late_fee' => 10000,
                'late_fee_waiver_reason' => 'Kebijakan Toko - Pelanggan loyal',
                'penalty_payment_method_id' => $this->cashMethod->id,
                'cash_received' => 10000,
            ]);

        $res->assertOk()
            ->assertJsonPath('data.calculated_late_fee', '50000.00')
            ->assertJsonPath('data.waived_late_fee', '40000.00')
            ->assertJsonPath('data.final_late_fee', '10000.00')
            ->assertJsonPath('data.late_fee_waiver_reason', 'Kebijakan Toko - Pelanggan loyal');

        $audit = AuditLog::where('action', 'booking.returned')
            ->where('entity_id', (string) $res->json('data.id'))
            ->first();
        $this->assertNotNull($audit);
        $this->assertEquals(50000.0, (float) $audit->new_values['calculated_late_fee']);
        $this->assertEquals(10000.0, (float) $audit->new_values['final_late_fee']);
        $this->assertEquals('Kebijakan Toko - Pelanggan loyal', $audit->new_values['late_fee_waiver_reason']);
    }

    public function test_business_settings_update_persists_all_canonical_late_fee_policy_fields(): void
    {
        $ownerToken = $this->owner->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->putJson("/api/v1/businesses/{$this->business->id}", [
                'late_fee_grace_minutes' => 10,
                'late_fee_grace_mode' => 'deductive',
                'late_fee_rounding' => 'exact_minute',
                'late_fee_cap_daily' => false,
                'late_fee_default_rate' => 12.5,
                'late_fee_default_type' => 'percent_daily',
                'late_fee_addon_policy' => 'per_item',
                'allow_cashier_late_fee_waiver' => false,
            ])
            ->assertOk();

        $this->business->refresh();
        $policy = $this->business->getLateFeePolicy();
        $this->assertEquals(10, $policy['late_fee_grace_minutes']);
        $this->assertEquals('deductive', $policy['late_fee_grace_mode']);
        $this->assertEquals('exact_minute', $policy['late_fee_rounding']);
        $this->assertFalse($policy['late_fee_cap_daily']);
        $this->assertEquals(12.5, $policy['late_fee_default_rate']);
        $this->assertEquals('percent_daily', $policy['late_fee_default_type']);
        $this->assertEquals('per_item', $policy['late_fee_addon_policy']);
        $this->assertFalse($policy['allow_cashier_late_fee_waiver']);
    }

    public function test_model_create_and_update_with_tiers_omitting_per_tier_late_fee_uses_model_or_store_fallback(): void
    {
        $ownerToken = $this->owner->createToken('test')->plainTextToken;

        // 1. Create product with tiers omitting any per-tier late_fee / extension_hourly_rate
        $createRes = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->postJson('/api/v1/products', [
                'category_id' => $this->category->id,
                'brand' => 'Sony',
                'name' => 'FX3 Cinema',
                'late_fee_per_hour' => 30000,
                'rates' => [
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 1,
                        'price' => 350000,
                    ],
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 3,
                        'price' => 950000,
                    ],
                ],
            ]);

        $createRes->assertStatus(201);
        $modelId = (int) $createRes->json('data.id');
        $model = AssetModel::with('pricingTiers')->findOrFail($modelId);
        $this->assertEquals(30000.0, (float) $model->late_fee_per_hour);
        $this->assertCount(2, $model->pricingTiers);
        $this->assertEquals(0.0, (float) $model->pricingTiers->first()->extension_hourly_rate);

        // Even if a legacy tier row has a non-zero extension_hourly_rate, LateFeeCalculatorService ignores it
        $tier = $model->pricingTiers->first();
        $tier->update(['extension_hourly_rate' => 99000.0]);

        $calculator = app(LateFeeCalculatorService::class);
        $resolvedModelRate = $calculator->resolveEffectiveHourlyLateFeeWithSource(null, $model, $this->business, $tier);
        $this->assertEquals(30000.0, $resolvedModelRate['rate']);
        $this->assertEquals('model', $resolvedModelRate['source']);

        // 2. Update product clearing model late_fee_per_hour and omitting per-tier late_fee -> 200 OK & store fallback
        $updateRes = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->withHeader('X-Branch-Id', (string) $this->branch->id)
            ->putJson("/api/v1/products/{$modelId}", [
                'late_fee_per_hour' => null,
                'rates' => [
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 1,
                        'price' => 360000,
                    ],
                ],
            ]);

        $updateRes->assertOk();
        $model->refresh();
        $this->assertNull($model->late_fee_per_hour);

        $resolvedStoreFallback = $calculator->resolveEffectiveHourlyLateFeeWithSource(null, $model, $this->business, $tier->fresh());
        $this->assertEquals(15000.0, $resolvedStoreFallback['rate']);
        $this->assertEquals('business_default', $resolvedStoreFallback['source']);
    }
}
