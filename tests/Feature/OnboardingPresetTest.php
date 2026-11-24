<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\AssetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_iphone_gadget_preset_seeds_starter_categories_and_grace_defaults(): void
    {
        $user = User::create([
            'name' => 'Owner Preset',
            'email' => 'preset.iphone@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson('/api/v1/businesses', [
            'name' => 'iStore Rental Bali',
            'city' => 'Denpasar',
            'initial_branch_name' => 'iStore Renon',
            'branch_structure' => 'single_location',
            'niche_preset' => 'iphone_gadget',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'iStore Rental Bali')
            ->assertJsonPath('data.city', 'Denpasar')
            ->assertJsonPath('data.booking_grace_period_minutes', 60)
            ->assertJsonPath('data.return_grace_period_minutes', 15);

        $businessId = $response->json('data.id');

        // Central branch persists custom branch name & city immediately
        $this->assertDatabaseHas('affiliates', [
            'business_id' => $businessId,
            'name' => 'iStore Renon',
            'city' => 'Denpasar',
            'is_central' => true,
        ]);

        // Starter categories seeded specifically for this business_id
        $categories = AssetCategory::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->pluck('name')
            ->all();

        $this->assertContains('iPhone', $categories);
        $this->assertContains('Android & Tablet', $categories);
        $this->assertContains('Powerbank & Charger', $categories);
    }

    public function test_playstation_and_camera_presets_seed_tailored_categories_while_custom_starts_empty(): void
    {
        $ownerPs = User::create([
            'name' => 'Owner PS',
            'email' => 'ps@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $psToken = $ownerPs->createToken('test')->plainTextToken;

        $psResp = $this->withHeaders([
            'Authorization' => "Bearer $psToken",
        ])->postJson('/api/v1/businesses', [
            'name' => 'GameZone Hub',
            'niche_preset' => 'playstation_console',
        ]);
        $psResp->assertStatus(201);
        $psBizId = $psResp->json('data.id');
        $psCats = AssetCategory::withoutGlobalScopes()->where('business_id', $psBizId)->pluck('name')->all();
        $this->assertContains('PlayStation 5 & 4', $psCats);
        $this->assertContains('Stik & Controller', $psCats);

        $ownerCustom = User::create([
            'name' => 'Owner Custom',
            'email' => 'custom@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $customToken = $ownerCustom->createToken('test')->plainTextToken;

        $customResp = $this->withHeaders([
            'Authorization' => "Bearer $customToken",
        ])->postJson('/api/v1/businesses', [
            'name' => 'Serba Ada Rental',
            'niche_preset' => 'custom',
        ]);
        $customResp->assertStatus(201);
        $customBizId = $customResp->json('data.id');
        $this->assertEquals(
            0,
            AssetCategory::withoutGlobalScopes()->where('business_id', $customBizId)->count()
        );
    }
}
