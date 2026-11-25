<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppConfig extends Model
{
    public const CACHE_KEY = 'public_app_config';

    public const DEFAULT_PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=id.sewakasir.mobile';

    protected $fillable = [
        'min_android_version',
        'latest_android_version',
        'play_store_url',
        'maintenance_mode',
        'maintenance_message',
        'force_update_message',
    ];

    protected $casts = [
        'maintenance_mode' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? static::create([
            'min_android_version' => '1.0.0',
            'latest_android_version' => '1.0.0',
            'play_store_url' => self::DEFAULT_PLAY_STORE_URL,
            'maintenance_mode' => false,
        ]);
    }

    public static function flushPublicCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function toPublicPayload(): array
    {
        return [
            'min_android_version' => (string) $this->min_android_version,
            'latest_android_version' => (string) $this->latest_android_version,
            'play_store_url' => (string) $this->play_store_url,
            'maintenance_mode' => (bool) $this->maintenance_mode,
            'maintenance_message' => $this->maintenance_message,
            'force_update_message' => $this->force_update_message,
        ];
    }
}
