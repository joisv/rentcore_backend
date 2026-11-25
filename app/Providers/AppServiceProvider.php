<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            $tenantContext = app(TenantContext::class);
            $businessId = $tenantContext->getBusinessId()
                ?? ($request->header('X-Business-Id') ? (int) $request->header('X-Business-Id') : null)
                ?? $user?->current_business_id
                ?? $user?->business_id;

            if ($user) {
                $rateKey = $businessId
                    ? "api:biz:{$businessId}:user:{$user->id}"
                    : "api:user:{$user->id}";

                return Limit::perMinute(120)->by($rateKey);
            }

            return Limit::perMinute(60)->by('api:ip:' . $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }
            return Limit::perMinute(15)->by($request->ip());
        });
    }
}
