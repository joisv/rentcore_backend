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
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\TenantNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NoShowGracePeriodAndFloatingUnitTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected Business $business;
    protected Affiliate $branch;
    protected AssetCategory $category;
    protected AssetModel $model;
    protected Asset $unit1;
    protected Asset $unit2;
    protected PaymentMethod $cashMethod;
    protected Customer $customer;
    protected CashDrawerShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Booking Test',
            'email' => 'owner.noshow@test.com',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Floating Test',
            'slug' => 'rentcore-floating-test',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'JKT',
            'name' => 'Jakarta Pusat',
            'slug' => 'jakarta-pusat',
            'is_central' => true,
            'is_active' => true,
            'booking_grace_period_minutes' => 60,
            'booking_auto_noshow' => true,
            'allow_floating_unit_booking' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Kasir Cabang',
            'email' => 'kasir.noshow@test.com',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branch->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro 256GB',
            'slug' => 'iphone-15-pro-256gb',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 350000.00,
            'extension_hourly_rate' => 20000.00,
            'is_active' => true,
        ]);

        $this->unit1 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IP15P-001',
            'serial_number' => 'SN-1111',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 98,
        ]);

        $this->unit2 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IP15P-002',
            'serial_number' => 'SN-2222',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 96,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Andi Pratama',
            'phone' => '081234567899',
        ]);

        $this->shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->staff->id,
            'shift_number' => 'SHF-TEST-001',
            'opening_cash_float' => 200000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);
    }

    protected function authHeaders(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        return [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];
    }

    public function test_branch_booking_rules_can_be_read_and_updated_by_owner(): void
    {
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson("/api/v1/branches/{$this->branch->id}/booking-rules")
            ->assertStatus(200)
            ->assertJsonPath('data.booking_grace_period_minutes', 60)
            ->assertJsonPath('data.booking_auto_noshow', true)
            ->assertJsonPath('data.allow_floating_unit_booking', true);

        // Staff cannot update rules
        $this->withHeaders($this->authHeaders($this->staff))
            ->putJson("/api/v1/branches/{$this->branch->id}/booking-rules", [
                'booking_grace_period_minutes' => 30,
            ])
            ->assertStatus(403);

        // Owner can update rules
        $this->withHeaders($this->authHeaders($this->owner))
            ->putJson("/api/v1/branches/{$this->branch->id}/booking-rules", [
                'booking_grace_period_minutes' => 30,
                'booking_auto_noshow' => false,
                'allow_floating_unit_booking' => false,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.booking_grace_period_minutes', 30)
            ->assertJsonPath('data.booking_auto_noshow', false)
            ->assertJsonPath('data.allow_floating_unit_booking', false);
    }

    public function test_floating_unit_capacity_prevents_overbooking_and_supports_checkin_assignment(): void
    {
        $startAt = Carbon::now()->addHours(2)->startOfMinute();
        $endAt = $startAt->copy()->addHours(24);

        // 1 unit is already rented overlapping [startAt, endAt]
        $activeRental = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-ACTIVE-01',
            'start_at' => Carbon::now()->subHours(2),
            'end_at' => $startAt->copy()->addHours(6),
            'duration_hours' => 10,
            'status' => 'rented',
            'total_bill' => 200000.00,
            'payment_status' => 'paid',
        ]);
        BookingItem::create([
            'booking_id' => $activeRental->id,
            'asset_id' => $this->unit1->id,
            'asset_model_id' => $this->model->id,
            'item_type' => 'primary',
            'unit_price' => 200000.00,
            'status' => 'active',
        ]);
        $this->unit1->update(['status' => 'rented']);

        // Check availability -> 2 total units - 1 active = 1 available slot
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/assets/models?' . http_build_query([
                'start_at' => $startAt->toIso8601String(),
                'end_at' => $endAt->toIso8601String(),
                'branch_id' => $this->branch->id,
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.0.total_units', 2)
            ->assertJsonPath('data.0.booked_units', 1)
            ->assertJsonPath('data.0.available_slots', 1);

        // Create floating scheduled booking (no asset_ids, with DP 100k)
        $createRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'booking_mode' => 'scheduled',
                'customer_name' => 'Budi Santoso',
                'customer_phone' => '081999888777',
                'asset_model_id' => $this->model->id,
                'start_at' => $startAt->toIso8601String(),
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 100000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.asset_model_id', $this->model->id)
            ->assertJsonPath('data.asset_unit_id', null)
            ->assertJsonPath('data.is_floating_unit', true)
            ->assertJsonPath('data.payment_status', 'partial');

        $bookingId = $createRes->json('data.id');
        $createdBooking = Booking::findOrFail($bookingId);
        $this->assertEquals(
            $startAt->copy()->addMinutes(60)->timestamp,
            Carbon::parse($createdBooking->grace_expires_at)->timestamp
        );

        // Availability should now be 0
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/bookings/availability?' . http_build_query([
                'start_at' => $startAt->toIso8601String(),
                'end_at' => $endAt->toIso8601String(),
                'branch_id' => $this->branch->id,
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.0.available_slots', 0);

        // Second overlapping floating booking must be rejected
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'booking_mode' => 'scheduled',
                'customer_name' => 'Siti Aminah',
                'customer_phone' => '081777666555',
                'asset_model_id' => $this->model->id,
                'start_at' => $startAt->toIso8601String(),
                'duration_unit' => 'day',
                'duration_value' => 1,
            ])
            ->assertStatus(422);

        // Available physical units for check-in should list unit2 (ready, non-overlapping)
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson("/api/v1/bookings/{$bookingId}/available-units")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->unit2->id);

        // Check-in floating booking -> assigns unit2 and activates rental
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingId}/check-in", [
                'asset_unit_id' => $this->unit2->id,
                'collateral_storage_slot' => 'Brankas B, Slot 02',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rented')
            ->assertJsonPath('data.asset_unit_id', $this->unit2->id)
            ->assertJsonPath('data.collateral_storage_slot', 'Brankas B, Slot 02');

        $this->assertEquals('rented', $this->unit2->fresh()->status);
    }

    public function test_grace_warning_and_auto_noshow_release_slot_and_forfeit_dp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00'));

        $startAt = Carbon::parse('2026-11-18 09:10:00');
        // grace_expires_at = 10:10:00 (10 mins from now -> triggers 15-min grace warning!)
        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => null,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-FLOAT-WARN',
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHours(24),
            'grace_expires_at' => $startAt->copy()->addMinutes(60),
            'duration_hours' => 24,
            'status' => 'booked',
            'total_bill' => 350000.00,
            'payment_status' => 'partial',
        ]);

        \App\Models\PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $this->shift->id,
            'transaction_code' => 'TX-DP-001',
            'receipt_number' => 'REC-DP-001',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staff->id,
            'type' => 'dp',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 150000.00,
            'cash_received' => 150000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        // Run command at 10:00 -> triggers 15-min warning notification
        $this->artisan('bookings:process-no-show')->assertExitCode(0);

        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'type' => 'booking.grace_warning',
        ]);
        $warningCountBefore = TenantNotification::where('type', 'booking.grace_warning')->count();

        // Running again should be deduplicated
        $this->artisan('bookings:process-no-show')->assertExitCode(0);
        $this->assertEquals($warningCountBefore, TenantNotification::where('type', 'booking.grace_warning')->count());

        // Advance past grace_expires_at (10:15:00)
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:15:00'));
        $this->artisan('bookings:process-no-show')->assertExitCode(0);

        $booking->refresh();
        $this->assertEquals('no_show', $booking->status);
        $this->assertNotNull($booking->no_show_at);
        $this->assertEquals(150000.00, (float) $booking->forfeited_dp_amount);

        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'type' => 'booking.auto_noshow',
        ]);

        // Slot must be released back (2 units available)
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/bookings/availability?' . http_build_query([
                'start_at' => $startAt->toIso8601String(),
                'end_at' => $startAt->copy()->addHours(24)->toIso8601String(),
                'branch_id' => $this->branch->id,
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.0.available_slots', 2);

        Carbon::setTestNow();
    }

    public function test_manual_overdue_pickup_flag_extend_grace_and_manual_noshow(): void
    {
        $this->branch->update(['booking_auto_noshow' => false]);

        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => null,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-MANUAL-01',
            'start_at' => Carbon::parse('2026-11-18 10:00:00'),
            'end_at' => Carbon::parse('2026-11-19 10:00:00'),
            'grace_expires_at' => Carbon::parse('2026-11-18 11:00:00'),
            'duration_hours' => 24,
            'status' => 'booked',
            'total_bill' => 350000.00,
            'payment_status' => 'unpaid',
        ]);

        $this->artisan('bookings:process-no-show')->assertExitCode(0);

        $booking->refresh();
        $this->assertEquals('booked', $booking->status);
        $this->assertTrue($booking->is_overdue_pickup);

        // Cashier extends grace period by 45 mins
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$booking->id}/extend-grace", [
                'additional_minutes' => 45,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.is_overdue_pickup', false);

        // Cashier manually marks no_show
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$booking->id}/no-show", [
                'reason' => 'Pelanggan membatalkan via WA',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'no_show');

        Carbon::setTestNow();
    }

    public function test_immediate_rental_skips_future_date_validation_and_scheduled_uses_5_minute_buffer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 14:00:00'));

        // 1. Immediate walk-in rental with is_scheduled = false and null start_at succeeds
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => false,
                'booking_mode' => 'direct',
                'start_at' => null,
                'customer_name' => 'Walkin User',
                'customer_phone' => '081111222333',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'rented');

        // 2. Scheduled booking selected 3 minutes ago (within 5-minute cashier form buffer) succeeds
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'customer_name' => 'Buffered Booking User',
                'customer_phone' => '081111222444',
                'asset_model_id' => $this->model->id,
                'start_at' => now()->subMinutes(3)->toDateTimeString(),
                'duration_unit' => 'day',
                'duration_value' => 1,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'booked');

        // 3. Scheduled booking >5 minutes in the past fails with Indonesian message
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'customer_name' => 'Expired Schedule User',
                'customer_phone' => '081111222555',
                'asset_model_id' => $this->model->id,
                'start_at' => now()->subMinutes(10)->toDateTimeString(),
                'duration_unit' => 'day',
                'duration_value' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_at']);

        Carbon::setTestNow();
    }

    public function test_scheduled_dp_payment_for_floating_and_pinned_units_with_cash_and_qris_records_shift_ledger_and_remaining_balance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        $qrisMethod = PaymentMethod::create([
            'name' => 'QRIS',
            'code' => 'QRIS',
            'is_active' => true,
        ]);

        // 1. Floating scheduled booking with Cash DP (100k of 350k)
        $floatingRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'status' => 'booked',
                'customer_name' => 'DP Floating Cash',
                'customer_phone' => '081211112222',
                'asset_model_id' => $this->model->id,
                'start_at' => Carbon::parse('2026-11-19 10:00:00')->toIso8601String(),
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 100000,
                'dp_amount' => 100000,
                'cash_received' => 150000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.asset_unit_id', null)
            ->assertJsonPath('data.payment_status', 'partial');

        $this->assertEquals(100000.0, (float) $floatingRes->json('data.paid_amount'));
        $this->assertEquals(250000.0, (float) $floatingRes->json('data.remaining_balance'));

        // True Blind Close: current_cash_inflow is redacted while shift is open
        $shiftRes = $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/cashier/shift/current?branch_id=' . $this->branch->id)
            ->assertStatus(200)
            ->assertJsonPath('data.shift.is_blind_close', true)
            ->assertJsonMissingPath('data.shift.current_cash_inflow')
            ->assertJsonMissingPath('data.shift.expected_cash_now');

        // 2. Pinned scheduled booking with 2-day duration multiplier (server authoritative 2 * 350k = 700k) + QRIS DP (200k of 700k)
        $pinnedRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'status' => 'booked',
                'customer_name' => 'DP Pinned QRIS',
                'customer_phone' => '081233334444',
                'asset_ids' => [$this->unit2->id],
                'asset_model_id' => $this->model->id,
                'start_at' => Carbon::parse('2026-11-21 10:00:00')->toIso8601String(),
                'duration_unit' => 'day',
                'duration_value' => 2,
                'duration_multiplier' => 2,
                'payment_method_id' => $qrisMethod->id,
                'paid_amount' => 200000,
                'dp_amount' => 200000,
                'cash_received' => 200000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.asset_unit_id', $this->unit2->id)
            ->assertJsonPath('data.payment_status', 'partial');

        $this->assertEquals(700000.0, (float) $pinnedRes->json('data.total_bill'));
        $this->assertEquals(200000.0, (float) $pinnedRes->json('data.paid_amount'));
        $this->assertEquals(500000.0, (float) $pinnedRes->json('data.remaining_balance'));

        // Unit remains 'ready' (not 'rented') until check-in handover
        $this->assertEquals('ready', $this->unit2->fresh()->status);

        // Cashier summary includes both DP transactions with Uang Muka (DP) label
        $summaryRes = $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/cashier/closing-summary?branch_id=' . $this->branch->id)
            ->assertStatus(200);
        $txs = collect($summaryRes->json('data.recent_transactions'));
        $this->assertCount(2, $txs->where('type', 'dp'));
        $this->assertEquals('Uang Muka (DP)', $txs->firstWhere('type', 'dp')['type_label']);

        Carbon::setTestNow();
    }

    public function test_atomic_handover_settlement_records_remaining_payment_and_activates_floating_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        // Create floating booking with 100k DP out of 350k
        $createRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'status' => 'booked',
                'customer_name' => 'Handover Settle User',
                'customer_phone' => '081255556666',
                'asset_model_id' => $this->model->id,
                'start_at' => Carbon::parse('2026-11-18 13:00:00')->toIso8601String(),
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 100000,
                'cash_received' => 100000,
            ])
            ->assertStatus(201);

        $bookingId = $createRes->json('data.id');

        // Atomic settlement + handover in one call
        $settleRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $bookingId,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'settlement',
                'amount' => 250000,
                'cash_received' => 300000,
                'is_handover_settlement' => true,
                'asset_unit_id' => $this->unit2->id,
                'collateral_type' => 'KTP Asli',
                'collateral_storage_slot' => 'Brankas C, Slot 09',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.handover_completed', true)
            ->assertJsonPath('data.booking.status', 'rented')
            ->assertJsonPath('data.booking.payment_status', 'paid')
            ->assertJsonPath('data.booking.asset_unit_id', $this->unit2->id)
            ->assertJsonPath('data.booking.collateral_storage_slot', 'Brankas C, Slot 09');

        $this->assertEquals(0.0, (float) $settleRes->json('data.booking.remaining_balance'));
        $booking = Booking::findOrFail($bookingId);
        $this->assertNotNull($booking->picked_up_at);
        $this->assertTrue($booking->collateral_secured);
        $this->assertEquals('rented', $this->unit2->fresh()->status);

        // Shift cash inflow remains redacted while open, and reveals 350k total upon closing shift
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/cashier/shift/current?branch_id=' . $this->branch->id)
            ->assertStatus(200)
            ->assertJsonMissingPath('data.shift.current_cash_inflow');

        $closeRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/shift/close', [
                'actual_cash_counted' => 550000,
            ])
            ->assertOk();
        $this->assertEquals(350000.0, (float) $closeRes->json('data.cash_inflow'));

        Carbon::setTestNow();
    }

    public function test_reschedule_advances_future_booking_and_recalculates_keep_end_pricing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        // Book Nov 20 -> Nov 21 (24h @ 350,000, paid 100k DP)
        $createRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'status' => 'booked',
                'customer_name' => 'Reschedule User',
                'customer_phone' => '081277778888',
                'asset_model_id' => $this->model->id,
                'start_at' => '2026-11-20 18:00:00',
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 100000,
                'cash_received' => 100000,
            ])
            ->assertStatus(201);

        $bookingId = $createRes->json('data.id');

        // 1. Standard shift: Nov 20 18:00 -> Nov 18 18:00 (keeps 24h duration -> Nov 19 18:00)
        $this->withHeaders($this->authHeaders($this->staff))
            ->patchJson("/api/v1/bookings/{$bookingId}/reschedule", [
                'new_start_at' => '2026-11-18 18:00:00',
                'keep_original_end_at' => false,
            ])
            ->assertStatus(200);

        $booking = Booking::findOrFail($bookingId);
        $this->assertEquals('2026-11-18 18:00:00', $booking->start_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-19 18:00:00', $booking->end_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-18 19:00:00', $booking->grace_expires_at->format('Y-m-d H:i:s'));
        $this->assertEquals(24, $booking->duration_hours);

        // 2. Reschedule overlapping own window (Nov 18 20:00, excluding self) succeeds cleanly
        $this->withHeaders($this->authHeaders($this->staff))
            ->patchJson("/api/v1/bookings/{$bookingId}/reschedule", [
                'new_start_at' => '2026-11-18 20:00:00',
                'keep_original_end_at' => false,
            ])
            ->assertStatus(200);

        // 3. Move to Nov 18 18:00 while extending back to Nov 21 18:00 (set original end to Nov 21 first)
        $booking->update(['start_at' => '2026-11-20 18:00:00', 'end_at' => '2026-11-21 18:00:00', 'duration_hours' => 24]);
        $this->withHeaders($this->authHeaders($this->staff))
            ->patchJson("/api/v1/bookings/{$bookingId}/reschedule", [
                'new_start_at' => '2026-11-18 18:00:00',
                'keep_original_end_at' => true,
            ])
            ->assertStatus(200);

        $booking->refresh();
        $this->assertEquals('2026-11-18 18:00:00', $booking->start_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-21 18:00:00', $booking->end_at->format('Y-m-d H:i:s'));
        $this->assertEquals(72, $booking->duration_hours);
        $this->assertGreaterThan(350000.0, (float) $booking->total_bill);
        $this->assertEquals((float) $booking->total_bill - 100000.0, (float) $booking->remaining_balance);

        Carbon::setTestNow();
    }

    public function test_reschedule_and_early_pickup_block_when_earlier_date_is_fully_booked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        // Occupy both units on Nov 18 16:00 -> Nov 19 16:00
        Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-FULL-1',
            'start_at' => Carbon::parse('2026-11-18 12:00:00'),
            'end_at' => Carbon::parse('2026-11-19 16:00:00'),
            'grace_expires_at' => Carbon::parse('2026-11-18 13:00:00'),
            'duration_hours' => 28,
            'status' => 'booked',
            'total_bill' => 350000,
            'payment_status' => 'unpaid',
        ]);
        Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit2->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-FULL-2',
            'start_at' => Carbon::parse('2026-11-18 12:00:00'),
            'end_at' => Carbon::parse('2026-11-19 16:00:00'),
            'grace_expires_at' => Carbon::parse('2026-11-18 13:00:00'),
            'duration_hours' => 28,
            'status' => 'booked',
            'total_bill' => 350000,
            'payment_status' => 'unpaid',
        ]);

        // Booking C scheduled safely on Nov 20
        $bookingC = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => null,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-NOV20-C',
            'start_at' => Carbon::parse('2026-11-20 12:00:00'),
            'end_at' => Carbon::parse('2026-11-21 12:00:00'),
            'grace_expires_at' => Carbon::parse('2026-11-20 13:00:00'),
            'duration_hours' => 24,
            'status' => 'booked',
            'total_bill' => 350000,
            'remaining_balance' => 0,
            'payment_status' => 'paid',
        ]);

        // 1. Attempting to reschedule Nov 20 booking to Nov 18 fails with HTTP 422
        $this->withHeaders($this->authHeaders($this->staff))
            ->patchJson("/api/v1/bookings/{$bookingC->id}/reschedule", [
                'new_start_at' => '2026-11-18 14:00:00',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Unit/slot model penuh pada jadwal baru tersebut.']);

        // 2. Attempting early pickup check-in on Nov 18 also fails cleanly with HTTP 422
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingC->id}/check-in", [
                'start_now' => true,
                'keep_original_end_at' => false,
                'collateral_storage_slot' => 'Brankas A',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Unit/slot model penuh pada jadwal baru tersebut.']);

        $this->assertEquals('booked', $bookingC->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_early_pickup_checkin_and_atomic_handover_settlement_shift_dates_and_activate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        // Booking 1: fully paid Nov 20 floating booking -> direct early pickup (shift duration)
        $paidCreate = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'status' => 'booked',
                'customer_name' => 'Early Direct User',
                'customer_phone' => '081299990001',
                'asset_model_id' => $this->model->id,
                'start_at' => '2026-11-20 12:00:00',
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 350000,
                'cash_received' => 350000,
            ])
            ->assertStatus(201);

        $paidId = $paidCreate->json('data.id');

        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$paidId}/check-in", [
                'asset_unit_id' => $this->unit1->id,
                'collateral_storage_slot' => 'Brankas D, Slot 01',
                'start_now' => true,
                'keep_original_end_at' => false,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rented')
            ->assertJsonPath('data.asset_unit_id', $this->unit1->id);

        $paidBooking = Booking::findOrFail($paidId);
        $this->assertEquals('2026-11-18 12:00:00', $paidBooking->start_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-11-19 12:00:00', $paidBooking->end_at->format('Y-m-d H:i:s'));

        // Booking 2: DP-paid Nov 19 booking -> atomic early settlement keeping original Nov 20 end
        $dpCreate = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'status' => 'booked',
                'customer_name' => 'Early Settle User',
                'customer_phone' => '081299990002',
                'asset_model_id' => $this->model->id,
                'start_at' => '2026-11-19 12:00:00',
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'paid_amount' => 100000,
                'cash_received' => 100000,
            ])
            ->assertStatus(201);

        $dpId = $dpCreate->json('data.id');

        // Shifted to 48h (18 Nov 12:00 -> 20 Nov 12:00): price = 350k + 24 * 15k = 710k -> remaining 610k
        $settleRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $dpId,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'settlement',
                'cash_received' => 1000000,
                'is_handover_settlement' => true,
                'start_now' => true,
                'keep_original_end_at' => true,
                'asset_unit_id' => $this->unit2->id,
                'collateral_storage_slot' => 'Brankas D, Slot 02',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.handover_completed', true)
            ->assertJsonPath('data.booking.status', 'rented')
            ->assertJsonPath('data.booking.payment_status', 'paid')
            ->assertJsonPath('data.booking.duration_hours', 48);

        $this->assertEquals(0.0, (float) $settleRes->json('data.booking.remaining_balance'));

        Carbon::setTestNow();
    }

    public function test_future_window_availability_exposes_available_asset_ids_and_schedule_overlap_on_assets_index(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        // Unit 1 is currently rented until tomorrow (Nov 19), while Unit 2 has a booking on Nov 25-26
        $this->unit1->update(['status' => 'rented']);
        Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-NOW-RENTED',
            'start_at' => Carbon::parse('2026-11-18 10:00:00'),
            'end_at' => Carbon::parse('2026-11-19 10:00:00'),
            'duration_hours' => 24,
            'status' => 'rented',
            'total_bill' => 350000,
            'payment_status' => 'paid',
        ]);

        Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit2->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-FUT-25',
            'start_at' => Carbon::parse('2026-11-25 10:00:00'),
            'end_at' => Carbon::parse('2026-11-26 10:00:00'),
            'duration_hours' => 24,
            'status' => 'booked',
            'total_bill' => 350000,
            'payment_status' => 'unpaid',
        ]);

        // For [Nov 25, Nov 26], Unit 1 is free and Unit 2 is busy
        $availRes = $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/bookings/availability?start_at=2026-11-25T10:00:00&end_at=2026-11-26T10:00:00&branch_id=' . $this->branch->id)
            ->assertStatus(200);
        $modelRow = collect($availRes->json('data'))->firstWhere('id', $this->model->id);
        $this->assertEquals(1, $modelRow['available_slots']);
        $this->assertEquals([$this->unit1->id], $modelRow['available_asset_ids']);

        $assetsRes = $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/assets?start_at=2026-11-25T10:00:00&end_at=2026-11-26T10:00:00&branch_id=' . $this->branch->id)
            ->assertStatus(200);
        $u1 = collect($assetsRes->json('data'))->firstWhere('id', $this->unit1->id);
        $u2 = collect($assetsRes->json('data'))->firstWhere('id', $this->unit2->id);
        $this->assertTrue($u1['is_available_for_schedule']);
        $this->assertFalse($u2['is_available_for_schedule']);

        Carbon::setTestNow();
    }

    public function test_scheduled_booking_with_wib_offset_and_addons_serializes_utc_and_exposes_addons_in_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        // Create an add-on category/model & two physical add-on units
        $addonCat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Power & Cable',
            'slug' => 'power-cable',
            'is_addon' => true,
        ]);
        $addonModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $addonCat->id,
            'name' => 'Power Bank Anker',
            'slug' => 'power-bank-anker',
            'brand' => 'Anker',
            'is_active' => true,
            'is_addon' => true,
        ]);
        PricingTier::create([
            'model_id' => $addonModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 25000,
            'extension_hourly_rate' => 5000,
            'is_active' => true,
        ]);
        $addon1 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $addonModel->id,
            'asset_code' => 'PB-01',
            'serial_number' => 'SN-PB-01',
            'condition_grade' => 'A',
            'status' => 'ready',
        ]);
        $addon2 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $addonModel->id,
            'asset_code' => 'PB-02',
            'serial_number' => 'SN-PB-02',
            'condition_grade' => 'A',
            'status' => 'ready',
        ]);

        // Submit 23:00 WIB as UTC 16:00:00Z with 2 Power Bank add-ons
        $createRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => true,
                'booking_mode' => 'scheduled',
                'status' => 'booked',
                'customer_name' => 'WIB Addon User',
                'customer_phone' => '081200001122',
                'asset_model_id' => $this->model->id,
                'addon_asset_ids' => [$addon1->id, $addon2->id],
                'start_at' => '2026-11-18T16:00:00.000Z',
                'duration_unit' => 'day',
                'duration_value' => 1,
            ])
            ->assertStatus(201);

        $bookingId = $createRes->json('data.id');

        $queueRes = $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/bookings?branch_id=' . $this->branch->id)
            ->assertStatus(200);

        $queueItem = collect($queueRes->json('data.data') ?? $queueRes->json('data'))
            ->firstWhere('id', $bookingId);

        $this->assertNotNull($queueItem);
        // Stored and serialized as exact UTC (16:00 UTC == 23:00 WIB) — no +7h shift
        $this->assertEquals('2026-11-18T16:00:00.000000Z', $queueItem['start_at']);
        $this->assertEquals('2026-11-19T16:00:00.000000Z', $queueItem['end_at']);
        $this->assertNotEmpty($queueItem['addons']);
        $this->assertEquals('Power Bank Anker', $queueItem['addons'][0]['name']);
        $this->assertEquals(2, $queueItem['addons'][0]['quantity']);

        Carbon::setTestNow();
    }

    public function test_return_endpoint_enforces_addon_completeness_and_marks_addon_items_returned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));

        $addonCat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Aksesoris Sewa',
            'slug' => 'aksesoris-sewa',
            'is_addon' => true,
        ]);
        $addonModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $addonCat->id,
            'name' => 'Charger 20W',
            'slug' => 'charger-20w',
            'brand' => 'Apple',
            'is_active' => true,
            'is_addon' => true,
        ]);
        PricingTier::create([
            'model_id' => $addonModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 20000,
            'extension_hourly_rate' => 5000,
            'is_active' => true,
        ]);
        $addonA = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $addonModel->id,
            'asset_code' => 'CHG-20W-01',
            'serial_number' => 'SN-CHG-01',
            'condition_grade' => 'A',
            'status' => 'ready',
        ]);
        $addonB = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $addonModel->id,
            'asset_code' => 'CHG-20W-02',
            'serial_number' => 'SN-CHG-02',
            'condition_grade' => 'A',
            'status' => 'ready',
        ]);

        $rentRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => false,
                'booking_mode' => 'direct',
                'customer_name' => 'Return Addon User',
                'customer_phone' => '081244445555',
                'asset_ids' => [$this->unit1->id],
                'addon_asset_ids' => [$addonA->id, $addonB->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 390000,
            ])
            ->assertStatus(201);

        $bookingId = $rentRes->json('data.id');
        $addonItemIds = BookingItem::where('booking_id', $bookingId)
            ->where('item_type', 'addon')
            ->pluck('id')
            ->all();
        $this->assertCount(2, $addonItemIds);

        // 1. Omitting addons_returned fails with 422 Indonesian validation error
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingId}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Pastikan seluruh add-on yang disewa telah dikembalikan dengan lengkap.']);

        // 2. Partial returned_addon_ids fails with 422
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingId}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'addons_returned' => true,
                'returned_addon_ids' => [$addonItemIds[0]],
            ])
            ->assertStatus(422);

        $this->assertEquals('rented', Booking::findOrFail($bookingId)->status);

        // 3. Complete confirmation succeeds, marks booking & add-ons returned, restores assets
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingId}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
                'addons_returned' => true,
                'returned_addon_ids' => $addonItemIds,
            ])
            ->assertStatus(200);

        $this->assertEquals('returned', Booking::findOrFail($bookingId)->status);
        $this->assertEquals('ready', $addonA->fresh()->status);
        $this->assertEquals('ready', $addonB->fresh()->status);
        foreach ($addonItemIds as $itemId) {
            $item = BookingItem::findOrFail($itemId);
            $this->assertTrue((bool) $item->is_returned);
            $this->assertNotNull($item->returned_at);
        }

        // 4. Add-on-free rental returns normally without addons_returned
        $plainRes = $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => false,
                'booking_mode' => 'direct',
                'customer_name' => 'No Addon Return User',
                'customer_phone' => '081244446666',
                'asset_ids' => [$this->unit2->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ])
            ->assertStatus(201);

        $plainId = $plainRes->json('data.id');
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$plainId}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['icloud_removed' => true],
            ])
            ->assertStatus(200);

        Carbon::setTestNow();
    }
}
