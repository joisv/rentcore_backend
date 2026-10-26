<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\TenantNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomatedOverdueAndNotificationTriggersTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected Business $business;
    protected Affiliate $branch;
    protected Customer $customer;
    protected AssetCategory $category;
    protected AssetModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Notif Test',
            'email' => 'owner.notif@test.com',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Notif Test Rentals',
            'slug' => 'notif-test-rentals',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'NTF',
            'name' => 'Notif Branch Hub',
            'slug' => 'notif-branch-hub',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Staff Notif Test',
            'email' => 'staff.notif@test.com',
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
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Pelanggan Telat',
            'phone' => '081299990000',
        ]);
    }

    public function test_overdue_rental_triggers_notification_to_owner_and_staff(): void
    {
        // Booking that is 2 hours overdue (exceeds 60-min grace period)
        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-OVERDUE-01',
            'start_at' => Carbon::now()->subDays(2),
            'end_at' => Carbon::now()->subMinutes(120),
            'duration_hours' => 48,
            'status' => 'rented',
            'total_bill' => 300000.00,
            'payment_status' => 'paid',
        ]);

        $this->artisan('rentcore:check-overdue-rentals', ['--grace-minutes' => 60])
            ->assertSuccessful();

        // Must notify owner and staff
        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'type' => 'rental.overdue',
            'category' => 'rental',
        ]);

        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'type' => 'rental.overdue',
            'category' => 'rental',
        ]);

        $ownerNotif = TenantNotification::where('user_id', $this->owner->id)
            ->where('type', 'rental.overdue')
            ->first();

        $this->assertNotNull($ownerNotif);
        $this->assertStringContainsString('RC-OVERDUE-01', $ownerNotif->body);
        $this->assertStringContainsString('Pelanggan Telat', $ownerNotif->body);
    }

    public function test_rental_within_grace_period_does_not_trigger_notification(): void
    {
        // Booking only 25 minutes overdue (within 60m grace period)
        Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-GRACE-01',
            'start_at' => Carbon::now()->subHours(24),
            'end_at' => Carbon::now()->subMinutes(25),
            'duration_hours' => 24,
            'status' => 'rented',
            'total_bill' => 200000.00,
            'payment_status' => 'paid',
        ]);

        $this->artisan('rentcore:check-overdue-rentals', ['--grace-minutes' => 60])
            ->assertSuccessful();

        $this->assertDatabaseMissing('tenant_notifications', [
            'business_id' => $this->business->id,
            'type' => 'rental.overdue',
        ]);
    }

    public function test_overdue_check_is_idempotent_and_does_not_duplicate_alerts_on_repeat(): void
    {
        Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'booking_code' => 'RC-IDEMP-OVERDUE',
            'start_at' => Carbon::now()->subDays(3),
            'end_at' => Carbon::now()->subHours(5),
            'duration_hours' => 72,
            'status' => 'rented',
            'total_bill' => 450000.00,
            'payment_status' => 'paid',
        ]);

        // Run 1
        $this->artisan('rentcore:check-overdue-rentals', ['--grace-minutes' => 60])
            ->assertSuccessful();

        $initialCount = TenantNotification::where('type', 'rental.overdue')->count();
        $this->assertEquals(2, $initialCount); // 1 owner + 1 staff

        // Run 2 on same day
        $this->artisan('rentcore:check-overdue-rentals', ['--grace-minutes' => 60])
            ->assertSuccessful();

        $afterSecondRunCount = TenantNotification::where('type', 'rental.overdue')->count();
        $this->assertEquals(2, $afterSecondRunCount, 'Dedup key must prevent duplicate alert spam on repeat runs');
    }

    public function test_low_fleet_stock_triggers_alert_when_ready_units_depleted(): void
    {
        // Branch has 0 ready units
        $this->artisan('rentcore:check-overdue-rentals')
            ->assertSuccessful();

        $this->assertDatabaseHas('tenant_notifications', [
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'type' => 'inventory.low_stock',
            'category' => 'system',
        ]);
    }
}
