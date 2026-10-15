<?php

namespace App\Console\Commands;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetModel;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BranchMember;
use App\Models\Customer;
use App\Models\InventoryTransfer;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillLegacyTenancyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenancy:backfill-legacy {--force : Force execution without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely backfill legacy SkyRental data into a default tenant business';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Legacy Tenancy Backfill...');

        // 1. Identify primary owner user
        $owner = User::where('role', 'super_admin')->first() ?? User::first();

        if (!$owner) {
            $this->error('No users found in database to assign as legacy business owner.');
            return Command::FAILURE;
        }

        $this->line("Primary owner identified: {$owner->name} ({$owner->email})");

        return DB::transaction(function () use ($owner) {
            // 2. Find or create default business
            $business = Business::firstOrCreate(
                ['slug' => 'rentcore-network'],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => 'RentCore Network',
                    'email' => $owner->email,
                    'phone' => $owner->phone ?? '0812-3456-0001',
                    'currency' => 'IDR',
                    'timezone' => 'Asia/Jakarta',
                    'owner_id' => $owner->id,
                    'is_active' => true,
                ]
            );

            $this->info("Default business: {$business->name} (ID: {$business->id})");

            // 3. Backfill users into business_members & branch_members
            $users = User::all();
            $membersCount = 0;
            $branchMembersCount = 0;

            foreach ($users as $user) {
                $role = match ($user->role) {
                    'super_admin' => 'owner',
                    'affiliate_admin' => 'admin',
                    default => 'staff',
                };

                $member = BusinessMember::firstOrCreate(
                    [
                        'business_id' => $business->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'role' => $role,
                        'is_active' => $user->is_active,
                        'joined_at' => $user->created_at ?? Carbon::now(),
                    ]
                );

                $membersCount++;

                if ($user->affiliate_id) {
                    BranchMember::firstOrCreate([
                        'business_member_id' => $member->id,
                        'affiliate_id' => $user->affiliate_id,
                    ]);
                    $branchMembersCount++;
                }
            }

            $this->line("Synced {$membersCount} business members and {$branchMembersCount} branch members.");

            // 4. Backfill entities
            $affiliatesCount = Affiliate::whereNull('business_id')->update(['business_id' => $business->id]);
            $categoriesCount = AssetCategory::whereNull('business_id')->update(['business_id' => $business->id]);
            $modelsCount     = AssetModel::whereNull('business_id')->update(['business_id' => $business->id]);
            $assetsCount     = Asset::withoutGlobalScopes()->whereNull('business_id')->update(['business_id' => $business->id]);
            $customersCount  = Customer::whereNull('business_id')->update(['business_id' => $business->id]);
            $bookingsCount   = Booking::withoutGlobalScopes()->whereNull('business_id')->update(['business_id' => $business->id]);
            $vouchersCount   = Voucher::whereNull('business_id')->update(['business_id' => $business->id]);
            $paymentsCount   = PaymentTransaction::whereNull('business_id')->update(['business_id' => $business->id]);
            $transfersCount  = InventoryTransfer::whereNull('business_id')->update(['business_id' => $business->id]);
            $auditLogsCount  = AuditLog::whereNull('business_id')->update(['business_id' => $business->id]);

            $this->table(
                ['Entity', 'Backfilled Rows'],
                [
                    ['Affiliates (Branches)', $affiliatesCount],
                    ['Asset Categories', $categoriesCount],
                    ['Asset Models (Products)', $modelsCount],
                    ['Assets (Units)', $assetsCount],
                    ['Customers', $customersCount],
                    ['Bookings', $bookingsCount],
                    ['Vouchers', $vouchersCount],
                    ['Payment Transactions', $paymentsCount],
                    ['Inventory Transfers', $transfersCount],
                    ['Audit Logs', $auditLogsCount],
                ]
            );

            $this->info('Legacy Tenancy Backfill completed successfully!');
            return Command::SUCCESS;
        });
    }
}
