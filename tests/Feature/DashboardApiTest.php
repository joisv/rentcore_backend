<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected Affiliate $affiliateA;
    protected Affiliate $affiliateB;
    protected User $staffA;
    protected AssetModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliateA = Affiliate::create([
            'name' => 'Branch Mataram',
            'slug' => 'branch-mataram',
            'code' => 'MTR',
            'is_active' => true,
        ]);

        $this->affiliateB = Affiliate::create([
            'name' => 'Branch Senggigi',
            'slug' => 'branch-senggigi',
            'code' => 'SGG',
            'is_active' => true,
        ]);

        $this->staffA = User::create([
            'affiliate_id' => $this->affiliateA->id,
            'name' => 'Budi Santoso',
            'email' => 'budi.staff@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $cat = AssetCategory::create([
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'category_id' => $cat->id,
            'name' => 'iPhone 15 Pro 128GB',
            'slug' => 'iphone-15-pro-128gb',
            'brand' => 'Apple',
            'storage_capacity' => '128GB',
            'color' => 'Titanium',
            'is_active' => true,
        ]);
    }

    public function test_kpi_returns_accurate_inventory_breakdown_scoped_to_affiliate(): void
    {
        // Affiliate A assets: 2 ready, 1 rented, 1 maintenance (total 4)
        Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'MTR-001',
            'serial_number' => 'SN-001',
            'status' => 'ready',
        ]);
        Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'MTR-002',
            'serial_number' => 'SN-002',
            'status' => 'ready',
        ]);
        Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'MTR-003',
            'serial_number' => 'SN-003',
            'status' => 'rented',
        ]);
        Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'MTR-004',
            'serial_number' => 'SN-004',
            'status' => 'maintenance',
        ]);

        // Affiliate B asset (should NOT be counted for staff A)
        Asset::create([
            'affiliate_id' => $this->affiliateB->id,
            'model_id' => $this->model->id,
            'asset_code' => 'SGG-001',
            'serial_number' => 'SN-B01',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($this->staffA)
            ->getJson('/api/v1/dashboard/kpi');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kpi.units_total', 4)
            ->assertJsonPath('data.kpi.units_ready', 2)
            ->assertJsonPath('data.kpi.units_rented', 1)
            ->assertJsonPath('data.kpi.units_maintenance', 1)
            ->assertJsonPath('data.kpi.units_in_transit', 0);

        $this->assertEquals(25, $response->json('data.kpi.utilization_rate'));
    }

    public function test_kpi_calculates_today_revenue_and_overdue_alerts(): void
    {
        $customer = Customer::create([
            'name' => 'Ahmad Pelanggan',
            'phone' => '081234567890',
        ]);

        $asset = Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'MTR-OVERDUE',
            'serial_number' => 'SN-999',
            'status' => 'rented',
        ]);

        $now = Carbon::now();

        // 1 overdue booking (ended 2 hours ago)
        $booking = Booking::create([
            'booking_code' => 'BK-TEST-DASH',
            'affiliate_id' => $this->affiliateA->id,
            'customer_id' => $customer->id,
            'created_by' => $this->staffA->id,
            'start_at' => (clone $now)->subHours(26),
            'end_at' => (clone $now)->subHours(2),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 300000,
            'total_bill' => 300000,
            'collateral_type' => 'KTP Asli',
            'collateral_storage_slot' => 'SLOT-A1',
            'collateral_secured' => true,
        ]);

        $booking->items()->create([
            'asset_id' => $asset->id,
            'unit_price' => 300000,
        ]);

        $pm = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        // Payment today
        PaymentTransaction::create([
            'transaction_code' => 'TX-261011-0001',
            'booking_id' => $booking->id,
            'payment_method_id' => $pm->id,
            'processed_by' => $this->staffA->id,
            'type' => 'settlement',
            'amount' => 300000,
            'receipt_number' => 'REC-MTR-001',
            'transacted_at' => $now,
        ]);

        $response = $this->actingAs($this->staffA)
            ->getJson('/api/v1/dashboard/kpi');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kpi.overdue', 1)
            ->assertJsonPath('data.kpi.today_revenue', 300000)
            ->assertJsonPath('data.kpi.today_transactions_count', 1);

        $this->assertCount(1, $response->json('data.overdue_alerts'));
        $this->assertEquals('BK-TEST-DASH', $response->json('data.overdue_alerts.0.booking_code'));
        $this->assertEquals('Ahmad Pelanggan', $response->json('data.overdue_alerts.0.customer_name'));
    }

    public function test_kpi_handles_null_customer_and_unlinked_asset_model_without_throwing_500(): void
    {
        $now = Carbon::now();

        $asset = Asset::create([
            'affiliate_id' => $this->affiliateA->id,
            'model_id' => $this->model->id,
            'asset_code' => 'MTR-ORPHAN',
            'serial_number' => 'SN-ORPHAN',
            'status' => 'rented',
        ]);

        // Customer assigned to a different business context so TenantScope resolves it to null
        $otherBusiness = \App\Models\Business::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'owner_id' => $this->staffA->id,
            'is_active' => true,
        ]);

        $cust = Customer::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Tenant Customer',
            'phone' => '08999999999',
            'email' => 'other@example.com',
            'address' => 'Jakarta',
        ]);

        $booking = Booking::create([
            'affiliate_id' => $this->affiliateA->id,
            'customer_id' => $cust->id,
            'created_by' => $this->staffA->id,
            'booking_code' => 'BK-ORPHAN-DASH',
            'start_at' => (clone $now)->subDays(2),
            'end_at' => (clone $now)->subHours(3),
            'duration_hours' => 48,
            'status' => 'rented',
            'subtotal_amount' => 150000,
            'total_bill' => 150000,
            'collateral_type' => 'KTP Asli',
            'collateral_storage_slot' => 'SLOT-ORPHAN',
            'collateral_secured' => true,
        ]);

        $booking->items()->create([
            'asset_id' => $asset->id,
            'unit_price' => 150000,
        ]);

        $response = $this->actingAs($this->staffA)
            ->getJson('/api/v1/dashboard/kpi');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $alerts = $response->json('data.overdue_alerts');
        $this->assertNotEmpty($alerts);
        $alertItem = collect($alerts)->firstWhere('booking_code', 'BK-ORPHAN-DASH');
        $this->assertNotNull($alertItem);
        $this->assertEquals($this->model->name, $alertItem['unit_name']);
        // If customer is filtered by tenant scope, fallback 'Pelanggan Umum' is safely used without crashing
        $this->assertTrue(in_array($alertItem['customer_name'], ['Pelanggan Umum', 'Other Tenant Customer']));
    }
}
