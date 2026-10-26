<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase3ScaleAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $staff;
    protected PaymentMethod $cashMethod;
    protected Customer $customer;
    protected AssetModel $model;
    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        $this->staff = User::create([
            'name' => 'Staff Operasional',
            'email' => 'staff.ops@scalehard.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Scale Hardening Rental',
            'slug' => 'scale-hardening-rental',
            'email' => 'owner@scalehard.id',
            'owner_id' => $this->staff->id,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'name' => 'Cabang Pusat',
            'slug' => 'cabang-pusat',
            'code' => 'PST',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->staff->update([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Tunai Kasir',
            'code' => 'CASH',
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
        ]);

        $cat = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Kamera & iPhone',
            'slug' => 'kamera-iphone',
            'is_active' => true,
        ]);

        $this->model = AssetModel::create([
            'category_id' => $cat->id,
            'name' => 'iPhone 15 Pro Max',
            'slug' => 'iphone-15-pro-max',
            'brand' => 'Apple',
            'is_active' => true,
        ]);

        PricingTier::create([
            'model_id' => $this->model->id,
            'duration_hours' => 12,
            'price' => 150000,
            'extension_hourly_rate' => 15000,
            'is_active' => true,
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IPH15-001',
            'serial_number' => 'SN-IPH15-001',
            'status' => 'rented',
        ]);
    }

    public function test_rental_extension_with_idempotency_key_prevents_duplicate_charges(): void
    {
        $token = $this->staff->createToken('staff-token')->plainTextToken;

        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'BK-EXT-TEST-01',
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'start_at' => Carbon::now()->subHours(10),
            'end_at' => Carbon::now()->addHours(14),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 250000,
            'total_bill' => 250000,
            'payment_status' => 'paid',
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $this->asset->id,
            'item_type' => 'primary',
            'status' => 'active',
            'unit_price' => 250000,
        ]);

        $idempotencyKey = 'idem-ext-key-' . uniqid();

        $payload = [
            'additional_hours' => 12,
            'additional_fee' => 150000,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 150000,
            'idempotency_key' => $idempotencyKey,
            'notes' => 'Perpanjangan 12 jam sewa',
        ];

        // First Request: executes extension and records payment
        $response1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/bookings/{$booking->id}/extend", $payload);

        $response1->assertStatus(200)
            ->assertJsonPath('success', true);

        $booking->refresh();
        $this->assertEquals(36, $booking->duration_hours);
        $this->assertEquals(400000, (float)$booking->total_bill);
        $this->assertEquals(1, $booking->extensions()->count());
        $this->assertEquals(1, PaymentTransaction::where('idempotency_key', $idempotencyKey)->count());

        // Second Request (Immediate Retry / Double Tap with identical key)
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/bookings/{$booking->id}/extend", $payload);

        $response2->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_idempotent', true);

        // Verification: totals must NOT double up!
        $booking->refresh();
        $this->assertEquals(36, $booking->duration_hours);
        $this->assertEquals(400000, (float)$booking->total_bill);
        $this->assertEquals(1, $booking->extensions()->count());
        $this->assertEquals(1, PaymentTransaction::where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_return_retry_with_existing_inspection_returns_idempotently(): void
    {
        $token = $this->staff->createToken('staff-token')->plainTextToken;

        $booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'booking_code' => 'BK-RET-TEST-01',
            'customer_id' => $this->customer->id,
            'created_by' => $this->staff->id,
            'start_at' => Carbon::now()->subHours(20),
            'end_at' => Carbon::now()->subHours(1),
            'duration_hours' => 19,
            'status' => 'rented',
            'subtotal_amount' => 200000,
            'total_bill' => 200000,
            'payment_status' => 'paid',
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $this->asset->id,
            'item_type' => 'primary',
            'status' => 'active',
            'unit_price' => 200000,
        ]);

        $idempotencyKey = 'idem-ret-key-' . uniqid();

        $payload = [
            'condition_grade' => 'A',
            'battery_health_final' => 95,
            'checklist_results' => [
                'icloud_removed' => true,
                'screen_normal' => true,
            ],
            'idempotency_key' => $idempotencyKey,
            'staff_notes' => 'Unit dikembalikan dalam kondisi mulus',
        ];

        // 1. Initial Return Process
        $response1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", $payload);

        $response1->assertStatus(200)
            ->assertJsonPath('success', true);

        $booking->refresh();
        $this->assertEquals('returned', $booking->status);
        $this->assertNotNull($booking->returnInspection);

        // 2. Client Retry (Network timeout or duplicate tap)
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Business-Id', (string)$this->business->id)
            ->postJson("/api/v1/bookings/{$booking->id}/return", $payload);

        $response2->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.condition_grade', 'A');
    }

    public function test_migrate_customer_identities_artisan_command(): void
    {
        // 1. Put legacy document on public disk
        $filePath = 'customer_identities/id_legacy_999.jpg';
        $content = 'FAKE_IMAGE_BINARY_BYTES_12345';
        Storage::disk('public')->put($filePath, $content);
        $this->assertTrue(Storage::disk('public')->exists($filePath));
        $this->assertFalse(Storage::disk('local')->exists($filePath));

        // Create CustomerIdentity record pointing to this file
        CustomerIdentity::create([
            'customer_id' => $this->customer->id,
            'identity_type' => 'KTP',
            'identity_number' => '3271019988776655',
            'file_path' => $filePath,
        ]);

        // 2. Execute migration command
        $this->artisan('rentcore:migrate-identities-to-private')
            ->expectsOutputToContain('CUSTOMER IDENTITY MIGRATION TO PRIVATE STORAGE')
            ->assertSuccessful();

        // 3. Verify file moved to private local disk and removed from public
        $this->assertTrue(Storage::disk('local')->exists($filePath));
        $this->assertEquals($content, Storage::disk('local')->get($filePath));
        $this->assertFalse(Storage::disk('public')->exists($filePath));
    }
}
