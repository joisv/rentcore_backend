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
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RentalAvailabilityOverlapTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Business $business;
    protected Affiliate $branch;
    protected AssetCategory $category;
    protected AssetModel $iphoneXrModel;
    protected Asset $unit1;
    protected Customer $customer;
    protected PaymentMethod $cashMethod;
    protected CashDrawerShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Kasir Test',
            'email' => 'kasir.overlap@test.com',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Rental Overlap Test',
            'slug' => 'rental-overlap-test',
            'owner_id' => $this->owner->id,
            'shift_enforcement_enabled' => false,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'OVL',
            'slug' => 'cabang-overlap',
            'name' => 'Cabang Overlap',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->owner->id,
            'shift_number' => 'SHF-OVL-001',
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
            'opening_cash_float' => 100000,
        ]);

        $this->category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones-overlap',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->iphoneXrModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'name' => 'iPhone XR Putih',
            'slug' => 'iphone-xr-putih',
            'is_addon' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->iphoneXrModel->id,
            'duration_unit' => 'hour',
            'duration_value' => 12,
            'duration_hours' => 12,
            'price' => 100000,
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->iphoneXrModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 150000,
            'is_active' => true,
        ]);

        $this->unit1 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->iphoneXrModel->id,
            'asset_code' => 'XR-001',
            'serial_number' => 'SN-XR-001',
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

    protected function seedTomorrowBooking(?int $pinnedUnitId = null): Booking
    {
        // Existing booking: 2026-11-22 09:00 WIB -> 2026-11-23 09:00 WIB
        $startAt = Carbon::create(2026, 11, 22, 9, 0, 0, 'Asia/Jakarta')->utc();
        $endAt = Carbon::create(2026, 11, 23, 9, 0, 0, 'Asia/Jakarta')->utc();

        return Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'BK-20261122-001',
            'asset_model_id' => $this->iphoneXrModel->id,
            'asset_unit_id' => $pinnedUnitId,
            'customer_id' => $this->customer->id,
            'created_by' => $this->owner->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'booked',
            'subtotal_amount' => 150000,
            'primary_subtotal' => 150000,
            'addon_subtotal' => 0,
            'total_bill' => 150000,
            'remaining_balance' => 150000,
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_single_unit_with_tomorrow_floating_booking_rejects_overlapping_walkin_and_allows_non_overlapping(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 19, 0, 0, 'Asia/Jakarta'));

        try {
            $this->seedTomorrowBooking(null); // Floating booking: 22 Nov 09:00 -> 23 Nov 09:00

            $headers = $this->authHeaders();
            $walkInStart = Carbon::create(2026, 11, 21, 19, 0, 0, 'Asia/Jakarta')->utc();
            $walkInEnd24h = Carbon::create(2026, 11, 22, 19, 0, 0, 'Asia/Jakarta')->utc();

            // 1. Unit selection endpoint MUST flag unit as unavailable for [21 Nov 19:00, 22 Nov 19:00)
            $listRes = $this->withHeaders($headers)
                ->getJson('/api/v1/assets?' . http_build_query([
                    'status' => 'ready',
                    'branch_id' => $this->branch->id,
                    'start_at' => $walkInStart->toIso8601String(),
                    'end_at' => $walkInEnd24h->toIso8601String(),
                ]));

            $listRes->assertStatus(200)
                ->assertJsonPath('data.0.id', $this->unit1->id)
                ->assertJsonPath('data.0.is_available_for_window', false);
            $this->assertStringContainsString(
                'Stok iPhone XR Putih tidak mencukupi',
                (string) $listRes->json('data.0.conflict_reason')
            );

            // 2. Walk-in 24 hours from 2026-11-21 19:00 -> 2026-11-22 19:00 MUST fail with 422
            $overlapRes = $this->withHeaders($headers)->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branch->id,
                'shift_id' => $this->shift->id,
                'booking_mode' => 'direct',
                'is_scheduled' => false,
                'customer_name' => 'Penyewa Walkin',
                'customer_phone' => '081999888777',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'hour',
                'duration_value' => 24,
                'duration_hours' => 24,
                'paid_amount' => 150000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 150000,
            ]);

            $overlapRes->assertStatus(422)
                ->assertJsonValidationErrors(['asset_ids']);
            $this->assertStringContainsString(
                'Stok iPhone XR Putih tidak mencukupi',
                (string) $overlapRes->json('errors.asset_ids.0')
            );
            $this->assertStringContainsString(
                '22 Nov 09:00 - 23 Nov 09:00',
                (string) $overlapRes->json('errors.asset_ids.0')
            );

            // 3. Walk-in ending BEFORE 09:00 (2026-11-21 19:00 -> 2026-11-22 07:00 = 12h tier) MUST succeed
            $okRes = $this->withHeaders($headers)->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branch->id,
                'shift_id' => $this->shift->id,
                'booking_mode' => 'direct',
                'is_scheduled' => false,
                'customer_name' => 'Penyewa Singkat',
                'customer_phone' => '081999888778',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'hour',
                'duration_value' => 12,
                'duration_hours' => 12,
                'paid_amount' => 100000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 100000,
            ]);

            $okRes->assertStatus(201)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.status', 'rented');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_single_unit_with_tomorrow_pinned_booking_rejects_overlapping_walkin(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 19, 0, 0, 'Asia/Jakarta'));

        try {
            $this->seedTomorrowBooking($this->unit1->id);

            $res = $this->withHeaders($this->authHeaders())->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branch->id,
                'shift_id' => $this->shift->id,
                'booking_mode' => 'direct',
                'is_scheduled' => false,
                'customer_name' => 'Penyewa Walkin Pinned',
                'customer_phone' => '081777666555',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'duration_hours' => 24,
                'paid_amount' => 150000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 150000,
            ]);

            $res->assertStatus(422)
                ->assertJsonValidationErrors(['asset_ids']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_multi_unit_model_allows_first_walkin_and_rejects_second_when_one_unit_booked_tomorrow(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 19, 0, 0, 'Asia/Jakarta'));

        try {
            $unit2 = Asset::create([
                'business_id' => $this->business->id,
                'affiliate_id' => $this->branch->id,
                'model_id' => $this->iphoneXrModel->id,
                'asset_code' => 'XR-002',
                'serial_number' => 'SN-XR-002',
                'status' => 'ready',
            ]);

            // 1 of 2 units is booked tomorrow 22 Nov 09:00 -> 23 Nov 09:00
            $this->seedTomorrowBooking(null);

            $headers = $this->authHeaders();

            // First walk-in 24h for 1 unit consumes the 1 unbooked capacity slot -> 201
            $firstWalkIn = $this->withHeaders($headers)->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branch->id,
                'shift_id' => $this->shift->id,
                'booking_mode' => 'direct',
                'is_scheduled' => false,
                'customer_name' => 'Penyewa Pertama',
                'customer_phone' => '081111222333',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'duration_hours' => 24,
                'paid_amount' => 150000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 150000,
            ]);
            $firstWalkIn->assertStatus(201);

            // Second walk-in 24h for remaining unit2 would leave 0 units for tomorrow's 09:00 booking -> 422
            $secondWalkIn = $this->withHeaders($headers)->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branch->id,
                'shift_id' => $this->shift->id,
                'booking_mode' => 'direct',
                'is_scheduled' => false,
                'customer_name' => 'Penyewa Kedua',
                'customer_phone' => '081111222444',
                'asset_ids' => [$unit2->id],
                'duration_unit' => 'day',
                'duration_value' => 1,
                'duration_hours' => 24,
                'paid_amount' => 150000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 150000,
            ]);
            $secondWalkIn->assertStatus(422)
                ->assertJsonValidationErrors(['asset_ids']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_overdue_unreturned_rental_blocks_subsequent_booking_until_returned(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 19, 0, 0, 'Asia/Jakarta'));

        try {
            // Active rental already passed due_at (17:00), still unreturned at 19:00
            // Simulate unit status accidentally flipped to ready OR rented while booking remains 'rented'
            $overdueBooking = Booking::withoutGlobalScopes()->create([
                'business_id' => $this->business->id,
                'affiliate_id' => $this->branch->id,
                'booking_code' => 'RC-OVERDUE-001',
                'asset_model_id' => $this->iphoneXrModel->id,
                'asset_unit_id' => $this->unit1->id,
                'customer_id' => $this->customer->id,
                'created_by' => $this->owner->id,
                'start_at' => Carbon::create(2026, 11, 20, 17, 0, 0, 'Asia/Jakarta')->utc(),
                'end_at' => Carbon::create(2026, 11, 21, 17, 0, 0, 'Asia/Jakarta')->utc(),
                'duration_hours' => 24,
                'duration_unit' => 'day',
                'duration_value' => 1,
                'status' => 'rented',
                'subtotal_amount' => 150000,
                'primary_subtotal' => 150000,
                'addon_subtotal' => 0,
                'total_bill' => 150000,
                'remaining_balance' => 0,
                'payment_status' => 'paid',
            ]);

            $blockedRes = $this->withHeaders($this->authHeaders())->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branch->id,
                'shift_id' => $this->shift->id,
                'booking_mode' => 'direct',
                'is_scheduled' => false,
                'customer_name' => 'Penyewa Terhalang Overdue',
                'customer_phone' => '081555666777',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'hour',
                'duration_value' => 12,
                'duration_hours' => 12,
                'paid_amount' => 100000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 100000,
            ]);

            $blockedRes->assertStatus(422)
                ->assertJsonValidationErrors(['asset_ids']);

            // Once returned, a new walk-in succeeds cleanly
            $overdueBooking->update(['status' => 'returned']);
            $allowedRes = $this->withHeaders($this->authHeaders())->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branch->id,
                'shift_id' => $this->shift->id,
                'booking_mode' => 'direct',
                'is_scheduled' => false,
                'customer_name' => 'Penyewa Setelah Kembali',
                'customer_phone' => '081555666778',
                'asset_ids' => [$this->unit1->id],
                'duration_unit' => 'hour',
                'duration_value' => 12,
                'duration_hours' => 12,
                'paid_amount' => 100000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 100000,
            ]);
            $allowedRes->assertStatus(201);
        } finally {
            Carbon::setTestNow();
        }
    }
}
