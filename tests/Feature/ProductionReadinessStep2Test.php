<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Services\TelegramAlertService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ProductionReadinessStep2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_telegram_alert_skips_expected_client_and_4xx_exceptions(): void
    {
        config([
            'services.telegram_alerts.enabled' => true,
            'services.telegram_alerts.bot_token' => 'test-token',
            'services.telegram_alerts.chat_id' => '123456',
        ]);

        Http::fake();

        $service = app(TelegramAlertService::class);

        $this->assertFalse($service->reportException(ValidationException::withMessages(['field' => ['invalid']])));
        $this->assertFalse($service->reportException(new AuthenticationException()));
        $this->assertFalse($service->reportException(new AuthorizationException()));
        $this->assertFalse($service->reportException(new ModelNotFoundException()));
        $this->assertFalse($service->reportException(new NotFoundHttpException()));

        Http::assertNothingSent();
    }

    public function test_telegram_alert_reports_500_exception_and_deduplicates_within_5_minutes(): void
    {
        config([
            'services.telegram_alerts.enabled' => true,
            'services.telegram_alerts.bot_token' => 'bot-token-xyz',
            'services.telegram_alerts.chat_id' => '-100999888',
            'app.name' => 'RentCore',
            'app.env' => 'production',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $service = app(TelegramAlertService::class);
        $request = Request::create('/api/v1/bookings', 'POST', [], [], [], [], '');
        $request->headers->set('X-Business-Id', '42');

        $makeException = fn () => new RuntimeException('Database deadlock');

        $first = $service->reportException($makeException(), $request);
        $second = $service->reportException($makeException(), $request);

        $this->assertTrue($first);
        $this->assertFalse($second);

        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'botbot-token-xyz/sendMessage')
                && $req['chat_id'] === '-100999888'
                && str_contains($req['text'], 'RuntimeException')
                && str_contains($req['text'], 'Database deadlock')
                && str_contains($req['text'], 'POST')
                && str_contains($req['text'], 'Business ID: 42')
                && str_contains($req['text'], 'WIB');
        });
    }

    public function test_telegram_alert_never_throws_when_telegram_network_fails(): void
    {
        config([
            'services.telegram_alerts.enabled' => true,
            'services.telegram_alerts.bot_token' => 'bot-token-xyz',
            'services.telegram_alerts.chat_id' => '-100999888',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $service = app(TelegramAlertService::class);
        $result = $service->reportException(new RuntimeException('Boom'));

        $this->assertFalse($result);
    }

    public function test_telegram_test_alert_command_handles_missing_config_and_success(): void
    {
        config([
            'services.telegram_alerts.enabled' => false,
            'services.telegram_alerts.bot_token' => '',
            'services.telegram_alerts.chat_id' => '',
        ]);

        $this->artisan('telegram:test-alert')->assertExitCode(1);

        config([
            'services.telegram_alerts.enabled' => true,
            'services.telegram_alerts.bot_token' => 'valid-token',
            'services.telegram_alerts.chat_id' => '999',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('telegram:test-alert')->assertExitCode(0);
        Http::assertSentCount(1);
    }

    public function test_public_app_config_endpoint_returns_default_row_and_caches_for_60s(): void
    {
        $response = $this->getJson('/api/v1/app-config');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'min_android_version' => '1.0.0',
                    'latest_android_version' => '1.0.0',
                    'play_store_url' => AppConfig::DEFAULT_PLAY_STORE_URL,
                    'maintenance_mode' => false,
                    'maintenance_message' => null,
                    'force_update_message' => null,
                ],
            ]);

        $this->assertTrue(Cache::has(AppConfig::CACHE_KEY));
    }

    public function test_app_config_command_updates_row_and_flushes_cache(): void
    {
        // Prime cache
        $this->getJson('/api/v1/app-config')->assertOk();
        $this->assertTrue(Cache::has(AppConfig::CACHE_KEY));

        $this->artisan('app:config', [
            '--min-version' => '1.2.0',
            '--latest-version' => '1.3.5',
            '--maintenance' => 'on',
            '--message' => 'Sedang upgrade database 10 menit.',
            '--store-url' => 'https://play.google.com/store/apps/details?id=com.rentcore.rentcore_mobile',
        ])->assertExitCode(0);

        $this->assertFalse(Cache::has(AppConfig::CACHE_KEY));

        $this->getJson('/api/v1/app-config')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'min_android_version' => '1.2.0',
                    'latest_android_version' => '1.3.5',
                    'play_store_url' => 'https://play.google.com/store/apps/details?id=com.rentcore.rentcore_mobile',
                    'maintenance_mode' => true,
                    'maintenance_message' => 'Sedang upgrade database 10 menit.',
                ],
            ]);
    }
}
