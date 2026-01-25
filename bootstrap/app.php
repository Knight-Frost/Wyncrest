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
        // Register custom middleware aliases
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenant::class,
            'landlord' => \App\Http\Middleware\EnsureLandlord::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'admin.or.landlord' => \App\Http\Middleware\EnsureAdminOrLandlord::class,
            'rate.limit.role' => \App\Http\Middleware\RateLimitByRole::class,
            'metrics' => \App\Http\Middleware\MetricsMiddleware::class,
        ]);

        // Enable Sanctum's stateful middleware for SPA authentication
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // In production, don't expose detailed exception messages
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return $request->expectsJson() || $request->is('api/*');
        });
    })
    ->create();
