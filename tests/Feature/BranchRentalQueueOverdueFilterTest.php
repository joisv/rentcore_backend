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
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchRentalQueueOverdueFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Business $businessA;
    protected Business $businessB;
    protected Affiliate $branchA1;
    protected Affiliate $branchA2;
    protected Affiliate $branchB1;
    protected User $ownerA;
    protected User $staffA1;
    protected User $staffA2;
    protected User $staffB1;
    protected AssetModel $model;
    protected Customer $customerA;
    protected Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Owners
        $this->ownerA = User::create([
            'name' => 'Owner Bisnis A',
            'email' => 'owner.a@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $ownerB = User::create([
            'name' => 'Owner Bisnis B',
            'email' => 'owner.b@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        // 2. Business A & B
        $this->businessA = Business::create([
            'name' => 'Bisnis Rental A',
            'slug' => 'bisnis-rental-a',
            'owner_id' => $this->ownerA->id,
            'is_active' => true,
        ]);

        $this->businessB = Business::create([
            'name' => 'Bisnis Rental B',
            'slug' => 'bisnis-rental-b',
            'owner_id' => $ownerB->id,
            'is_active' => true,
        ]);

        $this->branchA1 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'PST',
            'name' => 'Cabang Pusat A',
            'slug' => 'cabang-pusat-a',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->branchA2 = Affiliate::create([
            'business_id' => $this->businessA->id,
            'code' => 'BRT',
            'name' => 'Cabang Barat A',
            'slug' => 'cabang-barat-a',
            'is_central' => false,
            'is_active' => true,
        ]);

        $this->ownerA->update([
            'affiliate_id' => $this->branchA1->id,
            'business_id' => $this->businessA->id,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->staffA1 = User::create([
            'name' => 'Staff Cabang Pusat A',
            'email' => 'staff.a1@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA1->id,
            'business_id' => $this->businessA->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA1->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->staffA2 = User::create([
            'name' => 'Staff Cabang Barat A',
            'email' => 'staff.a2@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchA2->id,
            'business_id' => $this->businessA->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessA->id,
            'user_id' => $this->staffA2->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        // 3. Business B Branches & Users
        $this->branchB1 = Affiliate::create([
            'business_id' => $this->businessB->id,
            'code' => 'BB1',
            'name' => 'Cabang B1',
            'slug' => 'cabang-b1',
            'is_central' => true,
            'is_active' => true,
        ]);

        $this->staffB1 = User::create([
            'name' => 'Staff Cabang B1',
            'email' => 'staff.b1@rentcore.id',
            'password' => Hash::make('password'),
            'role' => 'staff',
            'affiliate_id' => $this->branchB1->id,
            'business_id' => $this->businessB->id,
            'is_active' => true,
        ]);

        BusinessMember::create([
            'business_id' => $this->businessB->id,
            'user_id' => $this->staffB1->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        // 4. Asset Model & Category
        $cat = AssetCategory::create([
            'business_id' => $this->businessA->id,
            'name' => 'Smartphone',
            'slug' => 'smartphone',
        ]);

        $this->model = AssetModel::create([
            'business_id' => $this->businessA->id,
            'category_id' => $cat->id,
            'name' => 'iPhone 15 Pro Max',
            'slug' => 'iphone-15-pro-max',
            'brand' => 'Apple',
        ]);

        $this->customerA = Customer::create([
            'business_id' => $this->businessA->id,
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'identity_number' => '3201010101010001',
        ]);

        $this->customerB = Customer::create([
            'business_id' => $this->businessB->id,
            'name' => 'Siti Nurhaliza',
            'phone' => '089876543210',
            'identity_number' => '3202020202020002',
        ]);
    }

    private function createBooking(
        Business $business,
        Affiliate $branch,
        User $creator,
        Customer $customer,
        string $status,
        Carbon $startAt,
        Carbon $endAt,
        string $code,
        ?Asset $asset = null
    ): Booking {
        $booking = Booking::withoutGlobalScopes()->create([
            'business_id' => $business->id,
            'affiliate_id' => $branch->id,
            'booking_code' => $code,
            'customer_id' => $customer->id,
            'created_by' => $creator->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'duration_hours' => 24,
            'duration_unit' => 'day',
            'duration_value' => 1,
            'status' => $status,
            'subtotal_amount' => 300000,
            'deposit_fee' => 0,
            'discount_amount' => 0,
            'total_bill' => 300000,
            'payment_status' => 'paid',
        ]);

        if ($asset) {
            BookingItem::create([
                'booking_id' => $booking->id,
                'asset_id' => $asset->id,
                'unit_price' => 300000,
                'duration_unit' => 'day',
                'duration_value' => 1,
            ]);
        }

        return $booking;
    }

    public function test_branch_staff_can_view_overdue_bookings_for_assigned_branch(): void
    {
        $asset = Asset::withoutGlobalScopes()->create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IPHONE-A1-01',
            'serial_number' => 'SN-A1-01',
            'status' => 'rented',
        ]);

        // Overdue booking for Branch A1
        $overdueBooking = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(3),
            'RC-A1-OVERDUE-1',
            $asset
        );

        // Active non-overdue booking for Branch A1
        $activeBooking = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'rented',
            Carbon::now()->subHours(2),
            Carbon::now()->addHours(22),
            'RC-A1-ACTIVE-1'
        );

        $response = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA1->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals($overdueBooking->id, $data[0]['id']);
        $this->assertEquals('RC-A1-OVERDUE-1', $data[0]['booking_code']);
    }

    public function test_branch_staff_cannot_view_overdue_bookings_for_unassigned_branch(): void
    {
        $response = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA2->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue&branch_id=' . $this->branchA2->id);

        $response->assertStatus(403);
    }

    public function test_branch_staff_cannot_request_all_branches(): void
    {
        $response = $this->actingAs($this->staffA2, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA2->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue&all_branches=1');

        $response->assertStatus(403);
    }

    public function test_owner_can_filter_overdue_bookings_by_specific_branch_via_query_param(): void
    {
        // Overdue in Branch A1
        $bookingA1 = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->ownerA,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(5),
            'RC-A1-OD'
        );

        // Overdue in Branch A2
        $bookingA2 = $this->createBooking(
            $this->businessA,
            $this->branchA2,
            $this->staffA2,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(2),
            'RC-A2-OD'
        );

        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue&branch_id=' . $this->branchA1->id);

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals($bookingA1->id, $data[0]['id']);
    }

    public function test_owner_can_filter_overdue_bookings_by_specific_branch_via_x_branch_id_header(): void
    {
        $bookingA1 = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->ownerA,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(5),
            'RC-A1-HEADER-OD'
        );

        $bookingA2 = $this->createBooking(
            $this->businessA,
            $this->branchA2,
            $this->staffA2,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(2),
            'RC-A2-HEADER-OD'
        );

        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA2->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals($bookingA2->id, $data[0]['id']);
    }

    public function test_owner_can_view_consolidated_overdue_with_all_branches_flag(): void
    {
        $bookingA1 = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->ownerA,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(5),
            'RC-ALL-A1'
        );

        $bookingA2 = $this->createBooking(
            $this->businessA,
            $this->branchA2,
            $this->staffA2,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(2),
            'RC-ALL-A2'
        );

        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue&all_branches=1');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(2, $data);
        $codes = collect($data)->pluck('booking_code')->all();
        $this->assertContains('RC-ALL-A1', $codes);
        $this->assertContains('RC-ALL-A2', $codes);
    }

    public function test_owner_cannot_query_branch_of_another_business(): void
    {
        $response = $this->actingAs($this->ownerA, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue&branch_id=' . $this->branchB1->id);

        $response->assertStatus(404);
    }

    public function test_overdue_filter_excludes_active_rentals_whose_deadline_is_in_the_future(): void
    {
        $futureBooking = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'rented',
            Carbon::now()->subHours(2),
            Carbon::now()->addHours(6),
            'RC-FUTURE-1'
        );

        $response = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA1->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEmpty($data);
    }

    public function test_overdue_filter_includes_rentals_at_exact_deadline_boundary(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        $boundaryBooking = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'rented',
            $now->copy()->subHours(24),
            $now, // Exactly at now
            'RC-EXACT-NOW'
        );

        $response = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA1->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue');

        Carbon::setTestNow();

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals($boundaryBooking->id, $data[0]['id']);
    }

    public function test_overdue_filter_strictly_excludes_returned_and_completed_bookings(): void
    {
        $returnedBooking = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'returned',
            Carbon::now()->subDays(3),
            Carbon::now()->subDays(1), // Returned late, but return is already completed
            'RC-RETURNED-COMPLETED'
        );

        $response = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA1->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEmpty($data);
    }

    public function test_overdue_booking_displays_device_details_even_if_asset_transferred_to_another_branch(): void
    {
        // 1. Create asset initially at Branch A1
        $asset = Asset::withoutGlobalScopes()->create([
            'business_id' => $this->businessA->id,
            'affiliate_id' => $this->branchA1->id,
            'model_id' => $this->model->id,
            'asset_code' => 'IPHONE-TRANSFER-1',
            'serial_number' => 'SN-TRANS-1',
            'status' => 'rented',
        ]);

        // 2. Booking created at Branch A1 with this asset
        $booking = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(4),
            'RC-TRANSFER-OD',
            $asset
        );

        // 3. Asset is later reassigned/transferred to Branch A2
        $asset->update(['affiliate_id' => $this->branchA2->id]);

        // 4. Staff of Branch A1 queries overdue bookings
        $response = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA1->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals($booking->id, $data[0]['id']);

        // Check that asset information is resolved and NOT null
        $item = $data[0]['items'][0];
        $this->assertNotNull($item['asset']);
        $this->assertEquals('IPHONE-TRANSFER-1', $item['asset']['asset_code']);
        $this->assertEquals('iPhone 15 Pro Max', $item['asset']['model']['name']);
    }

    public function test_overdue_filter_strictly_enforces_business_isolation_across_tenants(): void
    {
        // Overdue booking for Business B
        $bookingB = $this->createBooking(
            $this->businessB,
            $this->branchB1,
            $this->staffB1,
            $this->customerB,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(4),
            'RC-BIZ-B-OD'
        );

        // User from Business A queries overdue bookings
        $response = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchA1->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEmpty($data);

        // User from Business A cannot pass Business B's branch
        $forbiddenResponse = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders([
                'X-Business-Id' => (string)$this->businessA->id,
                'X-Branch-Id' => (string)$this->branchB1->id,
            ])
            ->getJson('/api/v1/bookings?status=overdue');

        $forbiddenResponse->assertStatus(403);
    }

    public function test_switching_filters_between_rented_overdue_all_returned_returns_correct_subsets(): void
    {
        // 1. Active normal rental
        $active = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'rented',
            Carbon::now()->subHours(1),
            Carbon::now()->addHours(10),
            'RC-ACTIVE'
        );

        // 2. Overdue rental
        $overdue = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(3),
            'RC-OVERDUE'
        );

        // 3. Completed returned
        $returned = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'returned',
            Carbon::now()->subDays(3),
            Carbon::now()->subDays(1),
            'RC-RETURNED'
        );

        $headers = [
            'X-Business-Id' => (string)$this->businessA->id,
            'X-Branch-Id' => (string)$this->branchA1->id,
        ];

        // Filter: overdue -> should only return $overdue
        $resOverdue = $this->actingAs($this->staffA1, 'sanctum')->withHeaders($headers)->getJson('/api/v1/bookings?status=overdue');
        $resOverdue->assertStatus(200);
        $dataOverdue = $resOverdue->json('data.data');
        $this->assertCount(1, $dataOverdue);
        $this->assertEquals($overdue->id, $dataOverdue[0]['id']);

        // Filter: rented -> returns all active rentals (both active and overdue having status 'rented')
        $resRented = $this->actingAs($this->staffA1, 'sanctum')->withHeaders($headers)->getJson('/api/v1/bookings?status=rented');
        $resRented->assertStatus(200);
        $dataRented = $resRented->json('data.data');
        $this->assertCount(2, $dataRented);

        // Filter: returned -> only returns $returned
        $resReturned = $this->actingAs($this->staffA1, 'sanctum')->withHeaders($headers)->getJson('/api/v1/bookings?status=returned');
        $resReturned->assertStatus(200);
        $dataReturned = $resReturned->json('data.data');
        $this->assertCount(1, $dataReturned);
        $this->assertEquals($returned->id, $dataReturned[0]['id']);

        // Filter: all (no status param) -> returns all 3 bookings
        $resAll = $this->actingAs($this->staffA1, 'sanctum')->withHeaders($headers)->getJson('/api/v1/bookings');
        $resAll->assertStatus(200);
        $dataAll = $resAll->json('data.data');
        $this->assertCount(3, $dataAll);
    }

    public function test_search_query_works_within_overdue_filter(): void
    {
        $overdue1 = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $this->customerA,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(3),
            'RC-OD-ALPHA'
        );

        $customerSpecial = Customer::create([
            'business_id' => $this->businessA->id,
            'name' => 'Wahyudi Pratama',
            'phone' => '087711223344',
            'identity_number' => '3203030303030003',
        ]);

        $overdue2 = $this->createBooking(
            $this->businessA,
            $this->branchA1,
            $this->staffA1,
            $customerSpecial,
            'rented',
            Carbon::now()->subDays(2),
            Carbon::now()->subHours(4),
            'RC-OD-BETA'
        );

        $headers = [
            'X-Business-Id' => (string)$this->businessA->id,
            'X-Branch-Id' => (string)$this->branchA1->id,
        ];

        // Search by booking code
        $resCode = $this->actingAs($this->staffA1, 'sanctum')->withHeaders($headers)->getJson('/api/v1/bookings?status=overdue&search=ALPHA');
        $resCode->assertStatus(200);
        $dataCode = $resCode->json('data.data');
        $this->assertCount(1, $dataCode);
        $this->assertEquals($overdue1->id, $dataCode[0]['id']);

        // Search by customer name
        $resCust = $this->actingAs($this->staffA1, 'sanctum')->withHeaders($headers)->getJson('/api/v1/bookings?status=overdue&search=Wahyudi');
        $resCust->assertStatus(200);
        $dataCust = $resCust->json('data.data');
        $this->assertCount(1, $dataCust);
        $this->assertEquals($overdue2->id, $dataCust[0]['id']);
    }

    public function test_pagination_works_correctly_on_overdue_filter(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createBooking(
                $this->businessA,
                $this->branchA1,
                $this->staffA1,
                $this->customerA,
                'rented',
                Carbon::now()->subDays(2),
                Carbon::now()->subHours($i),
                "RC-PAGINATE-{$i}"
            );
        }

        $headers = [
            'X-Business-Id' => (string)$this->businessA->id,
            'X-Branch-Id' => (string)$this->branchA1->id,
        ];

        $response = $this->actingAs($this->staffA1, 'sanctum')
            ->withHeaders($headers)
            ->getJson('/api/v1/bookings?status=overdue&per_page=2&page=1');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(5, $response->json('data.total'));
        $this->assertEquals(1, $response->json('data.current_page'));
        $this->assertEquals(3, $response->json('data.last_page'));
    }
}
