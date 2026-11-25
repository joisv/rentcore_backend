<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentSettingsSyncAndCacheTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $owner;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->owner = User::create([
            'name' => 'Owner Hadi',
            'email' => 'owner.hadi@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'SewaKasir Central Business',
            'slug' => 'rentcore-central',
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'payment_methods_config' => [
                'ewallet_enabled' => true,
                'ewallet_providers' => [
                    'shopeepay' => true,
                    'dana' => true,
                    'gopay' => true,
                    'linkaja' => true,
                    'ovo' => true,
                ],
                'qris_statis_enabled' => true,
                'is_dynamic_qris_enabled' => false,
                'qris_image_path' => 'qris/initial_qris.png',
                'cash_enabled' => true,
                'bank_transfer_enabled' => true,
            ],
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'CBG-MTR',
            'name' => 'Cabang Mataram',
            'slug' => 'cabang-mataram',
            'is_active' => true,
        ]);

        PaymentMethod::firstOrCreate(
            ['code' => 'CASH'],
            ['name' => 'Tunai', 'is_active' => true]
        );

        PaymentMethod::firstOrCreate(
            ['code' => 'QRIS'],
            ['name' => 'QRIS', 'is_active' => true]
        );

        PaymentMethod::firstOrCreate(
            ['code' => 'TRANSFER'],
            ['name' => 'Transfer Bank', 'is_active' => true]
        );

        PaymentMethod::firstOrCreate(
            ['code' => 'DANA'],
            ['name' => 'DANA', 'is_active' => true]
        );

        $this->token = $this->owner->createToken('test-token')->plainTextToken;
    }

    public function test_updating_payment_settings_purges_cache_and_normalizes_booleans(): void
    {
        // 1. Initial GET primes cache
        $getResp1 = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/payment-settings?branch_id=' . $this->branch->id);

        $getResp1->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.config.cash_enabled', true)
            ->assertJsonPath('data.config.qris_statis_enabled', true);

        // Verify cache is populated
        $this->assertTrue(Cache::has("branch_{$this->branch->id}_payment_settings"));

        // 2. Update with stringified booleans (as sent in multipart/form-data)
        $updateResp = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->putJson('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'cash_enabled' => 'false',
                'is_qris_enabled' => 'false',
                'qris_statis_enabled' => '0',
                'bank_transfer_enabled' => 'true',
                'ewallet_enabled' => '1',
                'ewallet_providers' => [
                    'dana' => 'false',
                    'shopeepay' => '1',
                ],
            ]);

        $updateResp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.config.cash_enabled', false)
            ->assertJsonPath('data.config.qris_statis_enabled', false)
            ->assertJsonPath('data.config.bank_transfer_enabled', true)
            ->assertJsonPath('data.config.ewallet_providers.dana', false)
            ->assertJsonPath('data.config.ewallet_providers.shopeepay', true);

        // 3. Verify Cache was strictly purged
        // (Note: after update, getSettings is not re-caching stale because purgeCache wiped it, and next GET primes fresh)
        $getResp2 = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/payment-settings?branch_id=' . $this->branch->id);

        $getResp2->assertStatus(200)
            ->assertJsonPath('data.config.cash_enabled', false)
            ->assertJsonPath('data.config.qris_statis_enabled', false)
            ->assertJsonPath('data.config.bank_transfer_enabled', true);

        // 4. Verify checkout / payment-methods reflects changes immediately
        $methodsResp = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->getJson('/api/v1/cashier/payment-methods?branch_id=' . $this->branch->id);

        $methodsResp->assertStatus(200);
        $methodsData = collect($methodsResp->json('data'));

        // Cash and QRIS should now be absent
        $this->assertNull($methodsData->firstWhere('code', 'CASH'));
        $this->assertNull($methodsData->firstWhere('code', 'QRIS'));
        // DANA e-wallet disabled
        $this->assertNull($methodsData->firstWhere('code', 'DANA'));
        // Transfer should be active
        $this->assertNotNull($methodsData->firstWhere('code', 'TRANSFER'));
    }

    public function test_updating_business_wide_settings_without_branch_persists_to_business(): void
    {
        $updateResp = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->putJson('/api/v1/payment-settings', [
                'branch_id' => 'all',
                'cash_enabled' => false,
                'qris_statis_enabled' => true,
            ]);

        $updateResp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.branch_id', null)
            ->assertJsonPath('data.config.cash_enabled', false);

        $this->assertFalse($this->business->fresh()->payment_methods_config['cash_enabled']);
    }

    public function test_updating_via_multipart_form_data_with_stringified_booleans(): void
    {
        $resp = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->post('/api/v1/payment-settings', [
                'branch_id' => (string)$this->branch->id,
                'cash_enabled' => '1',
                'qris_statis_enabled' => '0',
                'bank_transfer_enabled' => '0',
                'ewallet_enabled' => '0',
            ]);

        $resp->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.config.cash_enabled', true)
            ->assertJsonPath('data.config.qris_statis_enabled', false)
            ->assertJsonPath('data.config.bank_transfer_enabled', false)
            ->assertJsonPath('data.config.ewallet_enabled', false);
    }
}
