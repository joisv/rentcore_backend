<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\User;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VoucherApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $staff;
    protected Affiliate $affiliate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->affiliate = Affiliate::create([
            'code' => 'GNT',
            'name' => 'Genteng Central Hub',
            'slug' => 'genteng-central-hub',
            'is_central' => true,
        ]);

        $this->superAdmin = User::create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Budi Kasir',
            'email' => 'staff@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->affiliate->id,
            'is_active' => true,
        ]);
    }

    public function test_super_admin_can_create_percentage_voucher_with_min_transaction(): void
    {
        $token = $this->superAdmin->createToken('Test')->plainTextToken;

        $payload = [
            'code' => 'VCR-SUPER15',
            'name' => 'Voucher 15% Spesial',
            'discount_percentage' => 15.00,
            'min_transaction_amount' => 300000.00,
            'max_discount_cap' => 100000.00,
            'barcode_type' => 'qr_code',
            'start_at' => Carbon::now()->subDay()->toDateTimeString(),
            'end_at' => Carbon::now()->addMonth()->toDateTimeString(),
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/vouchers', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'VCR-SUPER15')
            ->assertJsonPath('data.discount_percentage', 15)
            ->assertJsonPath('data.min_transaction_amount', 300000);

        $this->assertDatabaseHas('vouchers', [
            'code' => 'VCR-SUPER15',
            'discount_percentage' => 15.00,
            'min_transaction_amount' => 300000.00,
        ]);
    }

    public function test_staff_cannot_create_vouchers(): void
    {
        $token = $this->staff->createToken('Test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/vouchers', [
                'name' => 'Unauthorized Voucher',
                'discount_percentage' => 10,
                'min_transaction_amount' => 100000,
                'start_at' => Carbon::now()->toDateTimeString(),
                'end_at' => Carbon::now()->addMonth()->toDateTimeString(),
            ]);

        $response->assertStatus(403);
    }

    public function test_voucher_validation_fails_if_transaction_below_min_threshold(): void
    {
        $voucher = Voucher::create([
            'code' => 'MIN300K',
            'name' => 'Min 300K Promo',
            'discount_percentage' => 15.00,
            'min_transaction_amount' => 300000.00,
            'barcode_type' => 'qr_code',
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->superAdmin->id,
            'is_active' => true,
        ]);

        $token = $this->staff->createToken('Test')->plainTextToken;

        // Subtotal 250,000 is below 300,000 threshold
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/vouchers/validate', [
                'code' => 'MIN300K',
                'subtotal' => 250000.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_code']);
    }

    public function test_voucher_validation_succeeds_when_transaction_meets_min_threshold(): void
    {
        $voucher = Voucher::create([
            'code' => 'MIN300K',
            'name' => 'Min 300K Promo',
            'discount_percentage' => 15.00,
            'min_transaction_amount' => 300000.00,
            'barcode_type' => 'qr_code',
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->superAdmin->id,
            'is_active' => true,
        ]);

        $token = $this->staff->createToken('Test')->plainTextToken;

        // Subtotal 400,000 >= 300,000 threshold
        // Discount 15% of 400,000 = 60,000; Final payable = 340,000
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/vouchers/validate', [
                'code' => 'MIN300K',
                'subtotal' => 400000.00,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'valid' => true,
                    'code' => 'MIN300K',
                    'discount_percentage' => 15.0,
                    'calculated_discount' => 60000.0,
                    'final_payable' => 340000.0,
                ],
            ]);
    }

    public function test_thermal_slip_payload_endpoint_returns_2d_barcode(): void
    {
        $voucher = Voucher::create([
            'code' => 'THERMAL2D',
            'name' => 'Thermal Test Voucher',
            'discount_percentage' => 20.00,
            'min_transaction_amount' => 500000.00,
            'barcode_type' => 'qr_code',
            'start_at' => Carbon::now()->subDay(),
            'end_at' => Carbon::now()->addMonth(),
            'created_by' => $this->superAdmin->id,
            'is_active' => true,
        ]);

        $token = $this->staff->createToken('Test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/vouchers/{$voucher->code}/print-slip");

        $response->assertStatus(200)
            ->assertJsonPath('data.voucher_code', 'THERMAL2D')
            ->assertJsonPath('data.barcode_format', 'qr_code')
            ->assertJsonPath('data.qr_payload', 'THERMAL2D');
    }
}
