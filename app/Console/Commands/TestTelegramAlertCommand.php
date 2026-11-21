<?php

namespace App\Console\Commands;

use App\Services\TelegramAlertService;
use Illuminate\Console\Command;

class TestTelegramAlertCommand extends Command
{
    protected $signature = 'telegram:test-alert';

    protected $description = 'Send a test message to the configured Telegram error alert chat.';

    public function handle(TelegramAlertService $service): int
    {
        if (! config('services.telegram_alerts.enabled')) {
            $this->warn('Note: TELEGRAM_ERROR_ENABLED is currently false; runtime 500 alerts are disabled until set to true.');
        }

        $result = $service->sendTestAlert();

        if ($result['ok']) {
            $this->info('Telegram test alert sent successfully (HTTP ' . ($result['status'] ?? 200) . ').');
            return self::SUCCESS;
        }

        $statusSuffix = $result['status'] !== null ? ' (HTTP ' . $result['status'] . ')' : '';
        $this->error('Failed to send Telegram test alert' . $statusSuffix . ': ' . ($result['error'] ?? 'Unknown error'));

        return self::FAILURE;
    }
}
