<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_privacy_policy_and_account_deletion_pages_render_without_auth(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertSee('Kebijakan Privasi')
            ->assertSee('BLUETOOTH_SCAN')
            ->assertSee('neverForLocation');

        $this->get('/account-deletion')
            ->assertOk()
            ->assertSee('Permintaan Penghapusan Akun')
            ->assertSee('7 hari');
    }

    public function test_account_deletion_blocks_sole_owner_of_active_business(): void
    {
        $owner = User::create([
            'name' => 'Pemilik Utama',
            'email' => 'owner.solo@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $business = Business::create([
            'name' => 'Kamera Jaya',
            'slug' => 'kamera-jaya',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $response = $this->from('/account-deletion')->post('/account-deletion', [
            'email' => 'owner.solo@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/account-deletion');
        $response->assertSessionHasErrors('ownership');
        $this->assertTrue((bool) $owner->fresh()->is_active);
    }

    public function test_account_deletion_deactivates_anonymizes_and_revokes_tokens_for_eligible_user(): void
    {
        $owner = User::create([
            'name' => 'Pemilik Tetap',
            'email' => 'keep.owner@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $staff = User::create([
            'name' => 'Staf Kasir',
            'email' => 'staff.delete@example.com',
            'phone' => '081234567890',
            'google_id' => 'google-sub-123',
            'password' => Hash::make('secret123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $business = Business::create([
            'name' => 'Kamera Nusantara',
            'slug' => 'kamera-nusantara',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $staffMembership = BusinessMember::create([
            'business_id' => $business->id,
            'user_id' => $staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $staff->createToken('mobile-test');
        $this->assertCount(1, $staff->tokens()->get());

        $response = $this->from('/account-deletion')->post('/account-deletion', [
            'email' => 'staff.delete@example.com',
            'password' => 'secret123',
            'reason' => 'Sudah berhenti bekerja.',
        ]);

        $response->assertRedirect('/account-deletion');
        $response->assertSessionHas('deletion_success');

        $fresh = $staff->fresh();
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertStringStartsWith('Deleted User #', $fresh->name);
        $this->assertSame('deleted_' . $staff->id . '@deleted.rentcore.local', $fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->google_id);
        $this->assertCount(0, $fresh->tokens()->get());
        $this->assertFalse((bool) $staffMembership->fresh()->is_active);
    }
}
