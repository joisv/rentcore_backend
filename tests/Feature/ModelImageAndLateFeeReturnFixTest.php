<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CashDrawerShift;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PricingTier;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelImageAndLateFeeReturnFixTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private Affiliate $branch;
    private User $owner;
    private AssetCategory $category;
    private PaymentMethod $cashMethod;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Owner Fix Test',
            'email' => 'owner.fix@sewakasir.id',
            'password' => Hash::make('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'SewaKasir Flagship',
            'slug' => 'sewakasir-flagship-' . uniqid(),
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'timezone' => 'Asia/Jakarta',
            'return_grace_period_minutes' => 15,
            'late_fee_grace_mode' => 'forfeit',
            'late_fee_rounding_mode' => 'hourly_ceil',
            'cap_late_fee_at_daily_rate' => true,
            'default_hourly_late_fee' => 15000.00,
            'late_fee_default_type' => 'fixed',
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'name' => 'Cabang Pusat',
            'slug' => 'cabang-pusat-' . uniqid(),
            'code' => 'PST',
            'city' => 'Jakarta',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->owner->update(['affiliate_id' => $this->branch->id]);

        $member = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphone',
            'slug' => 'smartphone-' . uniqid(),
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Tunai',
            'code' => 'CASH',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Andi Wijaya',
            'phone' => '081234567891',
        ]);

        CashDrawerShift::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_number' => 'SHF-FIX-' . uniqid(),
            'cashier_id' => $this->owner->id,
            'opened_by' => $this->owner->id,
            'opening_cash' => 200000.00,
            'expected_cash' => 200000.00,
            'status' => 'open',
            'opened_at' => Carbon::now()->subHour(),
        ]);

        app(TenantContext::class)->set($this->business, $member, $this->branch);
    }

    public function test_model_image_multipart_upload_persists_to_public_models_and_returns_clean_storage_url(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('iphone15.jpg', 500, 500);
        $token = $this->owner->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->post('/api/v1/products', [
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro Max',
            'image' => $file,
            'rates' => [
                [
                    'duration_unit' => 'day',
                    'duration_value' => 1,
                    'price' => 300000,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $imagePath = $response->json('data.image_path');
        $imageUrl = $response->json('data.image_url');

        $this->assertStringStartsWith('models/', $imagePath);
        $this->assertSame('/storage/' . $imagePath, $imageUrl);
        Storage::disk('public')->assertExists($imagePath);

        $productId = $response->json('data.id');
        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];

        // Verify list (/products), detail (/products/{id}), and /assets/models consistently include image_url
        $listRes = $this->withHeaders($headers)->getJson('/api/v1/products?all=1');
        $listRes->assertOk();
        $listed = collect($listRes->json('data'))->firstWhere('id', $productId);
        $this->assertSame($imageUrl, $listed['image_url'] ?? null);

        $detailRes = $this->withHeaders($headers)->getJson("/api/v1/products/{$productId}");
        $detailRes->assertOk()->assertJsonPath('data.image_url', $imageUrl);

        $modelsRes = $this->withHeaders($headers)->getJson('/api/v1/assets/models');
        $modelsRes->assertOk();
        $listedModel = collect($modelsRes->json('data'))->firstWhere('id', $productId);
        $this->assertSame($imageUrl, $listedModel['image_url'] ?? null);

        // Verify Windows backslashes in image_path normalize cleanly
        $modelRecord = AssetModel::findOrFail($productId);
        $modelRecord->update(['image_path' => str_replace('/', '\\', $imagePath)]);
        $this->assertSame('/storage/' . $imagePath, $modelRecord->fresh()->image_url);
    }

    public function test_model_image_base64_upload_persists_to_public_models_and_rewrites_stale_hosts(): void
    {
        Storage::fake('public');

        // Valid 1x1 PNG base64 data URI
        $pngBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';
        $token = $this->owner->createToken('test')->plainTextToken;

        $createRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->postJson('/api/v1/products', [
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'name' => 'PlayStation 5 Slim',
            'image' => $pngBase64,
            'rates' => [
                [
                    'duration_unit' => 'day',
                    'duration_value' => 1,
                    'price' => 200000,
                ],
            ],
        ]);

        $createRes->assertStatus(201);
        $createdPath = $createRes->json('data.image_path');
        $createdUrl = $createRes->json('data.image_url');

        $this->assertStringStartsWith('models/', $createdPath);
        $this->assertStringEndsWith('.png', $createdPath);
        $this->assertSame('/storage/' . $createdPath, $createdUrl);
        Storage::disk('public')->assertExists($createdPath);

        // Verify stale legacy rentcore_backend URL in DB row is cleanly rewritten
        $model = AssetModel::findOrFail($createRes->json('data.id'));
        $model->update(['image_path' => 'http://rentcore_backend.test/storage/models/legacy-photo.jpg']);
        $this->assertSame('/storage/models/legacy-photo.jpg', $model->fresh()->image_url);

        // Updating with new base64 replaces the stored file cleanly
        $updateRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ])->putJson("/api/v1/products/{$model->id}", [
            'image_base64' => $pngBase64,
        ]);

        $updateRes->assertOk();
        $updatedPath = $updateRes->json('data.image_path');
        $this->assertStringStartsWith('models/', $updatedPath);
        $this->assertSame('/storage/' . $updatedPath, $updateRes->json('data.image_url'));
        Storage::disk('public')->assertExists($updatedPath);
    }

    public function test_overdue_booking_show_preview_and_return_compute_non_zero_fee_for_model_and_store_rates(): void
    {
        $nowWib = Carbon::now('Asia/Jakarta');
        $dueAt = $nowWib->copy()->subMinutes(130); // > 2h overdue (> 15m grace -> 3h billed under hourly_ceil + forfeit)

        // Case 1: Model override rate (Rp 25.000/jam)
        $modelWithOverride = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'name' => 'Alpha A7 IV',
            'slug' => 'sony-a7iv-' . uniqid(),
            'has_serial_number' => true,
            'is_active' => true,
            'hourly_late_fee' => 25000.00,
        ]);
        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $modelWithOverride->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 300000.00,
            'is_active' => true,
        ]);
        $unit1 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $modelWithOverride->id,
            'asset_code' => 'CAM-OVR-1',
            'serial_number' => 'SN-OVR-1',
            'status' => 'rented',
            'condition_grade' => 'A',
        ]);
        $booking1 = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'SK-OVR-001',
            'asset_model_id' => $modelWithOverride->id,
            'asset_unit_id' => $unit1->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->owner->id,
            'start_at' => $dueAt->copy()->subDay()->setTimezone('UTC'),
            'end_at' => $dueAt->copy()->setTimezone('UTC'),
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'rented',
            'subtotal_amount' => 300000.00,
            'total_bill' => 300000.00,
            'payment_status' => 'paid',
        ]);
        // Simulate legacy/unconfigured 0.00 snapshot — must NOT poison rate to Rp 0!
        $booking1->items()->create([
            'asset_id' => $unit1->id,
            'asset_model_id' => $modelWithOverride->id,
            'item_type' => 'primary',
            'unit_price' => 300000.00,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'applied_hourly_late_fee' => 0.00,
            'status' => 'active',
        ]);

        $token = $this->owner->createToken('test')->plainTextToken;
        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Business-Id' => (string) $this->business->id,
            'X-Branch-Id' => (string) $this->branch->id,
        ];

        // 1a. GET /bookings/{id} exposes late_fee_breakdown with non-zero fee (3h * 25.000 = 75.000)
        $showRes = $this->withHeaders($headers)->getJson("/api/v1/bookings/{$booking1->id}");
        $showRes->assertOk()
            ->assertJsonPath('data.late_fee_breakdown.is_late', true)
            ->assertJsonPath('data.late_fee_breakdown.rate_source', 'model')
            ->assertJsonPath('data.late_fee_breakdown.applied_rate', 25000)
            ->assertJsonPath('data.late_fee_breakdown.billed_units', 3)
            ->assertJsonPath('data.late_fee_breakdown.calculated_fee', 75000);

        $this->assertGreaterThanOrEqual(130, $showRes->json('data.late_fee_breakdown.raw_overdue_minutes'));

        // 1b. POST /bookings/{id}/return calculates and persists Rp 75.000
        $returnRes = $this->withHeaders($headers)->postJson("/api/v1/bookings/{$booking1->id}/return", [
            'condition_grade' => 'A',
            'checklist_results' => ['unit_operational' => true, 'frp_cleared' => true, 'account_removed' => true],
            'frp_cleared' => true,
            'account_removed' => true,
            'penalty_payment_method_id' => $this->cashMethod->id,
            'cash_received' => 75000,
        ]);
        $returnRes->assertOk()
            ->assertJsonPath('data.calculated_late_fee', '75000.00')
            ->assertJsonPath('data.late_fee', '75000.00');

        // Case 2: Model without override falls back to business.late_fee_default_rate (Rp 15.000/jam)
        $modelStoreFallback = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand' => 'Canon',
            'name' => 'EOS R6',
            'slug' => 'canon-r6-' . uniqid(),
            'has_serial_number' => true,
            'is_active' => true,
            'hourly_late_fee' => null,
        ]);
        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $modelStoreFallback->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 250000.00,
            'is_active' => true,
        ]);
        $unit2 = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $modelStoreFallback->id,
            'asset_code' => 'CAM-DEF-2',
            'serial_number' => 'SN-DEF-2',
            'status' => 'rented',
            'condition_grade' => 'A',
        ]);
        $booking2 = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'SK-DEF-002',
            'asset_model_id' => $modelStoreFallback->id,
            'asset_unit_id' => $unit2->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->owner->id,
            'start_at' => $dueAt->copy()->subDay()->setTimezone('UTC'),
            'end_at' => $dueAt->copy()->setTimezone('UTC'),
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'rented',
            'subtotal_amount' => 250000.00,
            'total_bill' => 250000.00,
            'payment_status' => 'paid',
        ]);
        $booking2->items()->create([
            'asset_id' => $unit2->id,
            'asset_model_id' => $modelStoreFallback->id,
            'item_type' => 'primary',
            'unit_price' => 250000.00,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'active',
        ]);

        $previewRes = $this->withHeaders($headers)->getJson("/api/v1/bookings/{$booking2->id}/late-fee-preview");
        $previewRes->assertOk()
            ->assertJsonPath('data.is_late', true)
            ->assertJsonPath('data.rate_source', 'business_default')
            ->assertJsonPath('data.applied_rate', 15000)
            ->assertJsonPath('data.calculated_fee', 45000)
            ->assertJsonPath('late_fee_breakdown.calculated_fee', 45000);
    }
}
