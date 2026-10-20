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
}
