<?php

namespace App\Console\Commands;

use App\Models\Affiliate;
use App\Models\Business;
use Illuminate\Console\Command;

class CleanDefaultDenpasarCommand extends Command
{
    protected $signature = 'branches:clean-default-city
        {--dry-run : Preview affected businesses and branches without updating}
        {--all : Clear every city=Denpasar row regardless of address}';

    protected $description = 'Clears legacy hardcoded "Denpasar" default city values on businesses and branches';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $clearAll = (bool) $this->option('all');

        $bizQuery = Business::query()
            ->whereRaw("LOWER(TRIM(COALESCE(city, ''))) = ?", ['denpasar']);
        $branchQuery = Affiliate::withoutGlobalScopes()
            ->whereRaw("LOWER(TRIM(COALESCE(city, ''))) = ?", ['denpasar']);

        if (!$clearAll) {
            $bizQuery->whereRaw("TRIM(COALESCE(address, '')) = ''");
            $branchQuery->whereRaw("TRIM(COALESCE(address, '')) = ''");
        }

        $bizCount = (clone $bizQuery)->count();
        $branchCount = (clone $branchQuery)->count();

        if ($dryRun) {
            $this->info("[DRY RUN] Found {$bizCount} business(es) and {$branchCount} branch(es) with default city 'Denpasar'.");
            return self::SUCCESS;
        }

        $bizUpdated = $bizQuery->update(['city' => null]);
        $branchUpdated = $branchQuery->update(['city' => null]);

        $this->info("Cleared default 'Denpasar' city on {$bizUpdated} business(es) and {$branchUpdated} branch(es).");
        return self::SUCCESS;
    }
}
