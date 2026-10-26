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
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrencyAndBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $cashier;
    protected Business $business;
    protected Affiliate $branch;
    protected PaymentMethod $cashMethod;
    protected AssetCategory $category;
    protected AssetModel $model;
    protected Customer $customer;
    protected Voucher $voucher;
    protected CashDrawerShift $shift;
    protected string $cashierToken;
    protected string $ownerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Benchmark Owner',
            'email' => 'benchmark.owner@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Benchmark Rental Corp',
            'slug' => 'benchmark-rental-corp',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'BMK',
            'name' => 'Benchmark Main Branch',
            'slug' => 'benchmark-main-branch',
            'is_central' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Benchmark Cashier',
            'email' => 'cashier@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'cashier',
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
            'user_id' => $this->cashier->id,
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

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'price' => 300000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Budi Benchmark Customer',
            'phone' => '081299998888',
        ]);

        $this->voucher = Voucher::create([
            'business_id' => $this->business->id,
            'created_by' => $this->owner->id,
            'code' => 'BENCHMARK10',
            'name' => 'Voucher Diskon 10%',
            'discount_percentage' => 10.00,
            'min_transaction_amount' => 50000.00,
            'max_discount_cap' => 50000.00,
            'usage_limit_per_customer' => 100,
            'is_active' => true,
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
        ]);

        // Open shift for cashier operations
        $this->shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_number' => 'SHIFT-BENCH-001',
            'opened_by' => $this->cashier->id,
            'starting_cash' => 500000,
            'opened_at' => Carbon::now()->subHours(2),
            'status' => 'open',
        ]);

        $this->cashierToken = $this->cashier->createToken('CashierBenchToken')->plainTextToken;
        $this->ownerToken = $this->owner->createToken('OwnerBenchToken')->plainTextToken;
    }

    private function calculatePercentile(array $values, float $percentile): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $index = ($percentile / 100) * ($count - 1);
        $floor = (int) floor($index);
        $ceil = (int) ceil($index);
        if ($floor === $ceil) {
            return (float) $values[$floor];
        }
        return (float) ($values[$floor] + ($values[$ceil] - $values[$floor]) * ($index - $floor));
    }

    /**
     * Scenario 1: High-Traffic POS Checkout Concurrency & Latency
     * Simulates 20 virtual cashier checkout operations:
     * - Query ready inventory
     * - Validate discount voucher
     * - Create booking
     * - Process payment with row locks
     */
    public function test_scenario_1_pos_checkout_concurrency_and_latency(): void
    {
        // 1. Create 20 ready assets and customers
        $assets = [];
        $customers = [];
        for ($i = 1; $i <= 20; $i++) {
            $assets[] = Asset::create([
                'business_id' => $this->business->id,
                'affiliate_id' => $this->branch->id,
                'category_id' => $this->category->id,
                'model_id' => $this->model->id,
                'asset_code' => "AST-BENCH-{$i}",
                'serial_number' => "SN-BENCH-{$i}-" . Str::random(5),
                'status' => 'ready',
                'condition_grade' => 'A',
            ]);

            $customers[] = Customer::create([
                'business_id' => $this->business->id,
                'name' => "Customer Benchmark {$i}",
                'phone' => "0812" . sprintf('%08d', $i),
            ]);
        }

        $headers = [
            'Authorization' => "Bearer {$this->cashierToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];

        $checkoutLatencies = [];
        $queryCounts = [];

        for ($i = 0; $i < 20; $i++) {
            $asset = $assets[$i];
            $customer = $customers[$i];
            $queriesInIteration = 0;
            DB::listen(function () use (&$queriesInIteration) {
                $queriesInIteration++;
            });

            $startTime = microtime(true);

            // Step A: Query ready inventory
            $invRes = $this->withHeaders($headers)->getJson('/api/v1/assets?status=ready');
            $this->assertEquals(200, $invRes->status());

            // Step B: Validate Voucher
            $vcrRes = $this->withHeaders($headers)->postJson('/api/v1/vouchers/validate', [
                'code' => 'BENCHMARK10',
                'subtotal' => 150000,
            ]);
            $this->assertEquals(200, $vcrRes->status());

            // Step C: Create Booking & POS Checkout (Counter Rental)
            $bookingRes = $this->withHeaders($headers)->postJson('/api/v1/bookings', [
                'affiliate_id' => $this->branch->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_address' => 'Jl. Benchmark No. 1',
                'asset_ids' => [$asset->id],
                'duration_hours' => 24,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 300000,
                'voucher_code' => 'BENCHMARK10',
            ]);
            $this->assertEquals(201, $bookingRes->status());
            $bookingId = $bookingRes->json('data.id');

            // Step D: Execute Rental Extension with row lock and idempotency key
            $idempotencyKey = "pos_bench_ext_" . Str::uuid();
            $extendRes = $this->withHeaders($headers)->postJson("/api/v1/bookings/{$bookingId}/extend", [
                'additional_hours' => 2,
                'additional_fee' => 30000,
                'payment_method_id' => $this->cashMethod->id,
                'cash_received' => 30000,
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->assertEquals(200, $extendRes->status());

            $durationMs = (microtime(true) - $startTime) * 1000;
            $checkoutLatencies[] = $durationMs;
            $queryCounts[] = $queriesInIteration;
        }

        $p50 = $this->calculatePercentile($checkoutLatencies, 50);
        $p90 = $this->calculatePercentile($checkoutLatencies, 90);
        $p95 = $this->calculatePercentile($checkoutLatencies, 95);
        $avgQueries = array_sum($queryCounts) / count($queryCounts);

        echo "\n[BENCHMARK SCENARIO 1] 20 POS Checkouts: p50={$p50}ms, p90={$p90}ms, p95={$p95}ms, avg_queries={$avgQueries}\n";

        // Validate targets: 0 deadlocks, zero constraint failures (all 20 succeeded), and p95 latency is bounded
        $this->assertCount(20, $checkoutLatencies);
        $this->assertLessThan(400, $p95, "p95 checkout latency should be under 400ms");
    }

    /**
     * Scenario 2: Dashboard & Overdue Polling Load
     * Simulates 50 virtual users polling KPI dashboard and notifications
     */
    public function test_scenario_2_dashboard_and_notification_polling_load(): void
    {
        // Seed some notifications
        for ($i = 1; $i <= 10; $i++) {
            TenantNotification::create([
                'business_id' => $this->business->id,
                'user_id' => $this->owner->id,
                'category' => 'system',
                'type' => 'system.alert',
                'title' => "Notification {$i}",
                'body' => "Message body {$i}",
                'read_at' => null,
            ]);
        }

        $headers = [
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];

        $kpiLatencies = [];
        $queriesLogged = [];

        for ($i = 0; $i < 50; $i++) {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });

            $start = microtime(true);

            $kpiRes = $this->withHeaders($headers)->getJson('/api/v1/dashboard/kpi');
            $this->assertEquals(200, $kpiRes->status());

            $notifRes = $this->withHeaders($headers)->getJson('/api/v1/notifications');
            $this->assertEquals(200, $notifRes->status());

            $durationMs = (microtime(true) - $start) * 1000;
            $kpiLatencies[] = $durationMs;
            $queriesLogged[] = $queries;
        }

        $p50 = $this->calculatePercentile($kpiLatencies, 50);
        $p90 = $this->calculatePercentile($kpiLatencies, 90);
        $p95 = $this->calculatePercentile($kpiLatencies, 95);
        $avgQueries = array_sum($queriesLogged) / count($queriesLogged);

        echo "\n[BENCHMARK SCENARIO 2] 50 Dashboard & Notif Requests: p50={$p50}ms, p90={$p90}ms, p95={$p95}ms, avg_queries={$avgQueries}\n";

        $this->assertCount(50, $kpiLatencies);
        $this->assertLessThan(150, $p95, "p95 dashboard polling latency should be under 150ms");
    }

    /**
     * Scenario 3: Reporting Stress Test
     * Simulates 10 store owners requesting 30-day closing summaries over 5,000 transactions
     */
    public function test_scenario_3_closing_summary_reporting_stress_test(): void
    {
        // Dummy booking to associate payment records with
        $dummyBooking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->owner->id,
            'booking_code' => 'RC-BENCH-DUMMY',
            'start_at' => Carbon::now()->subDays(40),
            'end_at' => Carbon::now(),
            'duration_hours' => 960,
            'total_bill' => 750000000.00,
            'payment_status' => 'settled',
        ]);

        // 1. Bulk insert 5,000 payment records spanning 30 days
        $now = Carbon::now();
        $batch = [];
        for ($i = 1; $i <= 5000; $i++) {
            $batch[] = [
                'business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'booking_id' => $dummyBooking->id,
                'processed_by' => $this->cashier->id,
                'payment_method_id' => $this->cashMethod->id,
                'transaction_code' => "TX-BENCH-" . sprintf('%05d', $i),
                'receipt_number' => "RCP-BENCH-" . sprintf('%05d', $i),
                'amount' => 150000,
                'type' => 'settlement',
                'direction' => 'inflow',
                'status' => 'completed',
                'cash_received' => 150000,
                'cash_change' => 0,
                'transacted_at' => $now->copy()->subDays($i % 30)->subMinutes($i % 60)->toDateTimeString(),
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];

            if (count($batch) >= 1000) {
                PaymentTransaction::insert($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) {
            PaymentTransaction::insert($batch);
        }

        $headers = [
            'Authorization' => "Bearer {$this->ownerToken}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];

        $reportLatencies = [];
        $peakMemories = [];

        $startDate = $now->copy()->subDays(30)->toDateString();
        $endDate = $now->toDateString();

        for ($i = 0; $i < 10; $i++) {
            $memBefore = memory_get_peak_usage(true);
            $start = microtime(true);

            $res = $this->withHeaders($headers)->getJson("/api/v1/cashier/closing-summary?start_date={$startDate}&end_date={$endDate}");
            $this->assertEquals(200, $res->status());

            $durationMs = (microtime(true) - $start) * 1000;
            $peakMemMb = (memory_get_peak_usage(true) - $memBefore) / (1024 * 1024);

            $reportLatencies[] = $durationMs;
            $peakMemories[] = $peakMemMb;
        }

        $p50 = $this->calculatePercentile($reportLatencies, 50);
        $p95 = $this->calculatePercentile($reportLatencies, 95);
        $maxPeakMem = max($peakMemories);

        echo "\n[BENCHMARK SCENARIO 3] 10 Closing Summaries over 5,000 records: p50={$p50}ms, p95={$p95}ms, max_delta_memory={$maxPeakMem}MB\n";

        // Assert memory consumption is tightly contained (< 32MB) and response time < 1000ms
        $this->assertLessThan(32.0, $maxPeakMem, "Peak memory allocation must be well below 32MB");
        $this->assertLessThan(1000.0, $p95, "p95 closing summary duration must be under 1.0s");
    }
}
