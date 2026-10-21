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
use App\Models\PricingTier;
use App\Models\User;
use App\Services\BookingService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RentalQueueAddonDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Business $business;
    protected Affiliate $branch;
    protected PaymentMethod $cashMethod;
    protected AssetModel $iphoneModel;
    protected AssetModel $powerBankModel;
    protected AssetModel $chargerModel;
    protected AssetCategory $smartphoneCategory;
    protected AssetCategory $addonCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Owner Rental',
            'email' => 'owner@queue.test',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'Rental Mega Utama',
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'id_document_limit_enabled' => true,
            'max_primary_devices_per_id' => 1,
            'addons_enabled' => true,
            'max_addons_per_rental' => 5,
        ]);

        $member = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'name' => 'Cabang Utama',
            'slug' => 'cabang-utama',
            'code' => 'CBU',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->owner->update(['affiliate_id' => $this->branch->id]);
        app(TenantContext::class)->set($this->business, $member, $this->branch);

        $this->smartphoneCategory = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->addonCategory = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Aksesoris Sewa',
            'slug' => 'aksesoris-sewa',
            'is_addon' => true,
            'is_active' => true,
        ]);

        $this->iphoneModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->smartphoneCategory->id,
            'brand' => 'Apple',
            'name' => 'iPhone 13 128GB — Midnight',
            'slug' => 'iphone-13-128gb-midnight',
            'is_addon' => false,
            'is_active' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $this->iphoneModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 150000.00,
            'is_active' => true,
        ]);

        $this->powerBankModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->addonCategory->id,
            'brand' => 'Anker',
            'name' => 'Power Bank Anker 10000mAh',
            'slug' => 'power-bank-anker-10k',
            'is_addon' => true,
            'is_active' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $this->powerBankModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 25000.00,
            'is_active' => true,
        ]);

        $this->chargerModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->addonCategory->id,
            'brand' => 'Baseus',
            'name' => 'Fast Charger 67W',
            'slug' => 'charger-baseus-67w',
            'is_addon' => true,
            'is_active' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $this->chargerModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 15000.00,
            'is_active' => true,
        ]);
    }

    public function test_device_only_rental_displays_correct_primary_device(): void
    {
        $phoneAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'IPHONE-001',
            'serial_number' => 'SN-IP-001',
            'status' => 'ready',
        ]);

        $bookingService = app(BookingService::class);
        $booking = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '08123456789',
            'identity_number' => '35010001',
            'asset_ids' => [$phoneAsset->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
        ], $this->owner);

        $booking->load(['items.asset.model', 'customer']);

        $this->assertNotNull($booking->active_item);
        $this->assertNotNull($booking->active_asset);
        $this->assertEquals($phoneAsset->id, $booking->active_asset->id);
        $this->assertEquals('iPhone 13 128GB — Midnight', $booking->active_asset->model->name);
        $this->assertEquals('primary', $booking->active_item->item_type);
        $this->assertEmpty($booking->addon_items);
        $this->assertEquals(150000.00, (float)$booking->total_bill);
    }

    public function test_device_plus_multiple_addons_correctly_identifies_primary_and_all_addons(): void
    {
        $phoneAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'IPHONE-002',
            'serial_number' => 'SN-IP-002',
            'status' => 'ready',
        ]);

        $pbAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->powerBankModel->id,
            'asset_code' => 'PB-001',
            'serial_number' => 'SN-PB-001',
            'status' => 'ready',
        ]);

        $chargerAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->chargerModel->id,
            'asset_code' => 'CHG-001',
            'serial_number' => 'SN-CHG-001',
            'status' => 'ready',
        ]);

        $bookingService = app(BookingService::class);
        $booking = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'John Doe',
            'customer_phone' => '08999999999',
            'identity_number' => '35020002',
            'asset_ids' => [$phoneAsset->id],
            'addon_asset_ids' => [$pbAsset->id, $chargerAsset->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
        ], $this->owner);

        // Reload fresh from DB as API would
        $freshBooking = Booking::with(['items.asset.model.category', 'customer'])->findOrFail($booking->id);

        // 1. Primary device is correctly identified and NOT overwritten by add-ons
        $this->assertEquals($phoneAsset->id, $freshBooking->active_asset->id);
        $this->assertEquals('iPhone 13 128GB — Midnight', $freshBooking->active_asset->model->name);
        $this->assertEquals('IPHONE-002', $freshBooking->active_asset->asset_code);

        // 2. Primary asset accessor also resolves primary device
        $this->assertEquals($phoneAsset->id, $freshBooking->primary_asset->id);

        // 3. Add-on items are all preserved and identified as addon
        $this->assertCount(2, $freshBooking->addon_items);
        $addonAssetIds = collect($freshBooking->addon_items)->pluck('asset_id')->toArray();
        $this->assertContains($pbAsset->id, $addonAssetIds);
        $this->assertContains($chargerAsset->id, $addonAssetIds);

        // 4. Subtotals and total bill match exactly
        $this->assertEquals(150000.00, (float)$freshBooking->primary_subtotal);
        $this->assertEquals(40000.00, (float)$freshBooking->addon_subtotal);
        $this->assertEquals(190000.00, (float)$freshBooking->total_bill);

        // 5. Total items count is 3 within the single transaction (no duplicate transactions)
        $this->assertCount(3, $freshBooking->items);
        $this->assertEquals(1, Booking::where('customer_id', $freshBooking->customer_id)->count());
    }

    public function test_api_bookings_index_endpoint_returns_correct_queue_data(): void
    {
        $phoneAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'IPHONE-003',
            'serial_number' => 'SN-IP-003',
            'status' => 'ready',
        ]);

        $pbAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->powerBankModel->id,
            'asset_code' => 'PB-002',
            'serial_number' => 'SN-PB-002',
            'status' => 'ready',
        ]);

        $bookingService = app(BookingService::class);
        $booking = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Siti Rahma',
            'customer_phone' => '08777777777',
            'identity_number' => '35030003',
            'asset_ids' => [$phoneAsset->id],
            'addon_asset_ids' => [$pbAsset->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
        ], $this->owner);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/bookings?branch_id={$this->branch->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $bookingsList = $response->json('data.data');
        $this->assertCount(1, $bookingsList);

        $firstBooking = $bookingsList[0];
        $this->assertEquals($booking->booking_code, $firstBooking['booking_code']);

        // active_asset must be the primary iPhone, NOT the power bank
        $this->assertEquals('iPhone 13 128GB — Midnight', $firstBooking['active_asset']['model']['name']);
        $this->assertEquals('IPHONE-003', $firstBooking['active_asset']['asset_code']);

        // primary_asset must also be the primary iPhone
        $this->assertEquals('iPhone 13 128GB — Midnight', $firstBooking['primary_asset']['model']['name']);

        // addon_items list has the power bank
        $this->assertCount(1, $firstBooking['addon_items']);
        $this->assertEquals('PB-002', $firstBooking['addon_items'][0]['asset']['asset_code']);
    }

    public function test_addon_with_higher_id_does_not_usurp_primary_device(): void
    {
        $phoneAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'IPHONE-004',
            'serial_number' => 'SN-IP-004',
            'status' => 'ready',
        ]);

        $pbAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->powerBankModel->id,
            'asset_code' => 'PB-003',
            'serial_number' => 'SN-PB-003',
            'status' => 'ready',
        ]);

        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'booking_code' => 'RC-TEST-004',
            'affiliate_id' => $this->branch->id,
            'customer_id' => Customer::create(['business_id' => $this->business->id, 'name' => 'Doni', 'phone' => '08111'])->id,
            'created_by' => $this->owner->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addDay(),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 175000.00,
            'primary_subtotal' => 150000.00,
            'addon_subtotal' => 25000.00,
            'total_bill' => 175000.00,
            'payment_status' => 'paid',
        ]);

        // Deliberately create primary item first (id: lower)
        $primaryItem = BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $phoneAsset->id,
            'item_type' => 'primary',
            'unit_price' => 150000.00,
        ]);

        // Create addon item second (id: higher)
        $addonItem = BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $pbAsset->id,
            'item_type' => 'addon',
            'unit_price' => 25000.00,
        ]);

        $this->assertGreaterThan($primaryItem->id, $addonItem->id);

        $freshBooking = Booking::with('items.asset.model')->findOrFail($booking->id);

        // Despite higher id on the addon item, active_asset MUST be the primary iPhone
        $this->assertEquals($phoneAsset->id, $freshBooking->active_asset->id);
        $this->assertEquals('iPhone 13 128GB — Midnight', $freshBooking->active_asset->model->name);
        $this->assertEquals($primaryItem->id, $freshBooking->active_item->id);
    }

    public function test_legacy_booking_without_item_type_retains_primary_identity(): void
    {
        $phoneAsset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'IPHONE-LEGACY',
            'serial_number' => 'SN-IP-LEGACY',
            'status' => 'ready',
        ]);

        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $this->business->id,
            'booking_code' => 'RC-LEGACY-001',
            'affiliate_id' => $this->branch->id,
            'customer_id' => Customer::create(['business_id' => $this->business->id, 'name' => 'Legacy Customer', 'phone' => '08222'])->id,
            'created_by' => $this->owner->id,
            'start_at' => Carbon::now(),
            'end_at' => Carbon::now()->addDay(),
            'duration_hours' => 24,
            'status' => 'rented',
            'subtotal_amount' => 150000.00,
            'total_bill' => 150000.00,
            'payment_status' => 'paid',
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'asset_id' => $phoneAsset->id,
            'unit_price' => 150000.00,
        ]);

        $freshBooking = Booking::with('items.asset.model')->findOrFail($booking->id);
        $this->assertEquals($phoneAsset->id, $freshBooking->active_asset->id);
        $this->assertEquals('iPhone 13 128GB — Midnight', $freshBooking->active_asset->model->name);
    }
}
