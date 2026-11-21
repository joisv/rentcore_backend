<?php

namespace App\Console\Commands;

use App\Models\AppConfig;
use Illuminate\Console\Command;

class AppConfigCommand extends Command
{
    protected $signature = 'app:config
        {--min-version= : Minimum required Android SemVer (e.g. 1.2.0)}
        {--latest-version= : Latest available Android SemVer (e.g. 1.3.0)}
        {--maintenance= : Toggle maintenance mode (on|off)}
        {--message= : Maintenance or force-update message}
        {--update-message= : Explicit force-update message}
        {--store-url= : Play Store URL}';

    protected $description = 'Inspect or update the mobile app version and maintenance gate configuration.';

    public function handle(): int
    {
        $config = AppConfig::current();
        $dirty = false;

        $minVersion = $this->option('min-version');
        if ($minVersion !== null) {
            if (! $this->isValidSemVer($minVersion)) {
                $this->error("Invalid --min-version '{$minVersion}'. Expected SemVer format like 1.2.0.");
                return self::FAILURE;
            }
            $config->min_android_version = trim($minVersion);
            $dirty = true;
        }

        $latestVersion = $this->option('latest-version');
        if ($latestVersion !== null) {
            if (! $this->isValidSemVer($latestVersion)) {
                $this->error("Invalid --latest-version '{$latestVersion}'. Expected SemVer format like 1.2.0.");
                return self::FAILURE;
            }
            $config->latest_android_version = trim($latestVersion);
            $dirty = true;
        }

        $maintenanceOpt = $this->option('maintenance');
        if ($maintenanceOpt !== null) {
            $normalized = strtolower(trim($maintenanceOpt));
            if (! in_array($normalized, ['on', 'off'], true)) {
                $this->error("Invalid --maintenance '{$maintenanceOpt}'. Use 'on' or 'off'.");
                return self::FAILURE;
            }
            $config->maintenance_mode = $normalized === 'on';
            $dirty = true;
        }

        $storeUrl = $this->option('store-url');
        if ($storeUrl !== null) {
            $trimmedUrl = trim($storeUrl);
            if ($trimmedUrl === '' || ! filter_var($trimmedUrl, FILTER_VALIDATE_URL)) {
                $this->error("Invalid --store-url '{$storeUrl}'.");
                return self::FAILURE;
            }
            $config->play_store_url = $trimmedUrl;
            $dirty = true;
        }

        $message = $this->option('message');
        if ($message !== null) {
            $trimmedMsg = trim($message);
            $value = $trimmedMsg === '' ? null : $trimmedMsg;

            if ($maintenanceOpt !== null) {
                $config->maintenance_message = $value;
            } elseif ($minVersion !== null || $latestVersion !== null) {
                $config->force_update_message = $value;
            } elseif ($config->maintenance_mode) {
                $config->maintenance_message = $value;
            } else {
                $config->force_update_message = $value;
            }
            $dirty = true;
        }

        $updateMessage = $this->option('update-message');
        if ($updateMessage !== null) {
            $trimmedUpdateMsg = trim($updateMessage);
            $config->force_update_message = $trimmedUpdateMsg === '' ? null : $trimmedUpdateMsg;
            $dirty = true;
        }

        if ($dirty) {
            $config->save();
            AppConfig::flushPublicCache();
            $this->info('App configuration updated and public cache flushed.');
        } else {
            $this->line('Current app configuration:');
        }

        $this->table(
            ['Key', 'Value'],
            [
                ['min_android_version', $config->min_android_version],
                ['latest_android_version', $config->latest_android_version],
                ['play_store_url', $config->play_store_url],
                ['maintenance_mode', $config->maintenance_mode ? 'ON' : 'OFF'],
                ['maintenance_message', $config->maintenance_message ?? '(null)'],
                ['force_update_message', $config->force_update_message ?? '(null)'],
            ]
        );

        return self::SUCCESS;
    }

    private function isValidSemVer(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+$/', trim($version));
    }
}
