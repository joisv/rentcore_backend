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
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinancialControlP0Test extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branchMataram;
    protected Affiliate $branchSenggigi;
    protected User $owner;
    protected User $cashierMataram;
    protected User $cashierSenggigi;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $qrisMethod;
    protected AssetModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Utama',
            'email' => 'owner.fin@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Financial Demo',
            'slug' => 'rentcore-fin-demo',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->branchMataram = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'MTR',
            'name' => 'Cabang Mataram',
            'slug' => 'cabang-mataram',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchSenggigi = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'SGG',
            'name' => 'Cabang Senggigi',
            'slug' => 'cabang-senggigi',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->cashierMataram = User::create([
            'name' => 'Kasir Mataram',
            'email' => 'kasir.mtr@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchMataram->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);

        $this->cashierSenggigi = User::create([
            'name' => 'Kasir Senggigi',
            'email' => 'kasir.sgg@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchSenggigi->id,
            'business_id' => $this->business->id,
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
            'user_id' => $this->cashierMataram->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->cashierSenggigi->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->qrisMethod = PaymentMethod::create([
            'code' => 'QRIS',
            'name' => 'QRIS',
            'is_active' => true,
        ]);

        $cat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Gadgets',
            'slug' => 'gadgets-fin',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'category_id' => $cat->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro Max',
            'slug' => 'iphone-15-pro-max',
            'storage_capacity' => '256GB',
            'color' => 'Natural Titanium',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'price' => 350000.00,
            'extension_hourly_rate' => 25000.00,
        ]);
    }

    public function test_return_inspection_records_deposit_refund_transaction(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Rian Hidayat',
            'phone' => '081234567890',
        ]);

        $asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchMataram->id,
            'model_id' => $this->model->id,
            'asset_code' => 'AST-FIN-01',
            'serial_number' => 'SN-FIN-01',
            'status' => 'rented',
            'condition_grade' => 'A',
            'battery_health' => 100,
        ]);

        $now = Carbon::now();

        // Booking with rental fee 350.000 + deposit fee 200.000 = total 550.000
        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchMataram->id,
            'booking_code' => 'RC-DEP-001',
            'customer_id' => $customer->id,
            'created_by' => $this->cashierMataram->id,
            'start_at' => (clone $now)->subHours(24),
            'end_at' => $now,
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 350000.00,
            'deposit_fee' => 200000.00,
            'total_bill' => 550000.00,
            'payment_status' => 'paid',
            'collateral_secured' => true,
        ]);

        $booking->items()->create([
            'asset_id' => $asset->id,
            'unit_price' => 350000.00,
        ]);

        // Customer initially paid 550.000
        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-DEP-001',
            'receipt_number' => 'REC-DEP-001',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->cashierMataram->id,
            'type' => 'settlement',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 550000.00,
            'cash_received' => 550000.00,
            'cash_change' => 0.00,
            'transacted_at' => (clone $now)->subHours(24),
        ]);

        // Process return with no penalties (unit returned intact on time)
        $returnService = app(\App\Services\ReturnService::class);
        $result = $returnService->processReturn($booking, [
            'condition_grade' => 'A',
            'battery_health_final' => 99,
            'checklist_results' => [
                'icloud_removed' => true,
            ],
            'accessories_returned' => ['cable' => true, 'adapter' => true],
        ], $this->cashierMataram);

        $this->assertEquals(200000.00, (float)$result->deposit_refunded);

        // Verify that a PaymentTransaction of type 'refund' and direction 'outflow' was created
        $refundTx = PaymentTransaction::where('booking_id', $booking->id)
            ->where('type', 'refund')
            ->first();

        $this->assertNotNull($refundTx);
        $this->assertEquals(200000.00, (float)$refundTx->amount);
        $this->assertEquals('outflow', $refundTx->direction);
        $this->assertEquals('completed', $refundTx->status);
        $this->assertEquals($this->branchMataram->id, $refundTx->branch_id);
    }

    public function test_closing_summary_subtracts_refunds_from_total_revenue(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Budi Santoso',
            'phone' => '081987654321',
        ]);

        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchMataram->id,
            'booking_code' => 'RC-REV-001',
            'customer_id' => $customer->id,
            'created_by' => $this->cashierMataram->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'returned',
            'subtotal_amount' => 300000.00,
            'deposit_fee' => 100000.00,
            'total_bill' => 400000.00,
            'payment_status' => 'paid',
        ]);

        // 1. Payment Inflow: Rp 400.000 Cash
        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-IN-001',
            'receipt_number' => 'REC-IN-001',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->cashierMataram->id,
            'type' => 'settlement',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 400000.00,
            'cash_received' => 400000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        // 2. Refund Outflow: Rp 100.000 Cash
        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-OUT-001',
            'receipt_number' => 'REC-OUT-001',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->cashierMataram->id,
            'type' => 'refund',
            'status' => 'completed',
            'direction' => 'outflow',
            'amount' => 100000.00,
            'cash_received' => 100000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        // 3. Void Inflow: Rp 200.000 (should NOT be added to revenue)
        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-VOID-001',
            'receipt_number' => 'REC-VOID-001',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->cashierMataram->id,
            'type' => 'settlement',
            'status' => 'void',
            'direction' => 'inflow',
            'amount' => 200000.00,
            'cash_received' => 200000.00,
            'cash_change' => 0.00,
            'transacted_at' => Carbon::now(),
        ]);

        // Fetch closing summary
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchMataram->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Net revenue should be 400.000 - 100.000 = 300.000 (void 200.000 excluded)
        $data = $response->json('data');
        $this->assertEquals(300000.00, (float)$data['total_revenue']);
        $this->assertEquals(300000.00, (float)$data['breakdown_by_method']['Tunai']['total']);
        $this->assertEquals(100000.00, (float)$data['breakdown_by_type']['refund']['total']);
    }

    public function test_dashboard_kpi_accurately_reflects_net_revenue(): void
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Dedi Setiawan',
            'phone' => '0812999888',
        ]);

        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branchMataram->id,
            'booking_code' => 'RC-KPI-001',
            'customer_id' => $customer->id,
            'created_by' => $this->cashierMataram->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 500000.00,
            'total_bill' => 500000.00,
            'payment_status' => 'paid',
        ]);

        // Inflow 500k
        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-KPI-001',
            'receipt_number' => 'REC-KPI-001',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->cashierMataram->id,
            'type' => 'settlement',
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => 500000.00,
            'transacted_at' => Carbon::now(),
        ]);

        // Outflow 150k
        PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'transaction_code' => 'TX-KPI-002',
            'receipt_number' => 'REC-KPI-002',
            'booking_id' => $booking->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->cashierMataram->id,
            'type' => 'refund',
            'status' => 'completed',
            'direction' => 'outflow',
            'amount' => 150000.00,
            'transacted_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->cashierMataram)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/dashboard/kpi');

        $response->assertOk();
        // Today revenue should be 500.000 - 150.000 = 350.000
        $this->assertEquals(350000.00, (float)$response->json('data.kpi.today_revenue'));
    }
}
