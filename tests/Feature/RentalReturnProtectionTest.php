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
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\ReturnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RentalReturnProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Affiliate $branch;
    protected User $inspector;
    protected AssetModel $iphoneModel;
    protected Asset $asset;
    protected Customer $customer;
    protected Booking $booking;
    protected ReturnService $returnService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inspector = User::create([
            'name' => 'Staff Pengembalian',
            'email' => 'staff.return@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->business = Business::create([
            'name' => 'RentCore Test Business',
            'slug' => 'rentcore-test-biz',
            'owner_id' => $this->inspector->id,
            'is_active' => true,
        ]);

        $this->branch = Affiliate::create([
            'business_id' => $this->business->id,
            'name' => 'Cabang Perlindungan',
            'slug' => 'cbg-pld',
            'code' => 'CBG-PLD',
            'address' => 'Jl. Perlindungan No. 1',
            'is_central' => true,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->business->id,
            'user_id' => $this->inspector->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $category = AssetCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'code' => 'SPH',
        ]);

        $this->iphoneModel = AssetModel::create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'iPhone 15 Pro Max',
            'slug' => 'iphone-15-pro-max',
            'brand' => 'Apple',
            'requires_frp_check' => true,
        ]);

        $this->asset = Asset::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'model_id' => $this->iphoneModel->id,
            'asset_code' => 'IPH15P-001',
            'serial_number' => 'SN-IPH15P-001',
            'status' => 'rented',
            'condition_grade' => 'A',
            'battery_health' => 100,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Budi Penyewa',
            'email' => 'budi@rentcore.id',
            'phone' => '081234567890',
            'identity_number' => '3273010101010001',
        ]);

        $this->booking = Booking::create([
            'business_id' => $this->business->id,
            'affiliate_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'created_by' => $this->inspector->id,
            'booking_code' => 'BK-RET-001',
            'status' => 'rented',
            'payment_status' => 'paid',
            'start_at' => Carbon::now()->subDays(2),
            'end_at' => Carbon::now()->addHours(2),
            'duration_unit' => 'hour',
            'duration_value' => 48,
            'duration_hours' => 48,
            'subtotal_amount' => 300000.0,
            'total_bill' => 300000.0,
            'deposit_fee' => 0.0,
        ]);

        BookingItem::create([
            'booking_id' => $this->booking->id,
            'asset_id' => $this->asset->id,
            'unit_price' => 300000.0,
            'duration_unit' => 'hour',
            'duration_value' => 48,
            'status' => 'active',
            'item_type' => 'unit',
        ]);

        $this->returnService = app(ReturnService::class);
    }

    public function test_return_fails_when_apple_device_has_not_signed_out_and_not_quarantined(): void
    {
        $this->expectException(ValidationException::class);

        $this->returnService->processReturn($this->booking, [
            'condition_grade' => 'A',
            'checklist_results' => [
                'icloud_removed' => false,
                'screen_ok' => true,
                'camera_ok' => true,
            ],
            'accessories_returned' => ['cable' => true, 'adapter' => true],
        ], $this->inspector);
    }

    public function test_return_fails_when_quarantined_without_staff_notes(): void
    {
        $this->expectException(ValidationException::class);

        $this->returnService->processReturn($this->booking, [
            'condition_grade' => 'A',
            'checklist_results' => [
                'icloud_removed' => false,
                'account_quarantined' => true,
                'account_status' => 'quarantined',
                'screen_ok' => true,
                'camera_ok' => true,
            ],
            'accessories_returned' => ['cable' => true, 'adapter' => true],
        ], $this->inspector);
    }

    public function test_return_succeeds_when_quarantined_with_staff_notes_and_puts_asset_in_maintenance(): void
    {
        $inspection = $this->returnService->processReturn($this->booking, [
            'condition_grade' => 'A',
            'staff_notes' => 'Pelanggan lupa sandi Apple ID. Dalam proses reset akun bersama staf cabang.',
            'checklist_results' => [
                'icloud_removed' => false,
                'account_quarantined' => true,
                'account_status' => 'quarantined',
                'screen_ok' => true,
                'camera_ok' => true,
            ],
            'accessories_returned' => ['cable' => true, 'adapter' => true],
        ], $this->inspector);

        $this->assertEquals('returned', $this->booking->fresh()->status);
        $this->assertEquals('maintenance', $this->asset->fresh()->status);
        $this->assertEquals('quarantined', $inspection->checklist_results['account_status']);
    }

    public function test_return_succeeds_when_account_cleared_and_puts_asset_in_ready(): void
    {
        $inspection = $this->returnService->processReturn($this->booking, [
            'condition_grade' => 'A',
            'checklist_results' => [
                'icloud_removed' => true,
                'account_status' => 'cleared',
                'screen_ok' => true,
                'camera_ok' => true,
            ],
            'accessories_returned' => ['cable' => true, 'adapter' => true],
        ], $this->inspector);

        $this->assertEquals('returned', $this->booking->fresh()->status);
        $this->assertEquals('ready', $this->asset->fresh()->status);
    }
}
