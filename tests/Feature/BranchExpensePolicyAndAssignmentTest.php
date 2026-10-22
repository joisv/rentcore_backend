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
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class BranchExpensePolicyAndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branchA;
    protected Affiliate $branchB;
    protected User $owner;
    protected User $manager;
    protected User $staff;
    protected BusinessMember $staffMember;
    protected BusinessMember $managerMember;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('manager_pin_verify:biz_1:user_1');

        $this->owner = User::create([
            'name' => 'Owner RentCore',
            'email' => 'owner.policy@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Multi Policy Store',
            'slug' => 'rentcore-multi-policy',
            'owner_id' => $this->owner->id,
            'timezone' => 'Asia/Jakarta',
            'expense_pin_threshold' => 200000.00, // Custom business threshold
            'require_expense_pin' => true,
            'is_active' => true,
        ]);

        $this->branchA = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'B01',
            'name' => 'Cabang Pusat',
            'slug' => 'cabang-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchB = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'B02',
            'name' => 'Cabang Timur',
            'slug' => 'cabang-timur',
            'expense_pin_threshold_override' => 50000.00, // Stricter override on branch B
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Manager Rudi',
            'email' => 'rudi.mgr@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA->id,
            'business_id' => $this->business->id,
            'manager_override_pin' => Hash::make('654321'),
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Staff Joko',
            'email' => 'joko.staff@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->managerMember = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->manager->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->staffMember = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        \App\Models\BranchMember::create([
            'business_member_id' => $this->staffMember->id,
            'affiliate_id' => $this->branchA->id,
        ]);
    }

    public function test_custom_business_threshold_allows_expense_below_threshold(): void
    {
        // Business threshold is 200,000. An expense of 150,000 at Branch A does not require PIN.
        $response = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branchA->id)
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Beli Alat Kebersihan Toko',
                'amount' => 150000,
                'category' => 'supplies',
                'payment_source' => 'petty_cash',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.amount', 150000);
    }

    public function test_branch_override_threshold_enforces_stricter_pin_requirement(): void
    {
        // Assign staff to Branch B as well
        \App\Models\BranchMember::create([
            'business_member_id' => $this->staffMember->id,
            'affiliate_id' => $this->branchB->id,
        ]);

        // Branch B override threshold is 50,000. Expense of 75,000 requires PIN at Branch B.
        $response = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branchB->id)
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Beli Bensin Kurir',
                'amount' => 75000,
                'category' => 'operational',
                'payment_source' => 'petty_cash',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['manager_pin']);
    }

    public function test_pin_verification_rate_limiting_locks_out_after_5_failures(): void
    {
        $throttleKey = 'manager_pin_verify:biz_' . $this->business->id . ':user_' . $this->staff->id;
        RateLimiter::clear($throttleKey);

        // Submit 5 invalid PINs
        for ($i = 1; $i <= 5; $i++) {
            $resp = $this->actingAs($this->staff)
                ->withHeader('X-Business-Id', (string)$this->business->id)
                ->withHeader('X-Branch-Id', (string)$this->branchA->id)
                ->postJson('/api/v1/cashier/expenses', [
                    'title' => 'Perbaikan AC',
                    'amount' => 300000,
                    'category' => 'maintenance',
                    'payment_source' => 'cash_drawer',
                    'manager_pin' => '000000',
                ]);

            if ($i < 5) {
                $resp->assertStatus(422);
            }
        }

        // 6th attempt must return 429 Too Many Requests (Rate Limited)
        $lockedResp = $this->actingAs($this->staff)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->withHeader('X-Branch-Id', (string)$this->branchA->id)
            ->postJson('/api/v1/cashier/expenses', [
                'title' => 'Perbaikan AC',
                'amount' => 300000,
                'category' => 'maintenance',
                'payment_source' => 'cash_drawer',
                'manager_pin' => '654321', // Even correct PIN is blocked during lockout
            ]);

        $lockedResp->assertStatus(429)
            ->assertJsonPath('code', 'PIN_RATE_LIMITED');
    }

    public function test_owner_can_set_and_revoke_manager_pin_via_api(): void
    {
        // 1. Owner sets 6-digit PIN
        $setResp = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->putJson("/api/v1/businesses/{$this->business->id}/members/{$this->staffMember->id}/pin", [
                'pin' => '998877',
            ]);

        $setResp->assertOk()
            ->assertJsonPath('success', true);

        $freshStaff = $this->staff->fresh();
        $this->assertTrue(Hash::check('998877', $freshStaff->manager_override_pin));

        // 2. Owner revokes PIN
        $revokeResp = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->deleteJson("/api/v1/businesses/{$this->business->id}/members/{$this->staffMember->id}/pin");

        $revokeResp->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($this->staff->fresh()->manager_override_pin);
    }

    public function test_owner_can_void_expense_and_audit_is_recorded(): void
    {
        $expense = BranchExpense::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchA->id,
            'expense_number' => 'EXP-VOID-TEST-01',
            'category' => 'supplies',
            'title' => 'Salah Input Kertas',
            'amount' => 500000,
            'payment_source' => 'cash_drawer',
            'status' => 'disbursed',
            'requested_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
            'disbursed_at' => Carbon::now(),
        ]);

        $voidResp = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/cashier/expenses/{$expense->id}/void", [
                'reason' => 'Salah nominal, harusnya Rp 50.000 bukan Rp 500.000',
            ]);

        $voidResp->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'void')
            ->assertJsonPath('data.void_reason', 'Salah nominal, harusnya Rp 50.000 bukan Rp 500.000');

        $this->assertDatabaseHas('branch_expenses', [
            'id' => $expense->id,
            'status' => 'void',
            'void_by' => $this->owner->id,
        ]);
    }

    public function test_staff_reassignment_fails_if_active_shift_is_open(): void
    {
        // Open active shift for staff at Branch A
        CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchA->id,
            'shift_number' => 'SHIFT-TEST-001',
            'opened_by' => $this->staff->id,
            'opened_at' => Carbon::now(),
            'opening_cash_float' => 100000,
            'status' => 'open',
        ]);

        // Attempt reassignment from Branch A to Branch B
        $reassignResp = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/businesses/{$this->business->id}/members/{$this->staffMember->id}/reassign-branch", [
                'from_branch_id' => $this->branchA->id,
                'to_branch_id' => $this->branchB->id,
            ]);

        $reassignResp->assertStatus(422)
            ->assertJsonValidationErrors(['from_branch_id']);
    }

    public function test_staff_reassignment_succeeds_when_no_active_shift(): void
    {
        $reassignResp = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/businesses/{$this->business->id}/members/{$this->staffMember->id}/reassign-branch", [
                'from_branch_id' => $this->branchA->id,
                'to_branch_id' => $this->branchB->id,
                'reason' => 'Dipindahtugaskan untuk pembukaan cabang timur',
            ]);

        $reassignResp->assertOk()
            ->assertJsonPath('success', true);

        // Verify Branch A assignment was removed and Branch B assignment exists
        $this->assertDatabaseMissing('branch_members', [
            'business_member_id' => $this->staffMember->id,
            'affiliate_id' => $this->branchA->id,
        ]);

        $this->assertDatabaseHas('branch_members', [
            'business_member_id' => $this->staffMember->id,
            'affiliate_id' => $this->branchB->id,
        ]);
    }

    public function test_timezone_aware_closing_summary_handles_wib_boundaries(): void
    {
        // Create an expense disbursed today in Jakarta timezone
        $nowJakarta = Carbon::now('Asia/Jakarta');
        $expense = BranchExpense::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branchA->id,
            'expense_number' => 'EXP-TZ-TEST-01',
            'category' => 'operational',
            'title' => 'Biaya Kurir Sore',
            'amount' => 45000,
            'payment_source' => 'cash_drawer',
            'status' => 'disbursed',
            'requested_by' => $this->staff->id,
            'approved_by' => $this->manager->id,
            'disbursed_at' => Carbon::now(), // In UTC
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson("/api/v1/cashier/closing-summary?branch_id={$this->branchA->id}&date={$nowJakarta->toDateString()}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.timezone', 'Asia/Jakarta')
            ->assertJsonPath('data.total_expenses', 45000)
            ->assertJsonPath('data.cash_drawer_expenses', 45000);
    }
}
