<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.context' => \App\Http\Middleware\TenantContextMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $e): void {
            try {
                app(\App\Services\TelegramAlertService::class)->reportException(
                    $e,
                    app()->bound('request') ? request() : null,
                );
            } catch (\Throwable) {
                // Never let alerting errors interrupt API responses
            }
        });

        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            $errors = $e->errors();
            $isShiftError = (isset($errors['code']) && in_array('SHIFT_NOT_ACTIVE', (array)$errors['code']))
                || isset($errors['shift'])
                || isset($errors['cash_shift']);

            if ($isShiftError) {
                $message = $errors['shift'][0] ?? $errors['cash_shift'][0] ?? 'Shift kasir belum dibuka.';
                return response()->json([
                    'message' => $message,
                    'success' => false,
                    'code' => 'SHIFT_NOT_ACTIVE',
                    'errors' => $errors,
                ], 422);
            }
            return null;
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        });
    })->create();
