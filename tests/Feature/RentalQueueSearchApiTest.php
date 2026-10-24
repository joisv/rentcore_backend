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
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RentalQueueSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch1;
    protected Affiliate $branch2;
    protected User $staff1;
    protected User $staff2;
    protected AssetModel $modelIphone;
    protected AssetModel $modelMacbook;
    protected Asset $asset1;
    protected Asset $asset2;
    protected Booking $booking1;
    protected Booking $booking2;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Bisnis Rental',
            'slug' => 'bisnis-rental',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        $this->branch1 = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'BR1',
            'name' => 'Cabang Satu',
            'slug' => 'cabang-satu',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branch2 = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'BR2',
            'name' => 'Cabang Dua',
            'slug' => 'cabang-dua',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->staff1 = User::create([
            'name' => 'Staff Cabang 1',
            'email' => 'staff1@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch1->id,
            'is_active' => true,
        ]);

        $this->staff2 = User::create([
            'name' => 'Staff Cabang 2',
            'email' => 'staff2@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch2->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff1->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff2->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $cat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Gadgets',
            'slug' => 'gadgets',
            'is_active' => true,
        ]);

        $this->modelIphone = AssetModel::create([
            'category_id' => $cat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro Max',
            'slug' => 'iphone-15-pro-max',
            'storage_capacity' => '256GB',
            'color' => 'Natural Titanium',
            'is_active' => true,
        ]);

        $this->modelMacbook = AssetModel::create([
            'category_id' => $cat->id,
            'brand' => 'Apple',
            'name' => 'MacBook Pro M3',
            'slug' => 'macbook-pro-m3',
            'storage_capacity' => '512GB',
            'color' => 'Space Black',
            'is_active' => true,
        ]);

        $this->asset1 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch1->id,
            'model_id' => $this->modelIphone->id,
            'asset_code' => 'AST-IPH-001',
            'serial_number' => 'SN-IPHONE-9988',
            'status' => 'rented',
            'condition_grade' => 'A',
        ]);

        $this->asset2 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch1->id,
            'model_id' => $this->modelMacbook->id,
            'asset_code' => 'AST-MAC-002',
            'serial_number' => 'SN-MACBOOK-7766',
            'status' => 'rented',
            'condition_grade' => 'A',
        ]);

        $customer1 = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Siti Nurhaliza',
            'phone' => '081234567890',
        ]);

        $customer2 = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Budi Santoso',
            'phone' => '089876543210',
        ]);

        $this->booking1 = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'booking_code' => 'RC-SEARCH-001',
            'affiliate_id' => $this->branch1->id,
            'customer_id' => $customer1->id,
            'created_by' => $this->staff1->id,
            'start_at' => Carbon::now()->subHours(5),
            'end_at' => Carbon::now()->addHours(19),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000.00,
            'total_bill' => 300000.00,
            'payment_status' => 'paid',
        ]);

        $this->booking1->items()->create([
            'asset_id' => $this->asset1->id,
            'unit_price' => 300000.00,
        ]);

        $this->booking2 = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'booking_code' => 'RC-SEARCH-002',
            'affiliate_id' => $this->branch1->id,
            'customer_id' => $customer2->id,
            'created_by' => $this->staff1->id,
            'start_at' => Carbon::now()->subDays(2),
            'end_at' => Carbon::now()->subHours(2), // Overdue
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 500000.00,
            'total_bill' => 500000.00,
            'payment_status' => 'partial',
        ]);

        $this->booking2->items()->create([
            'asset_id' => $this->asset2->id,
            'unit_price' => 500000.00,
        ]);
    }

    public function test_search_by_booking_code_exact_and_partial(): void
    {
        $resp = $this->actingAs($this->staff1)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch1->id)
            ->getJson('/api/v1/bookings?search=RC-SEARCH-001');

        $resp->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.booking_code', 'RC-SEARCH-001');

        // Partial match
        $respPartial = $this->actingAs($this->staff1)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch1->id)
            ->getJson('/api/v1/bookings?search=SEARCH');

        $respPartial->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_search_by_customer_name_and_phone(): void
    {
        // By name
        $respName = $this->actingAs($this->staff1)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch1->id)
            ->getJson('/api/v1/bookings?search=Nurhaliza');

        $respName->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.booking_code', 'RC-SEARCH-001');

        // By phone
        $respPhone = $this->actingAs($this->staff1)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch1->id)
            ->getJson('/api/v1/bookings?search=08987654');

        $respPhone->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.booking_code', 'RC-SEARCH-002');
    }

    public function test_search_by_asset_code_and_serial_number(): void
    {
        // By asset code
        $respCode = $this->actingAs($this->staff1)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch1->id)
            ->getJson('/api/v1/bookings?search=AST-IPH-001');

        $respCode->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.booking_code', 'RC-SEARCH-001');

        // By serial number
        $respSerial = $this->actingAs($this->staff1)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch1->id)
            ->getJson('/api/v1/bookings?search=MACBOOK-7766');

        $respSerial->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.booking_code', 'RC-SEARCH-002');
    }

    public function test_search_by_model_name(): void
    {
        $respModel = $this->actingAs($this->staff1)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch1->id)
            ->getJson('/api/v1/bookings?search=MacBook');

        $respModel->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.booking_code', 'RC-SEARCH-002');
    }

    public function test_search_combined_with_status_filter(): void
    {
        // Both match 'SEARCH', but only booking2 is 'overdue'
        $respOverdue = $this->actingAs($this->staff1)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch1->id)
            ->getJson('/api/v1/bookings?search=SEARCH&status=overdue');

        $respOverdue->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.booking_code', 'RC-SEARCH-002');
    }

    public function test_search_respects_branch_isolation(): void
    {
        // Staff from Branch 2 searches for RC-SEARCH-001 (which belongs to Branch 1)
        $respIsolated = $this->actingAs($this->staff2)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branch2->id)
            ->getJson('/api/v1/bookings?search=RC-SEARCH-001');

        $respIsolated->assertOk()
            ->assertJsonCount(0, 'data.data');
    }
}
