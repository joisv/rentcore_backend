<?php

namespace App\Console\Commands;

use App\Models\CustomerIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateCustomerIdentitiesToPrivateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rentcore:migrate-identities-to-private 
                            {--dry-run : Simulate the migration without moving files} 
                            {--force : Force migration without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate legacy customer identity documents (KTP/Passport) from public disk to private local disk';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = (bool)$this->option('dry-run');

        $this->info($isDryRun 
            ? '--- SIMULATING CUSTOMER IDENTITY MIGRATION (DRY RUN) ---' 
            : '--- STARTING CUSTOMER IDENTITY MIGRATION TO PRIVATE STORAGE ---');

        $publicDisk = Storage::disk('public');
        $localDisk = Storage::disk('local');

        $identities = CustomerIdentity::whereNotNull('file_path')->get();

        if ($identities->isEmpty()) {
            $this->info('No customer identity records found.');
            return Command::SUCCESS;
        }

        $total = $identities->count();
        $migrated = 0;
        $alreadyPrivate = 0;
        $missing = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($identities as $identity) {
            $path = $identity->file_path;

            // Check if already on local disk
            if ($localDisk->exists($path)) {
                $alreadyPrivate++;
                // If it also exists on public disk, remove public exposure
                if (!$isDryRun && $publicDisk->exists($path)) {
                    $publicDisk->delete($path);
                }
                $bar->advance();
                continue;
            }

            // Check if on public disk
            if ($publicDisk->exists($path)) {
                if ($isDryRun) {
                    $this->line("\n[DRY RUN] Would move: {$path} from public to local disk.");
                    $migrated++;
                } else {
                    try {
                        $contents = $publicDisk->get($path);
                        $saved = $localDisk->put($path, $contents);

                        if ($saved && $localDisk->exists($path)) {
                            $publicDisk->delete($path);
                            $migrated++;
                        } else {
                            $this->error("\nFailed to write to local disk: {$path}");
                            $errors++;
                        }
                    } catch (\Throwable $e) {
                        $this->error("\nError moving {$path}: " . $e->getMessage());
                        $errors++;
                    }
                }
            } else {
                $missing++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Records Inspected', $total],
                [$isDryRun ? 'Identified to Migrate' : 'Successfully Migrated to Private', $migrated],
                ['Already in Private Storage', $alreadyPrivate],
                ['Missing / Not Found on Disk', $missing],
                ['Errors', $errors],
            ]
        );

        if ($isDryRun) {
            $this->warn('Dry-run completed. Run without --dry-run to execute migration.');
        } else {
            $this->info('Customer identity documents secured on private disk successfully.');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
