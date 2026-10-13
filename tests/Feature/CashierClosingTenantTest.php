<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierClosingTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_only_sees_transactions_for_their_own_branch(): void
    {
        // 1. Setup Affiliates
        $affiliateA = Affiliate::create([
            'code' => 'GNT',
            'name' => 'Genteng Central Hub',
            'slug' => 'genteng-central',
            'is_central' => true,
        ]);

        $affiliateB = Affiliate::create([
            'code' => 'SLR',
            'name' => 'Siliragung Outlet',
            'slug' => 'siliragung-outlet',
            'is_central' => false,
        ]);

        // 2. Setup Cashiers
        $cashierA = User::create([
            'name' => 'Kasir A',
            'email' => 'kasir.a@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $affiliateA->id,
            'is_active' => true,
        ]);

        $cashierB = User::create([
            'name' => 'Kasir B',
            'email' => 'kasir.b@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $affiliateB->id,
            'is_active' => true,
        ]);

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'affiliate_id' => null,
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Pelanggan Uji',
            'phone' => '081234567890',
        ]);

        $method = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        // 3. Create Booking & Transaction in Branch A
        $bookingA = Booking::create([
            'booking_code' => 'RC-261010-0001',
            'affiliate_id' => $affiliateA->id,
            'customer_id' => $customer->id,
            'created_by' => $cashierA->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 200000.00,
            'total_bill' => 200000.00,
            'payment_status' => 'paid',
            'collateral_type' => 'KTP Asli',
            'collateral_secured' => true,
        ]);

        PaymentTransaction::create([
            'transaction_code' => 'TX-261010-0001',
            'receipt_number' => 'REC-GNT-261010-0001',
            'booking_id' => $bookingA->id,
            'payment_method_id' => $method->id,
            'processed_by' => $cashierA->id,
            'type' => 'settlement',
            'amount' => 200000.00,
            'transacted_at' => Carbon::now(),
        ]);

        // 4. Create Booking & Transaction in Branch B
        $bookingB = Booking::create([
            'booking_code' => 'RC-261010-0002',
            'affiliate_id' => $affiliateB->id,
            'customer_id' => $customer->id,
            'created_by' => $cashierB->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 500000.00,
            'total_bill' => 500000.00,
            'payment_status' => 'paid',
            'collateral_type' => 'KTP Asli',
            'collateral_secured' => true,
        ]);

        PaymentTransaction::create([
            'transaction_code' => 'TX-261010-0002',
            'receipt_number' => 'REC-SLR-261010-0002',
            'booking_id' => $bookingB->id,
            'payment_method_id' => $method->id,
            'processed_by' => $cashierB->id,
            'type' => 'settlement',
            'amount' => 500000.00,
            'transacted_at' => Carbon::now(),
        ]);

        // 5. Test Cashier A (Should only see Branch A: 200,000 IDR)
        Sanctum::actingAs($cashierA);
        $responseA = $this->getJson('/api/v1/cashier/closing-summary');
        $responseA->assertStatus(200)
            ->assertJsonPath('data.total_revenue', 200000)
            ->assertJsonPath('data.total_transactions', 1);

        // 6. Test Cashier B (Should only see Branch B: 500,000 IDR)
        Sanctum::actingAs($cashierB);
        $responseB = $this->getJson('/api/v1/cashier/closing-summary');
        $responseB->assertStatus(200)
            ->assertJsonPath('data.total_revenue', 500000)
            ->assertJsonPath('data.total_transactions', 1);

        // 7. Test Super Admin (Should see aggregated network revenue: 700,000 IDR)
        Sanctum::actingAs($superAdmin);
        $responseAdmin = $this->getJson('/api/v1/cashier/closing-summary');
        $responseAdmin->assertStatus(200)
            ->assertJsonPath('data.total_revenue', 700000)
            ->assertJsonPath('data.total_transactions', 2);
    }
}
