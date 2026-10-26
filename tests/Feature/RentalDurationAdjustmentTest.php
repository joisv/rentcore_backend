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
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\RentalExtension;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RentalDurationAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $owner;
    protected User $manager;
    protected User $staff;
    protected PaymentMethod $cashMethod;
    protected AssetModel $iphoneModel;
    protected AssetModel $tripodModel;
    protected Asset $iphoneAsset;
    protected Asset $tripodAsset;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai (Cash)',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Owner Bisnis',
            'email' => 'owner@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'owner',
            'is_active' => true,
            'manager_override_pin' => Hash::make('999999'),
        ]);

        $this->business = Business::create([
            'name' => 'Rental Unggul',
            'slug' => 'rental-unggul',
            'phone' => '081234567890',
            'owner_id' => $this->owner->id,
            'addons_enabled' => true,
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
            'code' => 'CBG-01',
            'slug' => 'cabang-01',
            'name' => 'Cabang Pusat',
            'city' => 'Jakarta',
            'address' => 'Jl. Pusat No. 1',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager Cabang',
            'email' => 'manager@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'admin',
            'affiliate_id' => $this->branch->id,
            'is_active' => true,
            'manager_override_pin' => Hash::make('123456'),
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->manager->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Staff Kasir',
            'email' => 'staff@rentcore.test',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'affiliate_id' => $this->branch->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        // Categories & Models
        $phoneCat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $addonCat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Aksesoris',
            'slug' => 'aksesoris',
            'is_addon' => true,
            'is_active' => true,
        ]);

        $this->iphoneModel = AssetModel::create([
            'category_id' => $phoneCat->id,
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro',
            'brand' => 'Apple',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->tripodModel = AssetModel::create([
            'category_id' => $addonCat->id,
            'name' => 'Tripod Mini Bluetooth',
            'slug' => 'tripod-mini',
            'brand' => 'Ulanzi',
            'is_addon' => true,
            'is_active' => true,
        ]);

        // Pricing Tiers: iPhone 15 Pro
        // 24h = 150.000, 48h = 280.000, 72h = 400.000, extension = 15.000/hr
        PricingTier::create([
            'model_id' => $this->iphoneModel->id,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'duration_hours' => 24,
            'price' => 150000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);
        PricingTier::create([
            'model_id' => $this->iphoneModel->id,
            'duration_unit' => 'hour',
            'duration_value' => 48,
            'duration_hours' => 48,
            'price' => 280000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);

        // Pricing Tiers: Tripod Mini Add-on
        // 24h = 30.000, 48h = 50.000, extension = 3.000/hr
        PricingTier::create([
            'model_id' => $this->tripodModel->id,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'duration_hours' => 24,
            'price' => 30000.00,
            'extension_hourly_rate' => 3000.00,
            'is_active' => true,
        ]);
        PricingTier::create([
            'model_id' => $this->tripodModel->id,
            'duration_unit' => 'hour',
            'duration_value' => 48,
            'duration_hours' => 48,
            'price' => 50000.00,
            'extension_hourly_rate' => 3000.00,
            'is_active' => true,
        ]);

        // Physical Assets
        $this->iphoneAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'AST-IP15-01',
            'serial_number' => 'SN-IP15-01',
            'status' => 'rented',
        ]);

        $this->tripodAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->tripodModel->id,
            'asset_code' => 'AST-TRP-01',
            'serial_number' => 'SN-TRP-01',
            'status' => 'rented',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Budi Pelanggan',
            'phone' => '08987654321',
            'email' => 'budi@test.com',
        ]);
    }

    protected function requestAs(User $user)
    {
        auth()->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string)$this->business->id,
            'X-Branch-Id' => (string)$this->branch->id,
        ]);
    }

    protected function createActiveBooking(int $durationHours = 48, float $amountPaid = 0.00): Booking
    {
        $startAt = Carbon::now()->subHours(2);
        $endAt = (clone $startAt)->addHours($durationHours);

        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'BK-' . uniqid(),
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_hours' => $durationHours,
            'duration_unit' => 'hour',
            'duration_value' => $durationHours,
            'status' => 'rented',
            'primary_subtotal' => 280000.00, // 48h iPhone tier
            'addon_subtotal' => 50000.00,    // 48h Tripod tier
            'subtotal_amount' => 330000.00,
            'total_bill' => 330000.00,
            'payment_status' => $amountPaid >= 330000.00 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'pending'),
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $this->iphoneAsset->id,
            'item_type' => 'primary',
            'unit_price' => 280000.00,
            'duration_unit' => 'hour',
            'duration_value' => $durationHours,
            'status' => 'active',
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $this->tripodAsset->id,
            'item_type' => 'addon',
            'unit_price' => 50000.00,
            'duration_unit' => 'hour',
            'duration_value' => $durationHours,
            'status' => 'active',
        ]);

        if ($amountPaid > 0) {
            PaymentTransaction::create([
                'business_id' => $this->business->id,
                'transaction_code' => 'TX-' . uniqid(),
                'receipt_number' => 'REC-' . uniqid(),
                'booking_id' => $booking->id,
                'payment_method_id' => $this->cashMethod->id,
                'processed_by' => $this->staff->id,
                'type' => 'settlement',
                'direction' => 'inflow',
                'amount' => $amountPaid,
                'cash_received' => $amountPaid,
                'cash_change' => 0.00,
                'transacted_at' => Carbon::now()->subHours(2),
            ]);
        }

        return $booking;
    }

    public function test_increase_primary_device_hours_works_correctly(): void
    {
        $booking = $this->createActiveBooking(24, 180000.00); // 24h initially
        $previousEndAt = Carbon::parse($booking->end_at);

        $response = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'direction' => 'increase',
            'hours_delta' => 24, // increase from 24h to 48h
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $booking->refresh();
        $this->assertEquals(48, $booking->duration_hours);
        $this->assertEquals(280000.00, (float)$booking->primary_subtotal);
        $this->assertTrue(Carbon::parse($booking->end_at)->equalTo($previousEndAt->copy()->addHours(24)));

        $this->assertDatabaseHas('rental_extensions', [
            'booking_id' => $booking->id,
            'adjustment_type' => 'increase',
            'hours_delta' => 24,
        ]);
    }

    public function test_reduce_primary_device_hours_without_refund(): void
    {
        // 48h booking with 0 paid
        $booking = $this->createActiveBooking(48, 0.00);
        $previousEndAt = Carbon::parse($booking->end_at);

        $response = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'direction' => 'decrease',
            'hours_delta' => 24, // reduce from 48h to 24h
            'reason' => 'Pelanggan ingin selesai lebih awal',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $booking->refresh();
        $this->assertEquals(24, $booking->duration_hours);
        $this->assertEquals(150000.00, (float)$booking->primary_subtotal); // 24h tier
        $this->assertEquals(30000.00, (float)$booking->addon_subtotal);    // 24h capped tripod tier
        $this->assertEquals(180000.00, (float)$booking->total_bill);
        $this->assertEquals('pending', $booking->payment_status);

        $this->assertDatabaseHas('rental_extensions', [
            'booking_id' => $booking->id,
            'adjustment_type' => 'decrease',
            'hours_delta' => -24,
            'reason' => 'Pelanggan ingin selesai lebih awal',
        ]);
    }

    public function test_reduce_primary_device_hours_caps_exceeding_addons(): void
    {
        // 48h booking with iPhone 48h (280k) + Tripod 48h (50k)
        $booking = $this->createActiveBooking(48, 0.00);

        $response = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'direction' => 'decrease',
            'hours_delta' => 24, // reduce to 24h
        ]);

        $response->assertStatus(200);

        $booking->refresh();
        $tripodItem = $booking->items->where('item_type', 'addon')->first();
        $this->assertEquals(24, $tripodItem->duration_value);
        $this->assertEquals(30000.00, (float)$tripodItem->unit_price);
        $this->assertEquals(30000.00, (float)$booking->addon_subtotal);
    }

    public function test_reduce_primary_device_hours_rejected_if_new_end_at_in_past(): void
    {
        // Booking started 10 hours ago for 24h (14 hours remaining)
        $startAt = Carbon::now()->subHours(10);
        $endAt = (clone $startAt)->addHours(24);

        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'BK-PAST-' . uniqid(),
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_hours' => 24,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'status' => 'rented',
            'primary_subtotal' => 150000.00,
            'total_bill' => 150000.00,
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $this->iphoneAsset->id,
            'item_type' => 'primary',
            'unit_price' => 150000.00,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'status' => 'active',
        ]);

        // Attempting to reduce by 16 hours would move end_at to start_at + 8h = 2 hours in the past!
        $response = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'direction' => 'decrease',
            'hours_delta' => 16,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hours_delta']);
    }

    public function test_reduce_hours_rejected_on_overdue_rental(): void
    {
        // End time is already 1 hour in the past (overdue)
        $startAt = Carbon::now()->subHours(25);
        $endAt = Carbon::now()->subHours(1);

        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'BK-OVERDUE-' . uniqid(),
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_hours' => 24,
            'status' => 'rented',
            'primary_subtotal' => 150000.00,
            'total_bill' => 150000.00,
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $this->iphoneAsset->id,
            'item_type' => 'primary',
            'unit_price' => 150000.00,
            'duration_unit' => 'hour',
            'duration_value' => 24,
            'status' => 'active',
        ]);

        $response = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'direction' => 'decrease',
            'hours_delta' => 6,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('overdue', strtolower($response->json('message')));
    }

    public function test_reduce_individual_addon_hours_only_affects_targeted_addon(): void
    {
        $booking = $this->createActiveBooking(48, 0.00);
        $initialPrimaryEndAt = Carbon::parse($booking->end_at);
        $tripodItem = $booking->items->where('item_type', 'addon')->first();

        // Reduce Tripod from 48h to 24h
        $response = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'booking_item_id' => $tripodItem->id,
            'direction' => 'decrease',
            'hours_delta' => 24,
        ]);

        $response->assertStatus(200);

        $booking->refresh();
        $tripodItem->refresh();

        // Addon updated
        $this->assertEquals(24, $tripodItem->duration_value);
        $this->assertEquals(30000.00, (float)$tripodItem->unit_price);
        $this->assertEquals(30000.00, (float)$booking->addon_subtotal);

        // Primary device completely untouched
        $this->assertEquals(48, $booking->duration_hours);
        $this->assertEquals(280000.00, (float)$booking->primary_subtotal);
        $this->assertTrue(Carbon::parse($booking->end_at)->equalTo($initialPrimaryEndAt));
    }

    public function test_increase_individual_addon_hours_with_payment(): void
    {
        $booking = $this->createActiveBooking(24, 210000.00); // 24h booking paid in full (180k primary + 30k addon)
        $initialPrimaryEndAt = Carbon::parse($booking->end_at);
        $tripodItem = $booking->items->where('item_type', 'addon')->first();

        // Increase Tripod from 24h by 24h to 48h (with additional payment 20.000)
        $response = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'booking_item_id' => $tripodItem->id,
            'direction' => 'increase',
            'hours_delta' => 24,
            'additional_fee' => 20000.00,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 50000.00,
            'notes' => 'Pembayaran perpanjangan tripod add-on',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $booking->refresh();
        $tripodItem->refresh();

        // Addon updated
        $this->assertEquals(48, $tripodItem->duration_value);
        $this->assertEquals(70000.00, (float)$tripodItem->unit_price);
        $this->assertEquals(70000.00, (float)$booking->addon_subtotal);

        // Booking end_at updated to cover the 48h add-on
        $this->assertEquals(48, $booking->duration_hours);
        $this->assertTrue(Carbon::parse($booking->end_at)->equalTo($initialPrimaryEndAt->copy()->addHours(24)));

        // PaymentTransaction properly created and recorded
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $booking->id,
            'type' => 'extension',
            'amount' => 20000.00,
            'cash_received' => 50000.00,
            'cash_change' => 30000.00,
            'branch_id' => $booking->affiliate_id,
            'status' => 'completed',
        ]);

        // Extension history recorded
        $this->assertDatabaseHas('rental_extensions', [
            'booking_id' => $booking->id,
            'booking_item_id' => $tripodItem->id,
            'adjustment_type' => 'increase',
            'hours_delta' => 24,
            'additional_fee' => 20000.00,
        ]);
    }

    public function test_duration_reduction_with_overpayment_requires_manager_pin(): void
    {
        // 48h booking paid in full (330.000 IDR)
        $booking = $this->createActiveBooking(48, 330000.00);

        // 1. Without Manager PIN -> Fails 422
        $resNoPin = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'direction' => 'decrease',
            'hours_delta' => 24, // new bill = 180.000, overpayment = 150.000
        ]);

        $resNoPin->assertStatus(422);
        $resNoPin->assertJsonValidationErrors(['manager_pin']);

        // 2. With Invalid Manager PIN -> Fails 422
        $resWrongPin = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'direction' => 'decrease',
            'hours_delta' => 24,
            'manager_pin' => '000000',
        ]);

        $resWrongPin->assertStatus(422);
        $resWrongPin->assertJsonValidationErrors(['manager_pin']);

        // 3. With Valid Manager PIN (123456) -> Succeeds and creates outflow refund
        $resValid = $this->requestAs($this->staff)->postJson("/api/v1/bookings/{$booking->id}/adjust-duration", [
            'direction' => 'decrease',
            'hours_delta' => 24,
            'manager_pin' => '123456',
            'reason' => 'Pengembalian selisih sewa',
        ]);

        $resValid->assertStatus(200);
        $resValid->assertJson(['success' => true]);

        // Verify refund PaymentTransaction created
        $this->assertDatabaseHas('payment_transactions', [
            'booking_id' => $booking->id,
            'type' => 'refund',
            'direction' => 'outflow',
            'amount' => 150000.00,
        ]);

        $booking->refresh();
        $this->assertEquals(180000.00, (float)$booking->total_bill);
        $this->assertEquals('paid', $booking->payment_status);
    }
}
