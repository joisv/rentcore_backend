<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class IdempotencyService
{
    public const DEFAULT_TTL_SECONDS = 300;

    /**
     * Extract a normalized idempotency key from header or request body.
     */
    public static function resolveKey(Request $request): ?string
    {
        $raw = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return substr($trimmed, 0, 128);
    }

    /**
     * Build the tenant-scoped cache lock key.
     */
    public static function cacheKey(?int $businessId, string $key): string
    {
        $tenantPart = $businessId && $businessId > 0 ? (string) $businessId : 'global';

        return "idempotency:{$tenantPart}:{$key}";
    }

    /**
     * Guard a critical mutation with atomic Cache::add deduplication (300s TTL).
     *
     * - Replays the cached 2xx response (HTTP 200 + `is_idempotent: true`) if already completed.
     * - Returns HTTP 409 if an identical request is concurrently in-flight.
     * - Releases the lock on 4xx/5xx or thrown exceptions so users can correct errors and retry.
     */
    public static function execute(
        Request $request,
        ?int $businessId,
        string $scope,
        callable $callback,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): JsonResponse {
        $key = self::resolveKey($request);
        if ($key === null) {
            return $callback();
        }

        $lockKey = self::cacheKey($businessId, $key);
        $responseKey = "{$lockKey}:response";

        // 1. Fast path: replay cached completed response
        $cached = Cache::get($responseKey);
        if (is_array($cached) && isset($cached['body'])) {
            return self::buildReplayResponse($cached['body']);
        }

        // 2. Atomic reservation via Cache::add (prevents race conditions & double charges)
        $acquired = Cache::add($lockKey, [
            'scope' => $scope,
            'started_at' => now()->toIso8601String(),
        ], $ttlSeconds);

        if (!$acquired) {
            // Re-check response cache in case the first request just finished
            $cached = Cache::get($responseKey);
            if (is_array($cached) && isset($cached['body'])) {
                return self::buildReplayResponse($cached['body']);
            }

            return response()->json([
                'success' => false,
                'message' => 'Transaksi yang sama sedang diproses. Mohon tunggu beberapa saat.',
                'code' => 'IDEMPOTENCY_IN_PROGRESS',
            ], 409);
        }

        try {
            /** @var JsonResponse $response */
            $response = $callback();

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $body = $response->getData(true);
                Cache::put($responseKey, [
                    'status' => $status,
                    'body' => $body,
                ], $ttlSeconds);
            } else {
                // Release lock on 4xx/5xx so legitimate corrections/retries can proceed
                Cache::forget($lockKey);
            }

            return $response;
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            throw $e;
        }
    }

    /**
     * Format an idempotent replay response consistently with existing API contracts.
     */
    private static function buildReplayResponse(mixed $body): JsonResponse
    {
        if (is_array($body)) {
            $body['is_idempotent'] = true;
            if (isset($body['data']) && is_array($body['data']) && !array_is_list($body['data'])) {
                $body['data']['is_idempotent'] = true;
            }
        }

        return response()->json($body, 200)
            ->header('X-Idempotent-Replay', 'true');
    }
}
