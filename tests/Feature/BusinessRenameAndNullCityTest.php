<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRenameAndNullCityTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_and_branch_creation_without_city_leaves_city_and_address_null(): void
    {
        $owner = User::create([
            'name' => 'Owner Satu',
            'email' => 'owner1@rentcore.test',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $token = $owner->createToken('test')->plainTextToken;

        $createBiz = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/businesses', [
                'name' => 'awanrental',
            ]);

        $createBiz->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'awanrental')
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.branches.0.city', null)
            ->assertJsonPath('data.branches.0.address', null);

        $bizId = $createBiz->json('data.id');

        $createBranch = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $bizId)
            ->postJson("/api/v1/businesses/{$bizId}/branches", [
                'name' => 'Cabang Kedua',
                'code' => 'CB2',
            ]);

        $createBranch->assertStatus(201)
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.address', null);
    }

    public function test_business_rename_persists_and_returns_updated_name_across_endpoints(): void
    {
        $owner = User::create([
            'name' => 'Owner Dua',
            'email' => 'owner2@rentcore.test',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $business = Business::create([
            'name' => 'awanrental',
            'slug' => 'awanrental',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $branch = Affiliate::create([
            'business_id' => $business->id,
            'code' => 'AWN',
            'name' => 'Cabang Utama',
            'slug' => 'awanrental-awn',
            'is_central' => true,
            'is_active' => true,
        ]);
        $member = BusinessMember::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        $member->branchMembers()->create(['affiliate_id' => $branch->id]);

        $token = $owner->createToken('test')->plainTextToken;

        $updateRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $business->id)
            ->putJson("/api/v1/businesses/{$business->id}", [
                'name' => 'awansky',
                'slogan' => '',
                'address' => '',
                'phone' => '',
            ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'awansky')
            ->assertJsonPath('data.branches.0.city', null);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/businesses')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'awansky');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.businesses.0.name', 'awansky');
    }

    public function test_renaming_business_syncs_default_pusat_branch_and_allows_branch_update_and_denpasar_cleanup(): void
    {
        $owner = User::create([
            'name' => 'Owner Tiga',
            'email' => 'owner3@rentcore.test',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $business = Business::create([
            'name' => 'AwanRental',
            'slug' => 'awanrental-3',
            'city' => 'Denpasar',
            'owner_id' => $owner->id,
            'is_active' => true,
        ]);
        $branch = Affiliate::create([
            'business_id' => $business->id,
            'code' => 'AWA',
            'name' => 'AwanRental Pusat',
            'slug' => 'awanrental-awa',
            'city' => 'Denpasar',
            'is_central' => true,
            'is_active' => true,
        ]);
        $member = BusinessMember::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        $member->branchMembers()->create(['affiliate_id' => $branch->id]);

        $token = $owner->createToken('test')->plainTextToken;

        // 1. Clean legacy Denpasar default city via artisan command
        $this->artisan('branches:clean-default-city')
            ->assertExitCode(0);
        $this->assertNull($business->fresh()->city);
        $this->assertNull($branch->fresh()->city);

        // 2. Rename business AwanRental -> AwanRentalSky: default central branch follows
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $business->id)
            ->putJson("/api/v1/businesses/{$business->id}", [
                'name' => 'AwanRentalSky',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'AwanRentalSky')
            ->assertJsonPath('data.branches.0.name', 'AwanRentalSky Pusat');

        // 3. Edit branch name, code, city, and address explicitly
        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string) $business->id)
            ->putJson("/api/v1/businesses/{$business->id}/branches/{$branch->id}", [
                'name' => 'Cabang Sky Utama',
                'code' => 'SKY',
                'city' => 'Surabaya',
                'address' => 'Jl. Basuki Rahmat No. 12',
                'phone' => '081234567890',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Cabang Sky Utama')
            ->assertJsonPath('data.code', 'SKY')
            ->assertJsonPath('data.city', 'Surabaya')
            ->assertJsonPath('data.address', 'Jl. Basuki Rahmat No. 12');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/businesses')
            ->assertStatus(200)
            ->assertJsonPath('data.0.branches.0.name', 'Cabang Sky Utama')
            ->assertJsonPath('data.0.branches.0.code', 'SKY')
            ->assertJsonPath('data.0.branches.0.city', 'Surabaya');
    }
}
