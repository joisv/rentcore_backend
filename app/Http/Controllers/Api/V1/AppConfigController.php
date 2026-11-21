<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class AppConfigController extends Controller
{
    public function show(): JsonResponse
    {
        $payload = Cache::remember(AppConfig::CACHE_KEY, 60, function () {
            return AppConfig::current()->toPublicPayload();
        });

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }
}
