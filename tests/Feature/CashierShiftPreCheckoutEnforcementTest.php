<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Business;
use App\Models\CashDrawerShift;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashierShiftPreCheckoutEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $cashier;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $linkajaMethod;
    protected PaymentMethod $mandiriMethod;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create([
            'name' => 'Owner Rentcore',
            'email' => 'owner@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Central',
            'slug' => 'rentcore-central',
            'owner_id' => $owner->id,
            'is_active' => true,
            'shift_enforcement_enabled' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'PST',
            'name' => 'Cabang Pusat',
            'slug' => 'cabang-pusat',
            'is_central' => true,
        ]);

        $this->cashier = User::create([
            'name' => 'Siti Kasir',
            'email' => 'kasir@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branch->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::firstOrCreate(['code' => 'CASH'], ['name' => 'Tunai', 'is_active' => true]);
        $this->linkajaMethod = PaymentMethod::firstOrCreate(['code' => 'LINKAJA'], ['name' => 'LinkAja', 'is_active' => true]);
        $this->mandiriMethod = PaymentMethod::firstOrCreate(['code' => 'MANDIRI'], ['name' => 'Transfer Bank Mandiri', 'is_active' => true]);

        $category = AssetCategory::create(['business_id' => $this->business->id, 'name' => 'Kamera', 'slug' => 'kamera']);
        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => 'Sony',
            'name' => 'Sony A7 IV',
            'slug' => 'sony-a7-iv',
            'rental_rate_daily' => 150000,
            'is_active' => true,
        ]);

        \App\Models\PricingTier::create([
            'model_id' => $model->id,
            'duration_hours' => 24,
            'price' => 150000.00,
            'extension_hourly_rate' => 10000.00,
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => 'AST-SNY-001',
            'status' => 'ready',
            'condition_grade' => 'A',
        ]);
    }

    public function test_checkout_fails_when_no_cashier_shift_is_active(): void
    {
        $token = $this->cashier->createToken('Test')->plainTextToken;

        $payload = [
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Ahmad Customer',
            'customer_phone' => '081234567890',
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 200000,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['shift']);
        $this->assertStringContainsString(
            'Shift belum dibuka. Buka shift kasir terlebih dahulu sebelum memproses transaksi.',
            $response->json('message')
        );

        // Verify no orphaned booking or payment was created
        $this->assertDatabaseMissing('bookings', ['customer_phone' => '081234567890']);
        $this->assertDatabaseMissing('payment_transactions', ['processed_by' => $this->cashier->id]);
    }

    public function test_checkout_succeeds_when_shift_is_active_and_persists_shift_id(): void
    {
        // 1. Open Shift
        $shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_number' => 'SHIFT-001',
            'opened_by' => $this->cashier->id,
            'opened_at' => Carbon::now(),
            'opening_cash_float' => 500000,
            'status' => 'open',
        ]);

        $token = $this->cashier->createToken('Test')->plainTextToken;

        $payload = [
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Ahmad Customer',
            'customer_phone' => '081234567890',
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 200000,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('customers', ['phone' => '081234567890']);

        // Verify shift_id is correctly associated with payment transaction
        $this->assertDatabaseHas('payment_transactions', [
            'shift_id' => $shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'processed_by' => $this->cashier->id,
        ]);
    }

    public function test_ewallet_and_bank_transfer_methods_persist_exact_provider(): void
    {
        $shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_number' => 'SHIFT-002',
            'opened_by' => $this->cashier->id,
            'opened_at' => Carbon::now(),
            'opening_cash_float' => 500000,
            'status' => 'open',
        ]);

        $token = $this->cashier->createToken('Test')->plainTextToken;

        // E-Wallet LinkAja test
        $payloadLinkAja = [
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Budi LinkAja User',
            'customer_phone' => '081299991111',
            'asset_ids' => [$this->asset->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->linkajaMethod->id,
        ];

        $responseLinkAja = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payloadLinkAja);

        $responseLinkAja->assertStatus(201);
        $this->assertDatabaseHas('payment_transactions', [
            'shift_id' => $shift->id,
            'payment_method_id' => $this->linkajaMethod->id,
        ]);

        // Bank Transfer Mandiri test
        $asset2 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->asset->model_id,
            'asset_code' => 'AST-SNY-002',
            'status' => 'ready',
            'condition_grade' => 'A',
        ]);

        $payloadMandiri = [
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Charlie Mandiri User',
            'customer_phone' => '081299992222',
            'asset_ids' => [$asset2->id],
            'duration_hours' => 24,
            'payment_method_id' => $this->mandiriMethod->id,
        ];

        $responseMandiri = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/bookings', $payloadMandiri);

        $responseMandiri->assertStatus(201);
        $this->assertDatabaseHas('payment_transactions', [
            'shift_id' => $shift->id,
            'payment_method_id' => $this->mandiriMethod->id,
        ]);
    }
}
