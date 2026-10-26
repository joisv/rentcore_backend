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
use App\Models\BranchMember;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase1SecurityAndCollisionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;
    protected User $ownerB;
    protected Business $bizA;
    protected Business $bizB;
    protected Affiliate $branchA;
    protected Affiliate $branchB;
    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->cashMethod = PaymentMethod::create(['code' => 'CASH', 'name' => 'Tunai', 'is_active' => true]);

        // Tenant A
        $this->ownerA = User::create([
            'name' => 'Owner A',
            'email' => 'owner.a@test.com',
            'password' => bcrypt('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);
        $this->bizA = Business::create([
            'name' => 'Business A',
            'slug' => 'biz-a',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);
        $this->branchA = Affiliate::create([
            'business_id' => $this->bizA->id,
            'code' => 'BZA',
            'name' => 'Branch A',
            'slug' => 'branch-a',
            'is_central' => true,
            'is_active' => true,
        ]);
        $memA = BusinessMember::create([
            'business_id' => $this->bizA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        BranchMember::create([
            'business_member_id' => $memA->id,
            'affiliate_id' => $this->branchA->id,
        ]);

        // Tenant B
        $this->ownerB = User::create([
            'name' => 'Owner B',
            'email' => 'owner.b@test.com',
            'password' => bcrypt('password'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);
        $this->bizB = Business::create([
            'name' => 'Business B',
            'slug' => 'biz-b',
            'owner_id' => $this->ownerB->id,
            'is_active' => true,
        ]);
        $this->branchB = Affiliate::create([
            'business_id' => $this->bizB->id,
            'code' => 'BZB',
            'name' => 'Branch B',
            'slug' => 'branch-b',
            'is_central' => true,
            'is_active' => true,
        ]);
        $memB = BusinessMember::create([
            'business_id' => $this->bizB->id,
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        BranchMember::create([
            'business_member_id' => $memB->id,
            'affiliate_id' => $this->branchB->id,
        ]);
    }

    public function test_two_independent_tenants_can_process_payments_without_collision(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;
        $tokenB = $this->ownerB->createToken('test')->plainTextToken;

        // Customer & Booking in Business A
        $custA = Customer::create(['business_id' => $this->bizA->id, 'name' => 'Cust A', 'phone' => '081111']);
        $bookingA = Booking::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $this->branchA->id,
            'customer_id' => $custA->id,
            'created_by' => $this->ownerA->id,
            'booking_code' => 'RC-A-0001',
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'total_bill' => 200000.00,
            'payment_status' => 'unpaid',
            'status' => 'rented',
        ]);

        // Customer & Booking in Business B
        $custB = Customer::create(['business_id' => $this->bizB->id, 'name' => 'Cust B', 'phone' => '082222']);
        $bookingB = Booking::create([
            'business_id' => $this->bizB->id,
            'affiliate_id' => $this->branchB->id,
            'customer_id' => $custB->id,
            'created_by' => $this->ownerB->id,
            'booking_code' => 'RC-B-0001',
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'total_bill' => 200000.00,
            'payment_status' => 'unpaid',
            'status' => 'rented',
        ]);

        // Tenant A processes payment 1
        $respA = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $bookingA->id,
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 200000.00,
                'cash_received' => 200000.00,
            ]);

        $respA->assertStatus(201);
        $txCodeA = $respA->json('data.payment.transaction_code');
        $this->assertNotEmpty($txCodeA);

        \Illuminate\Support\Facades\Auth::forgetGuards();

        // Tenant B processes payment 1 (Previously crashed on duplicate transaction_code key!)
        $respB = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $bookingB->id,
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 200000.00,
                'cash_received' => 200000.00,
            ]);

        $respB->assertStatus(201);
        $txCodeB = $respB->json('data.payment.transaction_code');
        $this->assertNotEmpty($txCodeB);
        $this->assertNotEquals($txCodeA, $txCodeB);
    }

    public function test_same_booking_can_receive_multiple_payments_without_receipt_collision(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;

        $cust = Customer::create(['business_id' => $this->bizA->id, 'name' => 'Cust Multi', 'phone' => '083333']);
        $booking = Booking::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $this->branchA->id,
            'customer_id' => $cust->id,
            'created_by' => $this->ownerA->id,
            'booking_code' => 'RC-M-0001',
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'total_bill' => 500000.00,
            'payment_status' => 'unpaid',
            'status' => 'rented',
        ]);

        // Payment 1: DP 200.000
        $resp1 = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $booking->id,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'settlement',
                'amount' => 200000.00,
                'cash_received' => 200000.00,
            ]);

        $resp1->assertStatus(201);
        $rec1 = $resp1->json('data.payment.receipt_number');

        // Payment 2: Settlement remaining 300.000 (Previously crashed because receipt_number reused booking->id)
        $resp2 = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $booking->id,
                'payment_method_id' => $this->cashMethod->id,
                'payment_type' => 'settlement',
                'amount' => 300000.00,
                'cash_received' => 300000.00,
            ]);

        $resp2->assertStatus(201);
        $rec2 = $resp2->json('data.payment.receipt_number');

        $this->assertNotEmpty($rec1);
        $this->assertNotEmpty($rec2);
        $this->assertNotEquals($rec1, $rec2);
    }

    public function test_customer_identity_document_stored_in_private_disk_and_served_under_tenant_auth(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;
        $tokenB = $this->ownerB->createToken('test')->plainTextToken;

        $cust = Customer::create(['business_id' => $this->bizA->id, 'name' => 'Cust Identity', 'phone' => '084444']);

        $fakeFile = UploadedFile::fake()->image('ktp_ahmad.jpg', 600, 400);

        // Upload identity photo
        $uploadResp = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->postJson("/api/v1/customers/{$cust->id}/identity-document", [
                'identity_type' => 'KTP',
                'identity_number' => '3510091234567890',
                'photo' => $fakeFile,
            ]);

        $uploadResp->assertStatus(200);
        $filePath = $uploadResp->json('data.file_path');

        // Assert file is NOT on public disk, but IS on local private disk
        $this->assertFalse(Storage::disk('public')->exists($filePath));
        $this->assertTrue(Storage::disk('local')->exists($filePath));

        // Tenant A can retrieve the document securely
        $getRespA = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->get("/api/v1/customers/{$cust->id}/identity-document");

        $getRespA->assertStatus(200);

        \Illuminate\Support\Facades\Auth::forgetGuards();

        // Tenant B is forbidden from accessing Tenant A's customer document (404 due to fail-closed TenantScope)
        $getRespB = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Business-Id', (string)$this->bizB->id)
            ->get("/api/v1/customers/{$cust->id}/identity-document");

        $getRespB->assertStatus(404);
    }

    public function test_idempotent_payment_returns_existing_record_gracefully(): void
    {
        $tokenA = $this->ownerA->createToken('test')->plainTextToken;
        $cust = Customer::create(['business_id' => $this->bizA->id, 'name' => 'Cust Idempotent', 'phone' => '085555']);
        $booking = Booking::create([
            'business_id' => $this->bizA->id,
            'affiliate_id' => $this->branchA->id,
            'customer_id' => $cust->id,
            'created_by' => $this->ownerA->id,
            'booking_code' => 'RC-IDEMP-01',
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addHours(24),
            'duration_hours' => 24,
            'total_bill' => 100000.00,
            'payment_status' => 'unpaid',
            'status' => 'rented',
        ]);

        $idempotencyKey = 'test-idemp-key-12345';

        $resp1 = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $booking->id,
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 100000.00,
                'cash_received' => 100000.00,
            ]);

        $resp1->assertStatus(201);
        $txId = $resp1->json('data.payment.id');

        // Repeat request with same idempotency key
        $resp2 = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->withHeader('X-Business-Id', (string)$this->bizA->id)
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/cashier/pay', [
                'booking_id' => $booking->id,
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 100000.00,
                'cash_received' => 100000.00,
            ]);

        $resp2->assertStatus(200);
        $this->assertTrue($resp2->json('data.is_idempotent'));
        $this->assertEquals($txId, $resp2->json('data.transaction_id'));
    }
}
