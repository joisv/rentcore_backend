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
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\TenantNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LateBookingPickupPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected Business $business;
    protected Affiliate $branch;
    protected AssetCategory $category;
    protected AssetModel $model;
    protected Asset $unit1;
    protected PaymentMethod $cashMethod;
    protected Customer $customer;
    protected CashDrawerShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Policy Test',
            'email' => 'owner.policy@test.com',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'SewaKasir Policy Test',
            'slug' => 'rentcore-policy-test',
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'booking_grace_period_minutes' => 60,
            'auto_expire_unpaid_booking' => true,
            'auto_expire_paid_booking' => false,
            'late_pickup_end_time_mode' => 'keep_original_end',
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'PST',
            'name' => 'Cabang Pusat',
            'slug' => 'cabang-pusat-policy',
            'is_central' => true,
            'is_active' => true,
            'booking_grace_period_minutes' => 60,
            'booking_auto_noshow' => true,
            'allow_floating_unit_booking' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Kasir Policy',
            'email' => 'kasir.policy@test.com',
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
            'slug' => 'smartphones-policy',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro-policy',
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
            'asset_code' => 'IP15P-POL-01',
            'serial_number' => 'SN-POL-01',
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 98,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Siti Rahma',
            'phone' => '081234560001',
        ]);

        $this->shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->staff->id,
            'shift_number' => 'SHF-POL-001',
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

    public function test_tenant_late_pickup_policy_can_be_viewed_and_updated_by_owner_only(): void
    {
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson("/api/v1/businesses/{$this->business->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.booking_grace_period_minutes', 60)
            ->assertJsonPath('data.auto_expire_unpaid_booking', true)
            ->assertJsonPath('data.auto_expire_paid_booking', false)
            ->assertJsonPath('data.late_pickup_end_time_mode', 'keep_original_end');

        // Staff cannot update business policy
        $this->withHeaders($this->authHeaders($this->staff))
            ->putJson("/api/v1/businesses/{$this->business->id}", [
                'booking_grace_period_minutes' => 30,
            ])
            ->assertStatus(403);

        // Owner updates all 4 policy fields
        $this->withHeaders($this->authHeaders($this->owner))
            ->putJson("/api/v1/businesses/{$this->business->id}", [
                'booking_grace_period_minutes' => 30,
                'auto_expire_unpaid_booking' => false,
                'auto_expire_paid_booking' => true,
                'late_pickup_end_time_mode' => 'shift_if_available',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.booking_grace_period_minutes', 30)
            ->assertJsonPath('data.auto_expire_unpaid_booking', false)
            ->assertJsonPath('data.auto_expire_paid_booking', true)
            ->assertJsonPath('data.late_pickup_end_time_mode', 'shift_if_available');
    }

    public function test_unpaid_booking_auto_expires_to_noshow_while_dp_paid_retains_capacity_as_late_pickup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00'));

        $unit2 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IP15P-POL-02',
            'serial_number' => 'SN-POL-02',
            'status' => 'ready',
            'condition_grade' => 'A',
        ]);

        // Unpaid booking started at 08:30 (past 60m grace at 09:30, before end_at)
        $unpaidBooking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-UNPAID-01',
            'start_at' => Carbon::parse('2026-11-22 08:30:00'),
            'end_at' => Carbon::parse('2026-11-23 08:30:00'),
            'grace_expires_at' => Carbon::parse('2026-11-22 09:30:00'),
            'duration_hours' => 24,
            'status' => 'booked',
            'total_bill' => 350000.00,
            'remaining_balance' => 350000.00,
            'payment_status' => 'unpaid',
        ]);
        $this->unit1->update(['status' => 'reserved']);

        // DP-paid booking started at 08:30 (past 60m grace at 09:30, before end_at)
        $paidBooking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $unit2->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-DP-01',
            'start_at' => Carbon::parse('2026-11-22 08:30:00'),
            'end_at' => Carbon::parse('2026-11-23 08:30:00'),
            'grace_expires_at' => Carbon::parse('2026-11-22 09:30:00'),
            'duration_hours' => 24,
            'status' => 'booked',
            'total_bill' => 350000.00,
            'remaining_balance' => 250000.00,
            'payment_status' => 'partial',
        ]);
        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $this->shift->id,
            'transaction_code' => 'TX-DP-POL-1',
            'receipt_number' => 'REC-DP-POL-1',
            'booking_id' => $paidBooking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staff->id,
            'type' => 'dp',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 100000.00,
            'cash_received' => 100000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        // Run scheduler
        $this->artisan('bookings:process-late-pickups')->assertExitCode(0);

        // Unpaid transitions to no_show & unit1 is released to ready
        $unpaidBooking->refresh();
        $this->assertEquals('no_show', $unpaidBooking->status);
        $this->assertEquals('ready', $this->unit1->fresh()->status);

        // Paid transitions to late_pickup & sends branch notification
        $paidBooking->refresh();
        $this->assertEquals('late_pickup', $paidBooking->status);
        $this->assertTrue($paidBooking->is_late_pickup);

        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'type' => 'booking.late_pickup',
            'body' => 'Booking #RC-DP-01 a.n. Siti Rahma melewati batas waktu pengambilan',
        ]);

        $notifCountBefore = TenantNotification::where('type', 'booking.late_pickup')->count();

        // Idempotent re-run does not duplicate notification
        $this->artisan('bookings:process-late-pickups')->assertExitCode(0);
        $this->assertEquals(
            $notifCountBefore,
            TenantNotification::where('type', 'booking.late_pickup')->count()
        );

        // Capacity check: 2 total units - 1 late_pickup (paidBooking) = 1 available slot (unpaidBooking freed unit1!)
        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/bookings/availability?' . http_build_query([
                'start_at' => Carbon::parse('2026-11-22 10:00:00')->toIso8601String(),
                'end_at' => Carbon::parse('2026-11-23 10:00:00')->toIso8601String(),
                'branch_id' => $this->branch->id,
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.0.total_units', 2)
            ->assertJsonPath('data.0.booked_units', 1)
            ->assertJsonPath('data.0.available_slots', 1);

        // Advance past end_at -> late_pickup expires automatically and frees remaining slot
        Carbon::setTestNow(Carbon::parse('2026-11-23 09:00:00'));
        $this->artisan('bookings:process-late-pickups')->assertExitCode(0);

        $paidBooking->refresh();
        $this->assertEquals('expired', $paidBooking->status);

        $this->withHeaders($this->authHeaders($this->staff))
            ->getJson('/api/v1/bookings/availability?' . http_build_query([
                'start_at' => Carbon::parse('2026-11-23 09:00:00')->toIso8601String(),
                'end_at' => Carbon::parse('2026-11-24 09:00:00')->toIso8601String(),
                'branch_id' => $this->branch->id,
            ]))
            ->assertStatus(200)
            ->assertJsonPath('data.0.available_slots', 2);

        Carbon::setTestNow();
    }

    public function test_checkin_respects_keep_original_end_vs_shift_if_available_and_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00'));

        // Mode 1: keep_original_end -> keeps 10:00 -> next day 10:00
        $bookingKeep = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-KEEP-01',
            'start_at' => Carbon::parse('2026-11-22 10:00:00'),
            'end_at' => Carbon::parse('2026-11-23 10:00:00'),
            'grace_expires_at' => Carbon::parse('2026-11-22 11:00:00'),
            'duration_hours' => 24,
            'status' => 'late_pickup',
            'is_late_pickup' => true,
            'total_bill' => 350000.00,
            'payment_status' => 'paid',
        ]);

        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingKeep->id}/check-in", [
                'asset_unit_id' => $this->unit1->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('end_time_mode_applied', 'kept_original')
            ->assertJsonPath('data.status', 'rented');

        $bookingKeep->refresh();
        $this->assertEquals('2026-11-23 10:00:00', $bookingKeep->end_at->format('Y-m-d H:i:s'));

        // Return unit1 so we can test Mode 2
        $bookingKeep->refresh()->update(['status' => 'returned']);
        $this->unit1->refresh()->update(['status' => 'ready']);

        // Mode 2a: shift_if_available with NO downstream conflict -> shifts end_at to 2026-11-23 12:00:00
        $this->business->update(['late_pickup_end_time_mode' => 'shift_if_available']);

        $bookingShiftOk = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-SHIFT-OK',
            'start_at' => Carbon::parse('2026-11-22 10:00:00'),
            'end_at' => Carbon::parse('2026-11-23 10:00:00'),
            'grace_expires_at' => Carbon::parse('2026-11-22 11:00:00'),
            'duration_hours' => 24,
            'status' => 'late_pickup',
            'is_late_pickup' => true,
            'total_bill' => 350000.00,
            'payment_status' => 'paid',
        ]);

        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingShiftOk->id}/check-in", [
                'asset_unit_id' => $this->unit1->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('end_time_mode_applied', 'shifted')
            ->assertJsonPath('data.status', 'rented');

        $bookingShiftOk->refresh();
        $this->assertEquals('2026-11-23 12:00:00', $bookingShiftOk->end_at->format('Y-m-d H:i:s'));

        // Return unit1 and add a downstream booking at 2026-11-23 10:30:00 -> Mode 2b must fall back safely!
        $bookingShiftOk->refresh()->update(['status' => 'returned']);
        $this->unit1->refresh()->update(['status' => 'ready']);

        Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-NEXT-BOOK',
            'start_at' => Carbon::parse('2026-11-23 10:30:00'),
            'end_at' => Carbon::parse('2026-11-24 10:30:00'),
            'duration_hours' => 24,
            'status' => 'booked',
            'total_bill' => 350000.00,
            'payment_status' => 'paid',
        ]);

        $bookingFallback = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-SHIFT-FB',
            'start_at' => Carbon::parse('2026-11-22 10:00:00'),
            'end_at' => Carbon::parse('2026-11-23 10:00:00'),
            'grace_expires_at' => Carbon::parse('2026-11-22 11:00:00'),
            'duration_hours' => 24,
            'status' => 'late_pickup',
            'is_late_pickup' => true,
            'total_bill' => 350000.00,
            'payment_status' => 'paid',
        ]);

        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$bookingFallback->id}/check-in", [
                'asset_unit_id' => $this->unit1->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('end_time_mode_applied', 'kept_original_fallback')
            ->assertJsonPath('data.status', 'rented');

        $bookingFallback->refresh();
        $this->assertEquals('2026-11-23 10:00:00', $bookingFallback->end_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_extend_pickup_and_noshow_release_capacity_for_walkin_rental(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:30:00'));

        $lateBooking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'asset_model_id' => $this->model->id,
            'asset_unit_id' => $this->unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-EXT-01',
            'start_at' => Carbon::parse('2026-11-22 10:00:00'),
            'end_at' => Carbon::parse('2026-11-23 10:00:00'),
            'grace_expires_at' => Carbon::parse('2026-11-22 11:00:00'),
            'duration_hours' => 24,
            'status' => 'late_pickup',
            'is_late_pickup' => true,
            'total_bill' => 350000.00,
            'payment_status' => 'partial',
        ]);
        $this->unit1->update(['status' => 'reserved']);

        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $this->shift->id,
            'transaction_code' => 'TX-DP-EXT-1',
            'receipt_number' => 'REC-DP-EXT-1',
            'booking_id' => $lateBooking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->staff->id,
            'type' => 'dp',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 100000.00,
            'cash_received' => 100000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        // Extend pickup by +30m -> sets grace_extended_until = 12:00:00 and clears late_pickup
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$lateBooking->id}/extend-pickup", [
                'additional_minutes' => 30,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.is_late_pickup', false);

        $lateBooking->refresh();
        $this->assertEquals('2026-11-22 12:00:00', Carbon::parse($lateBooking->grace_extended_until)->format('Y-m-d H:i:s'));

        // Walk-in rental on unit1 must currently be blocked while booking is active
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => false,
                'booking_mode' => 'direct',
                'customer_name' => 'Walkin Blocked',
                'customer_phone' => '081200009999',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ])
            ->assertStatus(422);

        // Explicitly mark No-Show with dp_disposition = 'refunded'
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson("/api/v1/bookings/{$lateBooking->id}/no-show", [
                'dp_disposition' => 'refunded',
                'reason' => 'Pelanggan batal datang',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'no_show')
            ->assertJsonPath('data.dp_disposition', 'refunded');

        $lateBooking->refresh();
        $this->assertEquals(0.0, (float) $lateBooking->forfeited_dp_amount);
        $this->assertEquals('ready', $this->unit1->fresh()->status);

        // Walk-in rental on unit1 now succeeds immediately!
        $this->withHeaders($this->authHeaders($this->staff))
            ->postJson('/api/v1/bookings', [
                'is_scheduled' => false,
                'booking_mode' => 'direct',
                'customer_name' => 'Walkin Success',
                'customer_phone' => '081200008888',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 350000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'rented');

        Carbon::setTestNow();
    }
}
