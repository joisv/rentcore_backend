<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BranchMember;
use App\Models\CashDrawerShift;
use App\Models\Customer;
use App\Models\InventoryTransfer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\StaffResignationRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffResignationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected User $otherUser;
    protected Business $business;
    protected Affiliate $branch;
    protected BusinessMember $staffMembership;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Owner & Business
        $this->owner = User::create([
            'name' => 'Owner Bos Rental',
            'email' => 'owner.bos@tenant.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Kamera Dewata Rental',
            'slug' => 'kamera-dewata-rental',
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'KDR1',
            'name' => 'Kamera Dewata Seminyak',
            'slug' => 'kamera-dewata-seminyak',
            'is_central' => true,
            'is_active' => true,
        ]);

        $ownerMembership = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);

        BranchMember::create([
            'business_member_id' => $ownerMembership->id,
            'affiliate_id' => $this->branch->id,
        ]);

        // 2. Create Staff
        $this->staff = User::create([
            'name' => 'Staff Agus Pratama',
            'email' => 'agus.staff@gmail.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->staffMembership = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);

        BranchMember::create([
            'business_member_id' => $this->staffMembership->id,
            'affiliate_id' => $this->branch->id,
        ]);

        // 3. Create Other unrelated user
        $this->otherUser = User::create([
            'name' => 'Stranger User',
            'email' => 'stranger@example.com',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_staff_can_submit_valid_resignation_request()
    {
        $token = $this->staff->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests", [
            'reason' => 'Pindah domisili ke luar kota.',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Permohonan resign berhasil diajukan dan menunggu persetujuan Owner.',
            ]);

        $this->assertDatabaseHas('staff_resignation_requests', [
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'business_member_id' => $this->staffMembership->id,
            'status' => 'pending',
            'reason' => 'Pindah domisili ke luar kota.',
        ]);

        // Membership remains active while pending
        $this->assertTrue($this->staffMembership->fresh()->is_active);
    }

    public function test_owner_cannot_submit_resignation_request_for_own_business()
    {
        $token = $this->owner->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests", [
            'reason' => 'Ingin berhenti menjadi owner.',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Owner bisnis tidak dapat mengajukan resign dari bisnis miliknya sendiri.',
            ]);
    }

    public function test_staff_cannot_submit_duplicate_pending_resignation()
    {
        $token = $this->staff->createToken('test')->plainTextToken;

        StaffResignationRequest::create([
            'business_id' => $this->business->id,
            'business_member_id' => $this->staffMembership->id,
            'user_id' => $this->staff->id,
            'status' => 'pending',
            'reason' => 'First submission',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests", [
            'reason' => 'Second submission',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Anda masih memiliki permohonan resign yang sedang menunggu persetujuan Owner.',
            ]);
    }

    public function test_staff_can_cancel_own_pending_resignation()
    {
        $token = $this->staff->createToken('test')->plainTextToken;

        $request = StaffResignationRequest::create([
            'business_id' => $this->business->id,
            'business_member_id' => $this->staffMembership->id,
            'user_id' => $this->staff->id,
            'status' => 'pending',
            'reason' => 'Khilaf mau resign',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests/{$request->id}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Permohonan resign berhasil dibatalkan. Anda tetap aktif sebagai staf.',
            ]);

        $this->assertEquals('cancelled', $request->fresh()->status);
        $this->assertTrue($this->staffMembership->fresh()->is_active);
    }

    public function test_staff_cannot_cancel_another_users_resignation()
    {
        $token = $this->otherUser->createToken('test')->plainTextToken;

        $request = StaffResignationRequest::create([
            'business_id' => $this->business->id,
            'business_member_id' => $this->staffMembership->id,
            'user_id' => $this->staff->id,
            'status' => 'pending',
            'reason' => 'Resign note',
        ]);

        // stranger tries to cancel staff's request
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests/{$request->id}/cancel");

        // Fails with 403
        $response->assertStatus(403);
        $this->assertEquals('pending', $request->fresh()->status);
    }

    public function test_unauthorized_user_cannot_approve_or_reject_resignation()
    {
        $token = $this->staff->createToken('test')->plainTextToken;

        $request = StaffResignationRequest::create([
            'business_id' => $this->business->id,
            'business_member_id' => $this->staffMembership->id,
            'user_id' => $this->staff->id,
            'status' => 'pending',
            'reason' => 'Need approval',
        ]);

        // Staff tries to approve own resignation
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests/{$request->id}/approve");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Hanya Owner bisnis yang berwenang menyetujui permohonan resign staf.',
            ]);
    }

    public function test_owner_can_reject_resignation_preserving_active_membership()
    {
        $token = $this->owner->createToken('test')->plainTextToken;

        $request = StaffResignationRequest::create([
            'business_id' => $this->business->id,
            'business_member_id' => $this->staffMembership->id,
            'user_id' => $this->staff->id,
            'status' => 'pending',
            'reason' => 'Mau pindah',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests/{$request->id}/reject", [
            'decision_notes' => 'Gaji dinaikkan, jangan resign ya.',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Permohonan resign ditolak. Keanggotaan staf tetap aktif.',
            ]);

        $this->assertEquals('rejected', $request->fresh()->status);
        $this->assertEquals('Gaji dinaikkan, jangan resign ya.', $request->fresh()->decision_notes);
        $this->assertEquals($this->owner->id, $request->fresh()->decided_by);
        $this->assertNotNull($request->fresh()->decided_at);

        // Membership remains active
        $this->assertTrue($this->staffMembership->fresh()->is_active);
    }

    public function test_owner_cannot_approve_resignation_if_staff_has_open_cashier_shift()
    {
        $token = $this->owner->createToken('test')->plainTextToken;

        $request = StaffResignationRequest::create([
            'business_id' => $this->business->id,
            'business_member_id' => $this->staffMembership->id,
            'user_id' => $this->staff->id,
            'status' => 'pending',
            'reason' => 'Resign segera',
        ]);

        // Create an open shift for the staff
        CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_number' => 'SHF-TEST-001',
            'opened_by' => $this->staff->id,
            'opened_at' => Carbon::now(),
            'opening_cash_float' => 100000.00,
            'status' => 'open',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests/{$request->id}/approve");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shift']);

        $this->assertEquals('pending', $request->fresh()->status);
        $this->assertTrue($this->staffMembership->fresh()->is_active);
    }

    public function test_owner_approves_resignation_revoking_access_and_preserving_identity_and_history()
    {
        // 1. Create historical records created by staff
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Pelanggan Setia',
            'phone' => '08123456789',
        ]);

        $booking = Booking::create([
            'business_id' => $this->business->id,
            'booking_code' => 'BK-HISTORICAL-01',
            'affiliate_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'created_by' => $this->staff->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addDay(),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 100000.00,
            'total_bill' => 100000.00,
            'payment_status' => 'paid',
        ]);

        $payMethod = PaymentMethod::create(['code' => 'CASH-01', 'name' => 'Tunai', 'is_active' => true]);
        $payment = PaymentTransaction::create([
            'business_id' => $this->business->id,
            'transaction_code' => 'TRX-HISTORICAL-01',
            'receipt_number' => 'RCP-01',
            'booking_id' => $booking->id,
            'payment_method_id' => $payMethod->id,
            'processed_by' => $this->staff->id,
            'type' => 'settlement',
            'amount' => 100000.00,
            'transacted_at' => Carbon::now(),
        ]);

        // 2. Staff also owns their own independent business!
        $staffOwnBiz = Business::create([
            'name' => 'Staff Independent Business Budi',
            'slug' => 'staff-independent-business-budi',
            'owner_id' => $this->staff->id,
            'is_active' => true,
        ]);
        $staffOwnBizMember = BusinessMember::create([
            'business_id' => $staffOwnBiz->id,
            'user_id' => $this->staff->id,
            'role' => 'owner',
            'is_active' => true,
            'joined_at' => Carbon::now(),
        ]);

        // 3. Staff submits resignation from owner's business
        $request = StaffResignationRequest::create([
            'business_id' => $this->business->id,
            'business_member_id' => $this->staffMembership->id,
            'user_id' => $this->staff->id,
            'status' => 'pending',
            'reason' => 'Mau fokus ke usaha sendiri',
        ]);

        // 4. Owner approves resignation
        $ownerToken = $this->owner->createToken('test')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => "Bearer $ownerToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resignation-requests/{$request->id}/approve", [
            'decision_notes' => 'Sukses untuk usahanya.',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Permohonan resign disetujui. Akses staf ke bisnis rental dan cabang telah dicabut.',
            ]);

        // 5. Verification: Request is approved
        $this->assertEquals('approved', $request->fresh()->status);
        $this->assertEquals($this->owner->id, $request->fresh()->decided_by);

        // 6. Verification: Staff membership in employer's business is deactivated & branch cleared
        $this->assertFalse($this->staffMembership->fresh()->is_active);
        $this->assertDatabaseMissing('branch_members', [
            'business_member_id' => $this->staffMembership->id,
        ]);

        // 7. Verification: TenantContextMiddleware immediately blocks staff from former employer
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $staffToken = $this->staff->createToken('test')->plainTextToken;
        $blockedResponse = $this->withHeaders([
            'Authorization' => "Bearer $staffToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->getJson("/api/v1/businesses/{$this->business->id}/members");

        $blockedResponse->assertStatus(403)
            ->assertJson([
                'code' => 'BUSINESS_ACCESS_DENIED',
            ]);

        // 8. Verification: Staff's own independent business is 100% active and accessible!
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->assertTrue($staffOwnBizMember->fresh()->is_active);
        $ownBizResponse = $this->withHeaders([
            'Authorization' => "Bearer $staffToken",
            'X-Business-Id' => (string) $staffOwnBiz->id,
        ])->getJson("/api/v1/businesses/{$staffOwnBiz->id}");

        $ownBizResponse->assertStatus(200);

        // 9. Verification: Global account identity and credentials remain active
        $this->assertTrue($this->staff->fresh()->is_active);

        // 10. Verification: Historical bookings and payments retain attribution
        $this->assertEquals($this->staff->id, $booking->fresh()->created_by);
        $this->assertEquals($this->staff->id, $payment->fresh()->processed_by);
    }

    public function test_staff_resign_alias_blocks_open_shift_and_owner_notification_review_updates_status(): void
    {
        $this->staff->update(['affiliate_id' => $this->branch->id]);

        // Open shift blocks resign submission
        $shift = CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'opened_by' => $this->staff->id,
            'shift_number' => 'SHF-RESIGN-001',
            'status' => 'open',
            'opened_at' => Carbon::now(),
            'opening_cash_float' => 100000,
        ]);

        $staffToken = $this->staff->createToken('staff')->plainTextToken;

        $statusEmpty = $this->withHeaders([
            'Authorization' => "Bearer $staffToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->getJson("/api/v1/businesses/{$this->business->id}/resign-status");
        $statusEmpty->assertStatus(200)->assertJsonPath('data', null);

        $blockedResign = $this->withHeaders([
            'Authorization' => "Bearer $staffToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resign", [
            'reason' => 'Ingin fokus usaha sendiri',
        ]);
        $blockedResign->assertStatus(422)
            ->assertJsonPath('message', 'Tutup shift kasir aktif Anda terlebih dahulu sebelum mengajukan resign.');

        // Close shift -> resign succeeds & dispatches Owner notification
        $shift->update(['status' => 'closed', 'closed_at' => Carbon::now()]);

        $resignResp = $this->withHeaders([
            'Authorization' => "Bearer $staffToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resign", [
            'reason' => 'Ingin fokus usaha sendiri',
        ]);
        $resignResp->assertStatus(201);
        $requestId = $resignResp->json('data.id');

        // Verify resign-status reflects pending
        $statusPending = $this->withHeaders([
            'Authorization' => "Bearer $staffToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->getJson("/api/v1/businesses/{$this->business->id}/resign-status");
        $statusPending->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reason', 'Ingin fokus usaha sendiri');

        // Verify Owner notification payload created cleanly
        $ownerNotif = \App\Models\TenantNotification::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->where('user_id', $this->owner->id)
            ->where('type', 'staff.resignation_submitted')
            ->first();
        $this->assertNotNull($ownerNotif);
        $this->assertEquals($requestId, $ownerNotif->action_payload['resignation_request_id']);
        $this->assertEquals('pending', $ownerNotif->action_payload['status']);
        $this->assertEquals($this->branch->name, $ownerNotif->action_payload['branch_name']);

        // Owner approves via /api/v1/resignation-requests/{id}/approve
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $approveResp = $this->withHeaders([
            'Authorization' => "Bearer $ownerToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/resignation-requests/{$requestId}/approve");
        $approveResp->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Staff membership deactivated, left_at set, legacy affiliate_id cleared
        $freshMember = $this->staffMembership->fresh();
        $this->assertFalse($freshMember->is_active);
        $this->assertNotNull($freshMember->left_at);
        $this->assertNull($this->staff->fresh()->affiliate_id);

        // Owner notification updated to approved and marked read
        $freshOwnerNotif = $ownerNotif->fresh();
        $this->assertEquals('approved', $freshOwnerNotif->action_payload['status']);
        $this->assertNotNull($freshOwnerNotif->read_at);

        // Staff notification created with approved decision
        $staffNotif = \App\Models\TenantNotification::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->where('user_id', $this->staff->id)
            ->where('type', 'staff.resignation_approved')
            ->first();
        $this->assertNotNull($staffNotif);
        $this->assertEquals('approved', $staffNotif->action_payload['status']);
    }

    public function test_owner_reject_by_id_accepts_rejection_reason_and_updates_notifications(): void
    {
        $staffToken = $this->staff->createToken('staff')->plainTextToken;
        $resignResp = $this->withHeaders([
            'Authorization' => "Bearer $staffToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/businesses/{$this->business->id}/resign", [
            'reason' => 'Butuh libur panjang',
        ]);
        $requestId = $resignResp->json('data.id');

        $this->flushHeaders();
        app('auth')->forgetGuards();
        $ownerToken = $this->owner->createToken('owner')->plainTextToken;

        $rejectResp = $this->withHeaders([
            'Authorization' => "Bearer $ownerToken",
            'X-Business-Id' => (string) $this->business->id,
        ])->postJson("/api/v1/resignation-requests/{$requestId}/reject", [
            'rejection_reason' => 'Periode high season akhir bulan',
        ]);
        $rejectResp->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_notes', 'Periode high season akhir bulan');

        $ownerNotif = \App\Models\TenantNotification::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->where('user_id', $this->owner->id)
            ->where('type', 'staff.resignation_submitted')
            ->first();
        $this->assertEquals('rejected', $ownerNotif->action_payload['status']);
        $this->assertNotNull($ownerNotif->read_at);

        $staffNotif = \App\Models\TenantNotification::withoutGlobalScopes()
            ->where('business_id', $this->business->id)
            ->where('user_id', $this->staff->id)
            ->where('type', 'staff.resignation_rejected')
            ->first();
        $this->assertNotNull($staffNotif);
        $this->assertEquals('rejected', $staffNotif->action_payload['status']);
        $this->assertStringContainsString('Periode high season akhir bulan', $staffNotif->body);
    }
}
