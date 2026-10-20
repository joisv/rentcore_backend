<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\BranchExpense;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchExpenseAndShiftTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branchMataram;
    protected User $owner;
    protected User $manager;
    protected User $staff;
    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Toko',
            'email' => 'owner.exp@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Expense Test',
            'slug' => 'rentcore-exp-test',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->branchMataram = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'MTR',
            'name' => 'Cabang Mataram',
            'slug' => 'cabang-mataram-exp',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager Andi',
            'email' => 'andi.mgr@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchMataram->id,
            'business_id' => $this->business->id,
            'manager_override_pin' => password_hash('123456', PASSWORD_BCRYPT),
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Kasir Budi',
            'email' => 'budi.exp@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchMataram->id,
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
            'user_id' => $this->manager->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);
    }

    public function test_staff_can_record_small_expense_auto_approved(): void
    {
        $response = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Beli Kertas Struk Kasir',
                'amount' => 50000,
                'category' => 'supplies',
                'payment_source' => 'cash_drawer',
                'notes' => '10 roll kertas thermal',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'disbursed')
            ->assertJsonPath('data.amount', 50000);

        $this->assertDatabaseHas('branch_expenses', [
            'title' => 'Beli Kertas Struk Kasir',
            'amount' => 50000,
            'status' => 'disbursed',
            'requested_by' => $this->staff->id,
        ]);
    }

    public function test_staff_cannot_record_large_expense_without_manager_pin(): void
    {
        $response = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Perbaikan Pintu Kaca Toko',
                'amount' => 350000,
                'category' => 'maintenance',
                'payment_source' => 'cash_drawer',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['manager_pin']);
    }

    public function test_staff_can_record_large_expense_with_valid_manager_pin(): void
    {
        $response = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Perbaikan Pintu Kaca Toko',
                'amount' => 350000,
                'category' => 'maintenance',
                'payment_source' => 'cash_drawer',
                'manager_pin' => '123456',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'disbursed')
            ->assertJsonPath('data.amount', 350000);

        $this->assertDatabaseHas('branch_expenses', [
            'title' => 'Perbaikan Pintu Kaca Toko',
            'approved_by' => $this->manager->id,
            'status' => 'disbursed',
        ]);
    }

    public function test_cash_drawer_shift_lifecycle_open_status_and_close(): void
    {
        // 1. Open Shift with float Rp 500.000
        $openResp = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/cashier/shift/open', [
                'opening_cash_float' => 500000,
                'notes' => 'Shift Pagi Kasir Budi',
            ]);

        $openResp->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.opening_cash_float', 500000)
            ->assertJsonPath('data.status', 'open');

        $shiftId = $openResp->json('data.id');

        // Cannot open another shift while one is already open
        $dupResp = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/cashier/shift/open', [
                'opening_cash_float' => 200000,
            ]);
        $dupResp->assertStatus(422);

        // 2. Disburse an expense during this shift: Rp 100.000
        BranchExpense::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchMataram->id,
            'expense_number' => 'EXP-TEST-001',
            'category' => 'utilities',
            'title' => 'Token Listrik',
            'amount' => 100000,
            'payment_source' => 'cash_drawer',
            'status' => 'disbursed',
            'requested_by' => $this->staff->id,
            'approved_by' => $this->staff->id,
            'disbursed_at' => Carbon::now(),
        ]);

        // 3. Check current shift status
        $statusResp = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/cashier/shift/current');

        $statusResp->assertOk()
            ->assertJsonPath('data.has_active_shift', true)
            ->assertJsonPath('data.shift.opening_cash_float', 500000)
            ->assertJsonPath('data.shift.current_cash_expenses', 100000)
            ->assertJsonPath('data.shift.expected_cash_now', 400000); // 500k - 100k = 400k

        // 4. Close Shift: If discrepancy exists without reason, validation fails
        $closeFailResp = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/cashier/shift/close', [
                'shift_id' => $shiftId,
                'actual_cash_counted' => 350000, // 50k shortage
            ]);
        $closeFailResp->assertStatus(422)
            ->assertJsonValidationErrors(['difference_reason']);

        // 5. Close Shift with explanation note succeeds
        $closeSuccessResp = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson('/api/v1/cashier/shift/close', [
                'shift_id' => $shiftId,
                'actual_cash_counted' => 350000,
                'difference_reason' => 'Kembalian selisih uang receh parkir kurir',
                'closing_notes' => 'Shift selesai dengan aman',
            ]);

        $closeSuccessResp->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_cash_total', 400000)
            ->assertJsonPath('data.actual_cash_counted', 350000)
            ->assertJsonPath('data.cash_difference', -50000);
    }
}
