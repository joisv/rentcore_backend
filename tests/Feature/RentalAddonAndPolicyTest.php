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
use App\Services\ReturnService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RentalAddonAndPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected User $otherOwner;
    protected Business $business;
    protected Business $otherBusiness;
    protected Affiliate $branch;
    protected Affiliate $otherBranch;
    protected PaymentMethod $cashMethod;
    protected AssetModel $iphoneModel;
    protected AssetModel $powerBankModel;
    protected AssetModel $chargerModel;
    protected AssetCategory $smartphoneCategory;
    protected AssetCategory $addonCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Payment Method
        $this->cashMethod = PaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Tunai',
            'is_active' => true,
        ]);

        // 2. Users
        $this->owner = User::create([
            'name' => 'Owner Gwen',
            'email' => 'owner@gwen.test',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Staff Gwen',
            'email' => 'staff@gwen.test',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->otherOwner = User::create([
            'name' => 'Other Owner',
            'email' => 'other@test.test',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        // 3. Businesses
        $this->business = Business::create([
            'name' => 'Gwenn Rental',
            'owner_id' => $this->owner->id,
            'is_active' => true,
            'id_document_limit_enabled' => true,
            'max_primary_devices_per_id' => 1,
            'addons_enabled' => true,
            'max_addons_per_rental' => 5,
        ]);

        $this->otherBusiness = Business::create([
            'name' => 'Other Rental',
            'owner_id' => $this->otherOwner->id,
            'is_active' => true,
            'id_document_limit_enabled' => true,
            'max_primary_devices_per_id' => 1,
            'addons_enabled' => true,
            'max_addons_per_rental' => 5,
        ]);

        // 4. Business Members
        $ownerMember = BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->staff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->otherBusiness->id,
            'user_id' => $this->otherOwner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 5. Branches
        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'code' => 'GWP',
            'name' => 'Gwenn Pusat',
            'slug' => 'gwenn-pusat',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->otherBranch = Affiliate::create([
            'business_id' => $this->otherBusiness->id,
            'code' => 'OTH',
            'name' => 'Other Branch',
            'slug' => 'other-branch',
            'is_central' => true,
            'is_active' => true,
        ]);

        // 6. Categories
        $this->smartphoneCategory = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->addonCategory = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Accessories',
            'slug' => 'accessories',
            'is_addon' => true,
            'is_active' => true,
        ]);

        // 7. Models
        $this->iphoneModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->smartphoneCategory->id,
            'brand' => 'Apple',
            'name' => 'iPhone 15 Pro',
            'slug' => 'iphone-15-pro',
            'is_addon' => false,
            'is_active' => true,
        ]);

        $this->powerBankModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->addonCategory->id,
            'brand' => 'Anker',
            'name' => 'Power Bank 20000mAh',
            'slug' => 'anker-pb-20k',
            'is_addon' => true,
            'is_active' => true,
        ]);

        $this->chargerModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $this->addonCategory->id,
            'brand' => 'Apple',
            'name' => '20W USB-C Charger',
            'slug' => 'apple-20w-charger',
            'is_addon' => true,
            'is_active' => true,
        ]);

        // 8. Pricing Tiers (24h = 1 day)
        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $this->iphoneModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 250000.00,
            'extension_hourly_rate' => 15000.00,
            'is_active' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $this->powerBankModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 30000.00,
            'extension_hourly_rate' => 5000.00,
            'is_active' => true,
        ]);

        PricingTier::create([
            'business_id' => $this->business->id,
            'model_id' => $this->chargerModel->id,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'duration_hours' => 24,
            'price' => 15000.00,
            'extension_hourly_rate' => 2000.00,
            'is_active' => true,
        ]);

        // Set TenantContext
        app(TenantContext::class)->set($this->business, $ownerMember, $this->branch);
    }

    protected function createUnit(AssetModel $model, string $code): Asset
    {
        return Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $model->id,
            'asset_code' => $code,
            'serial_number' => 'SN-' . $code,
            'status' => 'ready',
            'condition_grade' => 'A',
            'battery_health' => 100,
        ]);
    }

    /**
     * 1. Test Owner can read and update rental rules via API.
     */
    public function test_owner_can_get_and_update_rental_rules(): void
    {
        $this->actingAs($this->owner);

        // Get rules
        $response = $this->withHeaders([
            'X-Business-Id' => $this->business->id,
            'X-Branch-Id' => $this->branch->id,
        ])->getJson("/api/v1/businesses/{$this->business->id}/rental-rules");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'id_document_limit_enabled' => true,
                'max_primary_devices_per_id' => 1,
                'addons_enabled' => true,
                'max_addons_per_rental' => 5,
            ],
        ]);

        // Update rules
        $updateResponse = $this->withHeaders([
            'X-Business-Id' => $this->business->id,
            'X-Branch-Id' => $this->branch->id,
        ])->putJson("/api/v1/businesses/{$this->business->id}/rental-rules", [
            'id_document_limit_enabled' => false,
            'max_primary_devices_per_id' => 3,
            'max_addons_per_rental' => 10,
        ]);

        $updateResponse->assertStatus(200);
        $this->business->refresh();
        $this->assertFalse($this->business->id_document_limit_enabled);
        $this->assertEquals(3, $this->business->max_primary_devices_per_id);
        $this->assertEquals(10, $this->business->max_addons_per_rental);
    }

    /**
     * 2. Test unauthorized staff cannot modify rental rules.
     */
    public function test_staff_cannot_modify_rental_rules(): void
    {
        $this->actingAs($this->staff);

        $response = $this->withHeaders([
            'X-Business-Id' => $this->business->id,
            'X-Branch-Id' => $this->branch->id,
        ])->putJson("/api/v1/businesses/{$this->business->id}/rental-rules", [
            'id_document_limit_enabled' => false,
        ]);

        $response->assertStatus(403);
    }

    /**
     * 3. Test cross-tenant isolation: Owner of Business A cannot access or modify Business B rules.
     */
    public function test_cross_tenant_policy_isolation(): void
    {
        $this->actingAs($this->owner);

        $response = $this->withHeaders([
            'X-Business-Id' => $this->business->id,
            'X-Branch-Id' => $this->branch->id,
        ])->putJson("/api/v1/businesses/{$this->otherBusiness->id}/rental-rules", [
            'id_document_limit_enabled' => false,
        ]);

        $response->assertStatus(403);
    }

    /**
     * 4. Test 1 primary device + multiple add-ons succeeds and subtotal is calculated accurately.
     */
    public function test_one_primary_device_plus_multiple_addons_succeeds(): void
    {
        $iphone = $this->createUnit($this->iphoneModel, 'IP-01');
        $pb = $this->createUnit($this->powerBankModel, 'PB-01');
        $charger = $this->createUnit($this->chargerModel, 'CHG-01');

        $bookingService = app(BookingService::class);

        $booking = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '081234567890',
            'identity_type' => 'KTP',
            'identity_number' => '3501234567890001',
            'asset_ids' => [$iphone->id],
            'addon_asset_ids' => [$pb->id, $charger->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 295000.00,
        ], $this->owner);

        $this->assertNotNull($booking);
        $this->assertEquals('rented', $booking->status);
        $this->assertEquals(250000.00, (float)$booking->primary_subtotal);
        $this->assertEquals(45000.00, (float)$booking->addon_subtotal); // 30000 + 15000
        $this->assertEquals(295000.00, (float)$booking->subtotal_amount);
        $this->assertEquals(295000.00, (float)$booking->total_bill);

        // Check BookingItems tagging
        $items = BookingItem::where('booking_id', $booking->id)->get();
        $this->assertCount(3, $items);
        $primaryItem = $items->firstWhere('asset_id', $iphone->id);
        $this->assertEquals('primary', $primaryItem->item_type);

        $addonPb = $items->firstWhere('asset_id', $pb->id);
        $this->assertEquals('addon', $addonPb->item_type);

        $addonChg = $items->firstWhere('asset_id', $charger->id);
        $this->assertEquals('addon', $addonChg->item_type);

        // Assets transitioned to rented
        $this->assertEquals('rented', $iphone->fresh()->status);
        $this->assertEquals('rented', $pb->fresh()->status);
        $this->assertEquals('rented', $charger->fresh()->status);
    }

    /**
     * 5. Test enabled limit prevents a second primary device under the same identity document.
     */
    public function test_enabled_limit_prevents_second_primary_device(): void
    {
        $iphone1 = $this->createUnit($this->iphoneModel, 'IP-01');
        $iphone2 = $this->createUnit($this->iphoneModel, 'IP-02');

        $bookingService = app(BookingService::class);

        // First rental with KTP: 35010001
        $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Alice',
            'customer_phone' => '081234560001',
            'identity_type' => 'KTP',
            'identity_number' => '3501-0001',
            'asset_ids' => [$iphone1->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.00,
        ], $this->owner);

        // Second rental attempt with same KTP (with formatting spaces/dashes)
        $this->expectException(ValidationException::class);

        $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Alice Kurnia',
            'customer_phone' => '081234560001',
            'identity_type' => 'KTP',
            'identity_number' => '3501 0001', // Normalized matches 35010001
            'asset_ids' => [$iphone2->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.00,
        ], $this->owner);
    }

    /**
     * 6. Test add-ons do NOT count toward the primary-device limit.
     * Alice has 1 primary device active. She can rent additional add-ons on a subsequent booking.
     */
    public function test_addons_do_not_count_toward_primary_limit(): void
    {
        $iphone1 = $this->createUnit($this->iphoneModel, 'IP-01');
        $pb = $this->createUnit($this->powerBankModel, 'PB-01');

        $bookingService = app(BookingService::class);

        // First rental: 1 iPhone
        $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Alice',
            'customer_phone' => '081234560001',
            'identity_type' => 'KTP',
            'identity_number' => '35010002',
            'asset_ids' => [$iphone1->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.00,
        ], $this->owner);

        // Second rental: customer needs an extra power bank add-on without a primary device
        $addonBooking = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Alice',
            'customer_phone' => '081234560001',
            'identity_type' => 'KTP',
            'identity_number' => '35010002',
            'asset_ids' => [],
            'addon_asset_ids' => [$pb->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 30000.00,
        ], $this->owner);

        $this->assertNotNull($addonBooking);
        $this->assertEquals(0.00, (float)$addonBooking->primary_subtotal);
        $this->assertEquals(30000.00, (float)$addonBooking->addon_subtotal);
        $this->assertEquals('rented', $pb->fresh()->status);
    }

    /**
     * 7. Test disabling the limit allows a second primary device.
     */
    public function test_disabling_limit_permits_multiple_primary_devices(): void
    {
        $this->business->update(['id_document_limit_enabled' => false]);

        $iphone1 = $this->createUnit($this->iphoneModel, 'IP-01');
        $iphone2 = $this->createUnit($this->iphoneModel, 'IP-02');

        $bookingService = app(BookingService::class);

        // First rental
        $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Bob',
            'customer_phone' => '081234560002',
            'identity_type' => 'KTP',
            'identity_number' => '35010003',
            'asset_ids' => [$iphone1->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.00,
        ], $this->owner);

        // Second rental with same KTP succeeds because limit is disabled
        $booking2 = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Bob',
            'customer_phone' => '081234560002',
            'identity_type' => 'KTP',
            'identity_number' => '35010003',
            'asset_ids' => [$iphone2->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.00,
        ], $this->owner);

        $this->assertNotNull($booking2);
        $this->assertEquals('rented', $iphone2->fresh()->status);
    }

    /**
     * 8. Test add-on quantity limit is enforced.
     */
    public function test_addon_quantity_limit_enforced(): void
    {
        $this->business->update(['max_addons_per_rental' => 1]);

        $iphone = $this->createUnit($this->iphoneModel, 'IP-01');
        $pb1 = $this->createUnit($this->powerBankModel, 'PB-01');
        $pb2 = $this->createUnit($this->powerBankModel, 'PB-02');

        $bookingService = app(BookingService::class);

        $this->expectException(ValidationException::class);

        $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Charlie',
            'customer_phone' => '081234560003',
            'identity_type' => 'KTP',
            'identity_number' => '35010004',
            'asset_ids' => [$iphone->id],
            'addon_asset_ids' => [$pb1->id, $pb2->id], // 2 add-ons when max is 1
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 310000.00,
        ], $this->owner);
    }

    /**
     * 9. Test double-booking of physical add-on is prevented.
     */
    public function test_double_booking_physical_addon_prevented(): void
    {
        $iphone1 = $this->createUnit($this->iphoneModel, 'IP-01');
        $iphone2 = $this->createUnit($this->iphoneModel, 'IP-02');
        $pb = $this->createUnit($this->powerBankModel, 'PB-01');

        $bookingService = app(BookingService::class);

        // Rent PB with iPhone 1
        $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Customer 1',
            'customer_phone' => '081234560010',
            'identity_type' => 'KTP',
            'identity_number' => '35010010',
            'asset_ids' => [$iphone1->id],
            'addon_asset_ids' => [$pb->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 280000.00,
        ], $this->owner);

        // Attempt to rent same PB with iPhone 2
        $this->expectException(ValidationException::class);

        $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Customer 2',
            'customer_phone' => '081234560011',
            'identity_type' => 'KTP',
            'identity_number' => '35010011',
            'asset_ids' => [$iphone2->id],
            'addon_asset_ids' => [$pb->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 280000.00,
        ], $this->owner);
    }

    /**
     * 10. Test return inspection transitions both primary and add-on assets back to 'ready'.
     */
    public function test_return_inspection_restores_all_assets_to_ready(): void
    {
        $iphone = $this->createUnit($this->iphoneModel, 'IP-01');
        $pb = $this->createUnit($this->powerBankModel, 'PB-01');

        $bookingService = app(BookingService::class);
        $returnService = app(ReturnService::class);

        $booking = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Danu',
            'customer_phone' => '081234560020',
            'identity_type' => 'KTP',
            'identity_number' => '35010020',
            'asset_ids' => [$iphone->id],
            'addon_asset_ids' => [$pb->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 280000.00,
        ], $this->owner);

        $this->assertEquals('rented', $iphone->fresh()->status);
        $this->assertEquals('rented', $pb->fresh()->status);

        // Process return
        $returnService->processReturn($booking, [
            'checklist_results' => ['icloud_removed' => true],
            'condition_grade' => 'A',
            'accessories_returned' => ['power_bank' => true, 'cable' => true],
        ], $this->owner);

        $this->assertEquals('returned', $booking->fresh()->status);
        $this->assertEquals('ready', $iphone->fresh()->status);
        $this->assertEquals('ready', $pb->fresh()->status);
    }

    /**
     * 11. Test standard primary-device rental without add-ons works seamlessly.
     */
    public function test_standard_primary_device_rental_without_addons(): void
    {
        $iphone = $this->createUnit($this->iphoneModel, 'IP-01');

        $booking = app(BookingService::class)->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Eko',
            'customer_phone' => '081234560030',
            'identity_type' => 'KTP',
            'identity_number' => '35010030',
            'asset_ids' => [$iphone->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.00,
        ], $this->owner);

        $this->assertNotNull($booking);
        $this->assertEquals(250000.00, (float)$booking->primary_subtotal);
        $this->assertEquals(0.00, (float)$booking->addon_subtotal);
        $this->assertEquals(250000.00, (float)$booking->total_bill);
    }

    /**
     * 12. Test master toggle: disabling add-ons rejects any add-on rental attempt.
     */
    public function test_disabling_addons_rejects_addon_rentals(): void
    {
        $this->business->update(['addons_enabled' => false]);

        $iphone = $this->createUnit($this->iphoneModel, 'IP-01');
        $pb = $this->createUnit($this->powerBankModel, 'PB-01');

        $this->expectException(ValidationException::class);

        app(BookingService::class)->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Fajar',
            'customer_phone' => '081234560040',
            'identity_type' => 'KTP',
            'identity_number' => '35010040',
            'asset_ids' => [$iphone->id],
            'addon_asset_ids' => [$pb->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 280000.00,
        ], $this->owner);
    }

    /**
     * 13. Test returned bookings do not block new primary rentals under quota.
     */
    public function test_returned_booking_does_not_block_subsequent_rental(): void
    {
        $iphone1 = $this->createUnit($this->iphoneModel, 'IP-01');
        $iphone2 = $this->createUnit($this->iphoneModel, 'IP-02');

        $bookingService = app(BookingService::class);
        $returnService = app(ReturnService::class);

        // Rent unit 1
        $booking1 = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Gina',
            'customer_phone' => '081234560050',
            'identity_type' => 'KTP',
            'identity_number' => '35010050',
            'asset_ids' => [$iphone1->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.00,
        ], $this->owner);

        // Return unit 1
        $returnService->processReturn($booking1, [
            'checklist_results' => ['icloud_removed' => true],
            'condition_grade' => 'A',
        ], $this->owner);

        $this->assertEquals('returned', $booking1->fresh()->status);

        // Rent unit 2 with same KTP succeeds because previous booking is returned!
        $booking2 = $bookingService->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Gina',
            'customer_phone' => '081234560050',
            'identity_type' => 'KTP',
            'identity_number' => '35010050',
            'asset_ids' => [$iphone2->id],
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 250000.00,
        ], $this->owner);

        $this->assertNotNull($booking2);
        $this->assertEquals('rented', $iphone2->fresh()->status);
    }

    /**
     * 14. Test renting two primary devices in a single transaction rejected when limit is 1.
     */
    public function test_renting_two_primary_devices_single_transaction_rejected(): void
    {
        $iphone1 = $this->createUnit($this->iphoneModel, 'IP-01');
        $iphone2 = $this->createUnit($this->iphoneModel, 'IP-02');

        $this->expectException(ValidationException::class);

        app(BookingService::class)->createCounterRental([
            'affiliate_id' => $this->branch->id,
            'customer_name' => 'Hadi',
            'customer_phone' => '081234560060',
            'identity_type' => 'KTP',
            'identity_number' => '35010060',
            'asset_ids' => [$iphone1->id, $iphone2->id], // 2 primary devices in 1 rental
            'duration_unit' => 'day',
            'duration_value' => 1,
            'payment_method_id' => $this->cashMethod->id,
            'cash_received' => 500000.00,
        ], $this->owner);
    }
}
