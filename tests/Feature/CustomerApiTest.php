<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected Affiliate $affiliate;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        $this->affiliate = Affiliate::create([
            'name' => 'Branch Mataram',
            'slug' => 'branch-mataram',
            'code' => 'MTR',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'affiliate_id' => $this->affiliate->id,
            'name' => 'Staff Customer',
            'email' => 'staff.customer@rentcore.id',
            'password' => bcrypt('password123'),
            'role' => 'staff',
            'is_active' => true,
        ]);
    }

    public function test_can_search_and_lookup_customer_by_phone(): void
    {
        $customer = Customer::create([
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'budi@example.com',
            'address' => 'Jl. Majapahit No. 10',
        ]);

        CustomerIdentity::create([
            'customer_id' => $customer->id,
            'identity_type' => 'KTP',
            'identity_number' => '5271012345670001',
            'is_verified' => true,
        ]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/v1/customers/lookup?query=081234567890');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $customer->id,
                    'name' => 'Budi Santoso',
                    'phone' => '081234567890',
                ],
            ]);

        $this->assertCount(1, $response->json('data.identities'));
        $this->assertEquals('5271012345670001', $response->json('data.identities.0.identity_number'));
    }

    public function test_can_lookup_customer_by_nik(): void
    {
        $customer = Customer::create([
            'name' => 'Siti Rahma',
            'phone' => '089876543210',
            'email' => 'siti@example.com',
        ]);

        CustomerIdentity::create([
            'customer_id' => $customer->id,
            'identity_type' => 'KTP',
            'identity_number' => '3201019988770005',
            'is_verified' => true,
        ]);

        $response = $this->actingAs($this->staff)
            ->getJson('/api/v1/customers/lookup?query=3201019988770005');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Siti Rahma',
                    'phone' => '089876543210',
                ],
            ]);
    }

    public function test_can_create_customer_with_identity(): void
    {
        $payload = [
            'name' => 'Ahmad Dahlan',
            'phone' => '085200112233',
            'email' => 'ahmad@example.com',
            'address' => 'Mataram Square',
            'identity_type' => 'SIM',
            'identity_number' => 'SIM-99881122',
        ];

        $response = $this->actingAs($this->staff)
            ->postJson('/api/v1/customers', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Ahmad Dahlan',
                    'phone' => '085200112233',
                ],
            ]);

        $this->assertDatabaseHas('customers', [
            'phone' => '085200112233',
            'name' => 'Ahmad Dahlan',
        ]);

        $this->assertDatabaseHas('customer_identities', [
            'identity_type' => 'SIM',
            'identity_number' => 'SIM-99881122',
        ]);
    }

    public function test_can_upload_customer_identity_document(): void
    {
        $customer = Customer::create([
            'name' => 'Dewi Lestari',
            'phone' => '087711223344',
        ]);

        $file = UploadedFile::fake()->image('ktp_dewi.jpg', 600, 400);

        $response = $this->actingAs($this->staff)
            ->postJson("/api/v1/customers/{$customer->id}/identity-document", [
                'identity_type' => 'KTP',
                'identity_number' => '5271018899001122',
                'photo' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'identity_type' => 'KTP',
                    'identity_number' => '5271018899001122',
                ],
            ]);

        $this->assertNotNull($response->json('data.file_path'));
        Storage::disk('local')->assertExists($response->json('data.file_path'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer_identity_uploaded',
            'entity_type' => 'customer_identities',
        ]);
    }

    public function test_can_toggle_customer_blacklist(): void
    {
        $customer = Customer::create([
            'name' => 'Oknum Rental',
            'phone' => '081199998888',
            'is_blacklisted' => false,
        ]);

        // Blacklist customer
        $response = $this->actingAs($this->staff)
            ->putJson("/api/v1/customers/{$customer->id}/blacklist", [
                'is_blacklisted' => true,
                'blacklist_reason' => 'Pernah terlambat 3 hari dan unit kotor.',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_blacklisted' => true,
                    'blacklist_reason' => 'Pernah terlambat 3 hari dan unit kotor.',
                ],
            ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'is_blacklisted' => true,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer_blacklist_updated',
        ]);
    }
}
