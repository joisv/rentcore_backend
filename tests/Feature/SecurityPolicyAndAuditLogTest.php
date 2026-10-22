<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BranchMember;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityPolicyAndAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $owner;
    protected User $manager1;
    protected User $manager2;
    protected User $staff;
    protected AssetCategory $category;
    protected AssetModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Owner & Business
        $this->owner = User::create([
            'name' => 'Owner Utama',
            'email' => 'owner.sec@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Security Test',
            'slug' => 'rentcore-sec-test',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $ownerMember = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 2. Branch
        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'SEC',
            'name' => 'Security Branch',
            'slug' => 'security-branch',
            'is_central' => true,
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $ownerMember->id,
            'affiliate_id' => $this->branch->id,
        ]);

        // 3. Manager 1 (PIN: 111111)
        $this->manager1 = User::create([
            'name' => 'Manager Satu',
            'email' => 'mgr1@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'affiliate_id' => $this->branch->id,
            'manager_override_pin' => Hash::make('111111'),
            'is_active' => true,
        ]);

        $bm1 = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->manager1->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $bm1->id,
            'affiliate_id' => $this->branch->id,
        ]);

        // 4. Manager 2 (PIN: 222222)
        $this->manager2 = User::create([
            'name' => 'Manager Dua',
            'email' => 'mgr2@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'affiliate_admin',
            'affiliate_id' => $this->branch->id,
            'manager_override_pin' => Hash::make('222222'),
            'is_active' => true,
        ]);

        $bm2 = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->manager2->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $bm2->id,
            'affiliate_id' => $this->branch->id,
        ]);

        // 5. Staff
        $this->staff = User::create([
            'name' => 'Kasir Counter',
            'email' => 'staff.sec@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $bmStaff = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        BranchMember::create([
            'business_member_id' => $bmStaff->id,
            'affiliate_id' => $this->branch->id,
        ]);

        // 6. Master Catalog
        $this->category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Gaming Consoles',
            'slug' => 'gaming-consoles',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'name' => 'PlayStation 5 Slim',
            'slug' => 'playstation-5-slim',
            'is_active' => true,
        ]);
    }

    public function test_staff_cannot_create_or_mutate_product_catalog_or_rates(): void
    {
        $staffToken = $this->staff->createToken('staff')->plainTextToken;

        // Staff attempts to create product catalog
        $resCreate = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/products', [
                'category_id' => $this->category->id,
                'brand' => 'Nintendo',
                'name' => 'Nintendo Switch OLED',
            ]);
        $resCreate->assertStatus(403);

        // Staff attempts to mutate pricing tiers
        $resRates = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->putJson("/api/v1/products/{$this->model->id}/rates", [
                'rates' => [
                    ['duration_unit' => 'day', 'duration_value' => 1, 'price' => 10000.00]
                ]
            ]);
        $resRates->assertStatus(403);

        // Owner CAN update rates
        $resOwnerRates = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->putJson("/api/v1/products/{$this->model->id}/rates", [
                'rates' => [
                    ['duration_unit' => 'day', 'duration_value' => 1, 'price' => 150000.00]
                ]
            ]);
        $resOwnerRates->assertStatus(200);
    }

    public function test_staff_cannot_create_or_mutate_category(): void
    {
        $staffToken = $this->staff->createToken('staff')->plainTextToken;

        $resCreate = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/categories', [
                'name' => 'Hacked Category',
            ]);
        $resCreate->assertStatus(403);

        $resDelete = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->deleteJson("/api/v1/categories/{$this->category->id}");
        $resDelete->assertStatus(403);
    }

    public function test_multi_manager_pin_allows_either_manager_to_authorize_void(): void
    {
        $staffToken = $this->staff->createToken('staff')->plainTextToken;

        $method = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Customer Void Test',
            'phone' => '081299990000',
        ]);

        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'BK-VOID-001',
            'customer_id' => $customer->id,
            'created_by' => $this->staff->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addDay(),
            'duration_hours' => 24,
            'subtotal_amount' => 100000,
            'total_bill' => 100000,
            'status' => 'rented',
            'payment_status' => 'paid',
        ]);

        $tx = PaymentTransaction::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'booking_id' => $booking->id,
            'transaction_code' => 'TX-TEST-VOID-001',
            'receipt_number' => 'REC-TEST-VOID-001',
            'payment_method_id' => $method->id,
            'processed_by' => $this->staff->id,
            'amount' => 100000,
            'cash_received' => 100000,
            'cash_change' => 0,
            'type' => 'settlement',
            'status' => 'completed',
            'transacted_at' => Carbon::now(),
        ]);

        // 1. Invalid PIN rejected
        $resInvalid = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/cashier/transactions/{$tx->id}/void", [
                'reason' => 'Salah input pembayaran',
                'manager_pin' => '999999',
            ]);
        $resInvalid->assertStatus(422)
            ->assertJsonValidationErrors(['manager_pin']);

        // 2. Second Manager's PIN (222222) succeeds (proves multi-manager iteration works!)
        $resValid = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/cashier/transactions/{$tx->id}/void", [
                'reason' => 'Salah input pembayaran disetujui manager 2',
                'manager_pin' => '222222',
            ]);

        $resValid->assertStatus(200)
            ->assertJsonPath('data.status', 'void')
            ->assertJsonPath('data.void_by', 'Manager Dua');
    }

    public function test_owner_can_view_and_filter_audit_logs_while_staff_is_rejected(): void
    {
        // Generate an audit log
        \App\Services\AuditLogService::log(
            $this->owner,
            'security.test_event',
            'Business',
            $this->business->id,
            ['status' => 'verified'],
            null,
            '127.0.0.1',
            $this->branch->id
        );

        $staffToken = $this->staff->createToken('staff')->plainTextToken;
        $ownerToken = $this->owner->createToken('owner')->plainTextToken;

        // Staff rejected with 403
        $resStaff = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/audit-logs');
        $resStaff->assertStatus(403);

        // Owner can query and filter
        $resOwner = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/audit-logs?action=security.test_event');
        $resOwner->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $resOwner->json('data.data');
        $this->assertNotEmpty($data);
        $this->assertEquals('security.test_event', $data[0]['action']);
    }

    public function test_unassigned_staff_is_rejected_from_tenant_context(): void
    {
        // Create staff user without branch assignment
        $unassignedUser = User::create([
            'name' => 'Staf Gantung',
            'email' => 'unassigned@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => null,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $unassignedUser->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $token = $unassignedUser->createToken('unassigned')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/dashboard/kpi');

        $res->assertStatus(403)
            ->assertJsonPath('code', 'NO_BRANCH_ASSIGNMENT');
    }
}
