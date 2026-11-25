<?php

namespace App\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class TelegramAlertService
{
    public function shouldReport(Throwable $e): bool
    {
        if (
            $e instanceof ValidationException ||
            $e instanceof AuthenticationException ||
            $e instanceof AuthorizationException ||
            $e instanceof ModelNotFoundException
        ) {
            return false;
        }

        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return false;
        }

        return true;
    }

    public function reportException(Throwable $e, ?Request $request = null): bool
    {
        if (! $this->shouldReport($e)) {
            return false;
        }

        if (! config('services.telegram_alerts.enabled')) {
            return false;
        }

        $token = trim((string) config('services.telegram_alerts.bot_token', ''));
        $chatId = trim((string) config('services.telegram_alerts.chat_id', ''));
        if ($token === '' || $chatId === '') {
            return false;
        }

        $dedupeKey = 'tg_err:' . md5(get_class($e) . '|' . $e->getFile() . '|' . $e->getLine());
        if (! Cache::add($dedupeKey, true, now()->addMinutes(5))) {
            return false;
        }

        $message = $this->formatExceptionMessage($e, $request);

        try {
            $response = Http::timeout(3)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok: bool, status: int|null, error: string|null}
     */
    public function sendTestAlert(): array
    {
        $token = trim((string) config('services.telegram_alerts.bot_token', ''));
        $chatId = trim((string) config('services.telegram_alerts.chat_id', ''));

        if ($token === '' || $chatId === '') {
            return [
                'ok' => false,
                'status' => null,
                'error' => 'TELEGRAM_ERROR_BOT_TOKEN or TELEGRAM_ERROR_CHAT_ID is not configured.',
            ];
        }

        $appName = (string) config('app.name', 'SewaKasir');
        $appEnv = (string) config('app.env', 'local');
        $timestamp = now('Asia/Jakarta')->format('Y-m-d H:i:s') . ' WIB';
        $enabledLabel = config('services.telegram_alerts.enabled') ? 'true' : 'false';

        $text = implode("\n", [
            "[TEST ALERT] {$appName} ({$appEnv})",
            "Telegram server error alerting is reachable.",
            "Enabled flag: {$enabledLabel}",
            "Time: {$timestamp}",
        ]);

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
            ]);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'status' => $response->status(),
                    'error' => null,
                ];
            }

            return [
                'ok' => false,
                'status' => $response->status(),
                'error' => Str::limit($response->body(), 300),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function formatExceptionMessage(Throwable $e, ?Request $request = null): string
    {
        $appName = (string) config('app.name', 'SewaKasir');
        $appEnv = (string) config('app.env', 'local');
        $exceptionClass = get_class($e);
        $errorMessage = Str::limit(trim($e->getMessage()) !== '' ? $e->getMessage() : '(no message)', 600);
        $location = $e->getFile() . ':' . $e->getLine();

        $method = $request?->method() ?? 'CLI';
        $url = $request?->fullUrl() ?? 'console';

        $userId = $request?->user()?->id ?? 'guest';
        $businessId = $request?->header('X-Business-Id')
            ?? $request?->attributes->get('business_id')
            ?? '-';
        $timestamp = now('Asia/Jakarta')->format('Y-m-d H:i:s') . ' WIB';

        return implode("\n", [
            "[500 ERROR] {$appName} ({$appEnv})",
            "Exception: {$exceptionClass}",
            "Message: {$errorMessage}",
            "File: {$location}",
            "Request: {$method} {$url}",
            "User ID: {$userId} | Business ID: {$businessId}",
            "Time: {$timestamp}",
        ]);
    }
}
