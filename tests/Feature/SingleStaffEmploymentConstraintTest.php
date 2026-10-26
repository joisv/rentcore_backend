<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BranchMember;
use App\Models\StaffResignationRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingleStaffEmploymentConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;
    protected User $ownerC;
    protected User $budi;
    protected Business $bizA;
    protected Business $bizB;
    protected Business $bizC;
    protected Affiliate $branchA1;
    protected Affiliate $branchA2;
    protected Affiliate $branchC;

    protected function setUp(): void
    {
        parent::setUp();

        // Owner A & Business A
        $this->ownerA = User::create([
            'name' => 'Owner Ani',
            'email' => 'ani@business-a.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->bizA = Business::create([
            'name' => 'Business A',
            'slug' => 'business-a',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);

        $this->branchA1 = Affiliate::create([
            'business_id' => $this->bizA->id,
            'code' => 'BA1',
            'name' => 'Business A Cabang 1',
            'slug' => 'business-a-cabang-1',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->bizA->id,
            'code' => 'BA2',
            'name' => 'Business A Cabang 2',
            'slug' => 'business-a-cabang-2',
            'is_central' => false,
            'is_active' => true,
        ]);

        $memA = BusinessMember::create([
            'business_id' => $this->bizA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create(['business_member_id' => $memA->id, 'affiliate_id' => $this->branchA1->id]);

        // Owner C & Business C
        $this->ownerC = User::create([
            'name' => 'Owner Citra',
            'email' => 'citra@business-c.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->bizC = Business::create([
            'name' => 'Business C',
            'slug' => 'business-c',
            'owner_id' => $this->ownerC->id,
            'is_active' => true,
        ]);

        $this->branchC = Affiliate::create([
            'business_id' => $this->bizC->id,
            'code' => 'BC1',
            'name' => 'Business C Pusat',
            'slug' => 'business-c-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $memC = BusinessMember::create([
            'business_id' => $this->bizC->id,
            'user_id' => $this->ownerC->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create(['business_member_id' => $memC->id, 'affiliate_id' => $this->branchC->id]);

        // Budi: owns Business B
        $this->budi = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi.santoso@gmail.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->bizB = Business::create([
            'name' => 'Business Budi Mandiri',
            'slug' => 'business-budi-mandiri',
            'owner_id' => $this->budi->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->bizB->id,
            'user_id' => $this->budi->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
    }

    public function test_budi_can_own_business_b_and_join_business_a_as_staff()
    {
        $ownerAToken = $this->ownerA->createToken('test')->plainTextToken;

        // Ani invites Budi to Business A as staff with access to Branch A1
        $response = $this->withHeaders([
            'Authorization' => "Bearer $ownerAToken",
            'X-Business-Id' => (string) $this->bizA->id,
        ])->postJson("/api/v1/businesses/{$this->bizA->id}/members", [
            'email' => $this->budi->email,
            'name' => $this->budi->name,
            'role' => 'staff',
            'branch_ids' => [$this->branchA1->id],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('business_members', [
            'business_id' => $this->bizA->id,
            'user_id' => $this->budi->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        // Budi's ownership of Business B is unchanged
        $this->assertDatabaseHas('business_members', [
            'business_id' => $this->bizB->id,
            'user_id' => $this->budi->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    public function test_budi_can_be_assigned_to_multiple_branches_of_business_a()
    {
        $ownerAToken = $this->ownerA->createToken('test')->plainTextToken;

        // Ani invites Budi with access to both Branch A1 and Branch A2
        $response = $this->withHeaders([
            'Authorization' => "Bearer $ownerAToken",
            'X-Business-Id' => (string) $this->bizA->id,
        ])->postJson("/api/v1/businesses/{$this->bizA->id}/members", [
            'email' => $this->budi->email,
            'name' => $this->budi->name,
            'role' => 'staff',
            'branch_ids' => [$this->branchA1->id, $this->branchA2->id],
        ]);

        $response->assertStatus(201);

        $budiMember = BusinessMember::where('business_id', $this->bizA->id)
            ->where('user_id', $this->budi->id)
            ->first();

        $this->assertCount(2, $budiMember->branchMembers);
    }

    public function test_budi_cannot_be_active_staff_in_business_c_while_active_in_business_a()
    {
        // 1. Budi joins Business A as staff
        $ownerAToken = $this->ownerA->createToken('test')->plainTextToken;
        $this->withHeaders([
            'Authorization' => "Bearer $ownerAToken",
            'X-Business-Id' => (string) $this->bizA->id,
        ])->postJson("/api/v1/businesses/{$this->bizA->id}/members", [
            'email' => $this->budi->email,
            'role' => 'staff',
            'branch_ids' => [$this->branchA1->id],
        ])->assertStatus(201);

        // 2. Citra tries to invite Budi to Business C as staff
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $ownerCToken = $this->ownerC->createToken('test')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => "Bearer $ownerCToken",
            'X-Business-Id' => (string) $this->bizC->id,
        ])->postJson("/api/v1/businesses/{$this->bizC->id}/members", [
            'email' => $this->budi->email,
            'role' => 'staff',
            'branch_ids' => [$this->branchC->id],
        ]);

        // 3. Rejected due to single staff employment constraint
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('business_members', [
            'business_id' => $this->bizC->id,
            'user_id' => $this->budi->id,
        ]);
    }

    public function test_budi_can_join_business_c_after_resignation_from_business_a_is_approved()
    {
        // 1. Budi joins Business A as staff
        $budiMember = BusinessMember::create([
            'business_id' => $this->bizA->id,
            'user_id' => $this->budi->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);
        BranchMember::create(['business_member_id' => $budiMember->id, 'affiliate_id' => $this->branchA1->id]);

        // 2. Resignation submitted & approved in Business A
        $budiMember->update(['is_active' => false]);
        BranchMember::where('business_member_id', $budiMember->id)->delete();

        // 3. Now Citra invites Budi to Business C
        $ownerCToken = $this->ownerC->createToken('test')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => "Bearer $ownerCToken",
            'X-Business-Id' => (string) $this->bizC->id,
        ])->postJson("/api/v1/businesses/{$this->bizC->id}/members", [
            'email' => $this->budi->email,
            'role' => 'staff',
            'branch_ids' => [$this->branchC->id],
        ]);

        // 4. Successfully joins Business C!
        $response->assertStatus(201);
        $this->assertDatabaseHas('business_members', [
            'business_id' => $this->bizC->id,
            'user_id' => $this->budi->id,
            'role' => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_previously_resigned_staff_can_be_reactivated_in_same_business()
    {
        // 1. Budi was previously staff in Business A but resigned
        $budiMember = BusinessMember::create([
            'business_id' => $this->bizA->id,
            'user_id' => $this->budi->id,
            'role' => 'staff',
            'is_active' => false,
            'joined_at' => Carbon::now()->subMonths(3),
        ]);

        // 2. Owner A re-hires Budi
        $ownerAToken = $this->ownerA->createToken('test')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => "Bearer $ownerAToken",
            'X-Business-Id' => (string) $this->bizA->id,
        ])->postJson("/api/v1/businesses/{$this->bizA->id}/members", [
            'email' => $this->budi->email,
            'role' => 'admin',
            'branch_ids' => [$this->branchA2->id],
        ]);

        $response->assertStatus(201);

        // Budi's membership is reactivated with updated role and branch
        $this->assertTrue($budiMember->fresh()->is_active);
        $this->assertEquals('admin', $budiMember->fresh()->role);
        $this->assertDatabaseHas('branch_members', [
            'business_member_id' => $budiMember->id,
            'affiliate_id' => $this->branchA2->id,
        ]);
    }
}
