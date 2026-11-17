<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageAndAssetSortTest extends TestCase
{
    use RefreshDatabase;

    private function createOwnerWithBusiness(): array
    {
        $user = User::create([
            'name' => 'Owner Studio',
            'email' => 'owner.product@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $business = Business::create([
            'owner_id' => $user->id,
            'name' => 'RentCore Studio',
            'slug' => 'rentcore-studio',
            'is_active' => true,
        ]);
        $branch = Affiliate::create([
            'business_id' => $business->id,
            'name' => 'Cabang Pusat',
            'slug' => 'cabang-pusat',
            'code' => 'PST',
            'is_central' => true,
            'is_active' => true,
        ]);
        BusinessMember::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
        $category = AssetCategory::create([
            'business_id' => $business->id,
            'name' => 'Kamera',
            'slug' => 'kamera',
            'is_active' => true,
        ]);

        return [$user, $business, $branch, $category];
    }

    public function test_product_store_accepts_image_upload_and_returns_image_url(): void
    {
        Storage::fake('public');
        [$user, $business, $branch, $category] = $this->createOwnerWithBusiness();

        $file = UploadedFile::fake()->image('sony-a7iv.jpg', 600, 600);

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Business-Id' => $business->id,
                'X-Branch-Id' => $branch->id,
            ])
            ->post('/api/v1/products', [
                'category_id' => $category->id,
                'brand' => 'Sony',
                'name' => 'Alpha A7 IV',
                'image' => $file,
                'rates' => [
                    [
                        'duration_unit' => 'day',
                        'duration_value' => 1,
                        'price' => 250000,
                        'extension_hourly_rate' => 30000,
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Alpha A7 IV');

        $imagePath = $response->json('data.image_path');
        $imageUrl = $response->json('data.image_url');

        $this->assertNotEmpty($imagePath);
        $this->assertNotEmpty($imageUrl);
        Storage::disk('public')->assertExists($imagePath);
    }

    public function test_product_store_rejects_oversized_or_non_image_file(): void
    {
        Storage::fake('public');
        [$user, $business, $branch, $category] = $this->createOwnerWithBusiness();

        $invalidFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Business-Id' => $business->id,
                'X-Branch-Id' => $branch->id,
                'Accept' => 'application/json',
            ])
            ->post('/api/v1/products', [
                'category_id' => $category->id,
                'brand' => 'Sony',
                'name' => 'Alpha A7 IV',
                'image' => $invalidFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_assets_index_sorts_newest_first_by_created_at_and_id(): void
    {
        [$user, $business, $branch, $category] = $this->createOwnerWithBusiness();

        $model = AssetModel::create([
            'business_id' => $business->id,
            'category_id' => $category->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro',
            'slug' => 'apple-iphone-15-pro',
            'is_active' => true,
        ]);

        $oldAsset = Asset::create([
            'business_id' => $business->id,
            'affiliate_id' => $branch->id,
            'model_id' => $model->id,
            'asset_code' => 'AST-OLD',
            'serial_number' => 'SN-OLD',
            'status' => 'ready',
            'condition_grade' => 'A',
        ]);
        $oldAsset->timestamps = false;
        $oldAsset->forceFill(['created_at' => now()->subDays(2)])->save();

        $newAsset = Asset::create([
            'business_id' => $business->id,
            'affiliate_id' => $branch->id,
            'model_id' => $model->id,
            'asset_code' => 'AST-NEW',
            'serial_number' => 'SN-NEW',
            'status' => 'ready',
            'condition_grade' => 'A',
        ]);
        $newAsset->timestamps = false;
        $newAsset->forceFill(['created_at' => now()])->save();

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Business-Id' => $business->id,
                'X-Branch-Id' => $branch->id,
            ])
            ->getJson('/api/v1/assets?all=1');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$newAsset->id, $oldAsset->id], $ids);
    }
}
