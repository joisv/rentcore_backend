<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryAwareReturnAndQrisValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $staff;
    protected User $owner;
    protected Business $business;
    protected Affiliate $branch;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->owner = User::create([
            'name' => 'Store Owner',
            'email' => 'owner@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Unit Test Store',
            'slug' => 'rentcore-unit-test-store',
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'shift_enforcement_enabled' => false,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'CENTRAL',
            'name' => 'Branch Central Hub',
            'slug' => 'branch-central-hub',
            'is_central' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Staff Kasir',
            'email' => 'staff@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branch->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Test Customer',
            'phone' => '081234567890',
        ]);

        PaymentMethod::create(['code' => 'CASH', 'name' => 'Tunai', 'is_active' => true]);
        PaymentMethod::create(['code' => 'QRIS', 'name' => 'QRIS', 'is_active' => true]);
    }

    private function createBookingWithItem(string $categoryName, string $categorySlug, string $brand, string $modelName, bool $requiresFrp = false)
    {
        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => $categoryName,
            'slug' => $categorySlug,
        ]);

        $model = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'brand' => $brand,
            'name' => $modelName,
            'slug' => strtolower(str_replace(' ', '-', $modelName)),
            'requires_frp_check' => $requiresFrp,
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => 'AST-' . strtoupper(substr($modelName, 0, 3)) . '-01',
            'serial_number' => 'SN-' . rand(10000, 99999),
            'status' => 'rented',
        ]);

        $booking = $this->staff->bookingsCreated()->create([
            'business_id' => $this->business->id,
            'booking_code' => 'RC-' . rand(1000, 9999),
            'affiliate_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'start_at' => Carbon::now()->subDays(1),
            'end_at' => Carbon::now()->addHours(1),
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => 'rented',
            'subtotal_amount' => 150000.00,
            'total_bill' => 150000.00,
            'collateral_secured' => true,
        ]);

        $booking->items()->create([
            'asset_id' => $asset->id,
            'unit_price' => 150000.00,
            'duration_unit' => 'day',
            'duration_value' => 1,
        ]);

        return [$booking, $asset];
    }

    public function test_non_phone_units_bypass_icloud_validation(): void
    {
        $token = $this->staff->createToken('Test')->plainTextToken;

        // 1. Outdoor Tent
        [$tentBooking, $tentAsset] = $this->createBookingWithItem('Camping & Outdoor', 'outdoor', 'Eiger', 'Tenda Dome 4 Person', false);
        $respTent = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$tentBooking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'tent_poles' => true,
                    'guy_lines' => true,
                    'tent_fabric' => true,
                    'carry_bag' => true,
                ],
                'accessories_returned' => ['tent_poles' => true, 'carry_bag' => true],
            ]);
        $respTent->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('returned', $tentBooking->fresh()->status);
        $this->assertEquals('ready', $tentAsset->fresh()->status);

        // 2. Drone
        [$droneBooking, $droneAsset] = $this->createBookingWithItem('Drone & Aerial', 'drone', 'DJI', 'DJI Mavic Air 2', false);
        $respDrone = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$droneBooking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['unit_operational' => true, 'accessories_complete' => true],
                'accessories_returned' => ['accessories_complete' => true],
            ]);
        $respDrone->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('returned', $droneBooking->fresh()->status);

        // 3. Powerbank
        [$pwrBooking, $pwrAsset] = $this->createBookingWithItem('Powerbank & Aksesoris', 'powerbank', 'Anker', 'Anker PowerCore 24000mAh', false);
        $respPwr = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$pwrBooking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['unit_operational' => true],
            ]);
        $respPwr->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('returned', $pwrBooking->fresh()->status);

        // 4. PlayStation 5 Console
        [$ps5Booking, $ps5Asset] = $this->createBookingWithItem('Konsol Game', 'konsol-game', 'Sony', 'PlayStation 5 Disc Edition', false);
        $respPs5 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$ps5Booking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => ['controller' => true, 'hdmi_cable' => true, 'console_chassis' => true],
                'accessories_returned' => ['controller' => true, 'hdmi_cable' => true],
            ]);
        $respPs5->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('returned', $ps5Booking->fresh()->status);
    }

    public function test_iphone_hardware_strictly_requires_icloud_removed_or_quarantine(): void
    {
        $token = $this->staff->createToken('Test')->plainTextToken;

        [$iphoneBooking, $iphoneAsset] = $this->createBookingWithItem('Smartphones', 'smartphones', 'Apple', 'iPhone 15 Pro Titanium', true);

        // Fails if icloud_removed is omitted/false without quarantine
        $failResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$iphoneBooking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'screen_ok' => true,
                    'camera_ok' => true,
                ],
            ]);
        $failResp->assertStatus(422)
            ->assertJsonValidationErrors(['checklist']);
        
        // Assert error message specifically refers to iCloud / Apple ID, NOT Google / FRP!
        $errorMsg = $failResp->json('errors.checklist.0');
        $this->assertStringContainsString('iCloud', $errorMsg);
        $this->assertStringNotContainsString('Google', $errorMsg);
        $this->assertStringNotContainsString('FRP', $errorMsg);

        // Succeeds when icloud_removed is true (zero FRP / Google account parameters submitted)
        $okResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$iphoneBooking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'icloud_removed' => true,
                    'account_status' => 'cleared',
                    'device_platform' => 'ios',
                    'screen_ok' => true,
                    'camera_ok' => true,
                ],
                'accessories_returned' => ['cable' => true, 'adapter' => true],
            ]);
        $okResp->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('returned', $iphoneBooking->fresh()->status);
    }

    public function test_iphone_with_various_naming_bypasses_google_frp_completely(): void
    {
        $token = $this->staff->createToken('Test')->plainTextToken;

        // Model named "14 Pro Max" with brand "Apple Inc." and requires_frp_check = true
        [$customIphoneBooking, $customIphoneAsset] = $this->createBookingWithItem('Handphone', 'handphone', 'Apple Inc.', '14 Pro Max 256GB', true);

        // Return with device_platform: 'ios' and icloud_removed: true -> MUST SUCCEED with NO Google FRP error
        $resp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$customIphoneBooking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'icloud_removed' => true,
                    'account_status' => 'cleared',
                    'device_platform' => 'ios',
                    'screen_ok' => true,
                ],
            ]);

        $resp->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('returned', $customIphoneBooking->fresh()->status);
    }

    public function test_android_device_requires_google_frp_removal(): void
    {
        $token = $this->staff->createToken('Test')->plainTextToken;

        [$androidBooking, $androidAsset] = $this->createBookingWithItem('Smartphones', 'smartphones', 'Samsung', 'Galaxy S24 Ultra', true);

        // Fails when frp_removed is omitted
        $failResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$androidBooking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'device_platform' => 'android',
                    'screen_ok' => true,
                ],
            ]);
        $failResp->assertStatus(422)->assertJsonValidationErrors(['checklist']);
        $errorMsg = $failResp->json('errors.checklist.0');
        $this->assertStringContainsString('Google / FRP', $errorMsg);

        // Passes when frp_removed is true
        $okResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/bookings/{$androidBooking->id}/return", [
                'condition_grade' => 'A',
                'checklist_results' => [
                    'frp_removed' => true,
                    'device_platform' => 'android',
                    'account_status' => 'cleared',
                    'screen_ok' => true,
                ],
            ]);
        $okResp->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals('returned', $androidBooking->fresh()->status);
    }

    protected function getValidQrisUploadedFile(string $filename = 'qris_sample.png'): UploadedFile
    {
        $fixturePath = base_path('tests/Fixtures/sample_qris.png');
        $tempPath = tempnam(sys_get_temp_dir(), 'qris_') . '.png';
        copy($fixturePath, $tempPath);
        return new UploadedFile($tempPath, $filename, 'image/png', null, true);
    }

    public function test_invalid_qris_image_upload_is_rejected_with_specific_error(): void
    {
        $ownerToken = $this->owner->createToken('Owner')->plainTextToken;

        // Blank image without QR barcode
        $file = UploadedFile::fake()->image('invalid_blank.png', 400, 400);

        $uploadResp = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Branch-Id', (string)$this->branch->id)
            ->post('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'qris_image' => $file,
            ]);

        $uploadResp->assertStatus(422);
        $this->assertStringContainsString('Gambar tidak valid atau barcode QRIS tidak terdeteksi.', $uploadResp->json('message'));
    }

    public function test_dynamic_qris_toggle_guard_rejects_activation_without_raw_payload(): void
    {
        $ownerToken = $this->owner->createToken('Owner')->plainTextToken;

        // Attempt to enable dynamic QRIS when no QRIS payload has ever been uploaded
        $resp = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Branch-Id', (string)$this->branch->id)
            ->post('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'is_dynamic_qris_enabled' => true,
            ]);

        $resp->assertStatus(422);
        $this->assertStringContainsString('Unggah gambar QRIS statis terlebih dahulu untuk mengaktifkan QRIS Dinamis.', $resp->json('message'));
    }

    public function test_static_qris_image_upload_decodes_payload_and_enables_dynamic_toggle(): void
    {
        $ownerToken = $this->owner->createToken('Owner')->plainTextToken;

        // 1. Upload valid static QRIS image
        $file = $this->getValidQrisUploadedFile('qris_sample.png');

        $uploadResp = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Branch-Id', (string)$this->branch->id)
            ->post('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'qris_image' => $file,
                'is_dynamic_qris_enabled' => true,
            ]);

        $uploadResp->assertStatus(200);
        $config = $uploadResp->json('data.config');
        $this->assertNotEmpty($config['qris_image_url']);
        $this->assertNotEmpty($config['qris_raw_payload']);
        $this->assertStringStartsWith('000201', $config['qris_raw_payload']);
        $this->assertTrue($config['is_dynamic_qris_enabled']);
        $this->assertStringContainsString('storage/qris/', $config['qris_image_url']);

        // 2. Cashier paymentMethods returns active branch qris_image_url and qris_raw_payload
        $staffToken = $this->staff->createToken('Staff')->plainTextToken;
        $methodsResp = $this->withHeader('Authorization', "Bearer {$staffToken}")
            ->withHeader('X-Branch-Id', (string)$this->branch->id)
            ->getJson('/api/v1/cashier/payment-methods?branch_id=' . $this->branch->id);

        $methodsResp->assertStatus(200);
        $methods = $methodsResp->json('data');
        $qrisMethod = collect($methods)->firstWhere('code', 'QRIS');
        $this->assertNotNull($qrisMethod);
        $this->assertNotEmpty($qrisMethod['qris_image_url']);
        $this->assertEquals($config['qris_raw_payload'], $qrisMethod['qris_raw_payload']);
        $this->assertTrue($qrisMethod['is_dynamic_qris_enabled']);
        $this->assertStringContainsString('storage/qris/', $qrisMethod['qris_image_url']);

        // 3. Public static image endpoint can be streamed without token
        $streamResp = $this->get('/api/v1/payment-settings/qris-image?business_id=' . $this->business->id . '&branch_id=' . $this->branch->id);
        $streamResp->assertStatus(200);
    }

    public function test_static_qris_image_upload_succeeds_when_ewallets_disabled_and_with_wallet_enable_alias(): void
    {
        $ownerToken = $this->owner->createToken('Owner')->plainTextToken;

        // Upload static QRIS image while disabling ewallets using both boolean false and string 'false' / wallet_enable
        $file = $this->getValidQrisUploadedFile('qris_disabled_wallets.png');

        $uploadResp = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Branch-Id', (string)$this->branch->id)
            ->post('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'ewallet_enabled' => 'false',
                'wallet_enable' => 'false',
                'qris_statis_enabled' => 'true',
                'cash_enabled' => 'true',
                'bank_transfer_enabled' => 'false',
                'ewallet_providers' => [
                    'shopeepay' => 'false',
                    'dana' => 'false',
                ],
                'qris_image' => $file,
            ]);

        $uploadResp->assertStatus(200);
        $config = $uploadResp->json('data.config');
        $this->assertFalse($config['ewallet_enabled']);
        $this->assertTrue($config['qris_statis_enabled']);
        $this->assertFalse($config['bank_transfer_enabled']);
        $this->assertNotEmpty($config['qris_image_url']);
        $this->assertNotEmpty($config['qris_raw_payload']);
        $this->assertStringContainsString('storage/qris/', $config['qris_image_url']);
    }

    public function test_static_qris_image_upload_succeeds_with_partial_payload_omitting_wallet(): void
    {
        $ownerToken = $this->owner->createToken('Owner')->plainTextToken;

        $file = $this->getValidQrisUploadedFile('qris_partial.png');

        // Submitting only QRIS image (e-wallets completely omitted from payload)
        $uploadResp = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->withHeader('X-Branch-Id', (string)$this->branch->id)
            ->post('/api/v1/payment-settings', [
                'branch_id' => $this->branch->id,
                'qris_image' => $file,
            ]);

        $uploadResp->assertStatus(200);
        $config = $uploadResp->json('data.config');
        $this->assertNotEmpty($config['qris_image_url']);
        $this->assertNotEmpty($config['qris_raw_payload']);
        $this->assertNotNull($config['ewallet_enabled']);
    }
}
