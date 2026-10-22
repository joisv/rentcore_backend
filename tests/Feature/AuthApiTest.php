<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'wrong@rentcore.id',
            'password' => 'invalid-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_succeeds_and_returns_token_and_user_profile(): void
    {
        $affiliate = Affiliate::create([
            'code' => 'GNT',
            'name' => 'Genteng Central Hub',
            'slug' => 'genteng-central-hub',
            'is_central' => true,
        ]);

        $user = User::create([
            'name' => 'Budi Santoso',
            'email' => 'kasir.genteng@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $affiliate->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'kasir.genteng@rentcore.id',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'kasir.genteng@rentcore.id',
                        'role' => 'staff',
                        'affiliate' => [
                            'code' => 'GNT',
                            'name' => 'Genteng Central Hub',
                        ],
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_authenticated_user_can_access_profile_and_logout(): void
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $token = $user->createToken('Test')->plainTextToken;

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('data.user.email', 'admin@rentcore.id');

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_login_returns_associated_businesses_and_accessible_branches(): void
    {
        $user = User::create([
            'name' => 'Owner Rental',
            'email' => 'owner@rentcore.id',
            'password' => Hash::make('password123'),
            'role' => 'affiliate_admin',
            'is_active' => true,
        ]);

        $business = \App\Models\Business::create([
            'name' => 'RentCore Express',
            'slug' => 'rentcore-express',
            'owner_id' => $user->id,
            'is_active' => true,
        ]);

        $branch = Affiliate::create([
            'business_id' => $business->id,
            'code' => 'EXP',
            'name' => 'Express Branch',
            'slug' => 'express-branch',
            'is_central' => true,
        ]);

        \App\Models\BusinessMember::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@rentcore.id',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.businesses.0.name', 'RentCore Express')
            ->assertJsonPath('data.businesses.0.branches.0.code', 'EXP');
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $user = User::create([
            'name' => 'Original Name',
            'email' => 'editor@rentcore.id',
            'phone' => '08123456789',
            'password' => Hash::make('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $token = $user->createToken('Test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Updated Name',
                'phone' => '08987654321',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'Updated Name')
            ->assertJsonPath('data.user.phone', '08987654321')
            ->assertJsonPath('data.user.has_password', true);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'phone' => '08987654321',
        ]);
    }

    public function test_google_user_without_password_can_create_password_without_current_password(): void
    {
        $user = User::create([
            'name' => 'Google User',
            'email' => 'google@rentcore.id',
            'google_id' => 'google-oauth-sub-12345',
            'password' => null,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->assertFalse($user->has_password);

        $token = $user->createToken('Test')->plainTextToken;

        // Creating password for first time without current_password
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'new_password' => 'newSecretPass123',
                'new_password_confirmation' => 'newSecretPass123',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_password', true);

        $user->refresh();
        $this->assertTrue($user->has_password);
        $this->assertTrue(Hash::check('newSecretPass123', $user->password));
    }

    public function test_user_with_existing_password_must_provide_correct_current_password_to_change(): void
    {
        $user = User::create([
            'name' => 'Password User',
            'email' => 'pwd@rentcore.id',
            'password' => Hash::make('oldSecretPass123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $token = $user->createToken('Test')->plainTextToken;

        // Attempt change with wrong current password
        $failResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'wrongCurrentPassword',
                'new_password' => 'newerSecretPass456',
                'new_password_confirmation' => 'newerSecretPass456',
            ]);

        $failResponse->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        // Attempt change with valid current password
        $successResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'oldSecretPass123',
                'new_password' => 'newerSecretPass456',
                'new_password_confirmation' => 'newerSecretPass456',
            ]);

        $successResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('newerSecretPass456', $user->password));
    }
}
