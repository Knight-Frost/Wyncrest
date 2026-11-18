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
        // Add security headers to all API responses
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Register custom middleware aliases
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenant::class,
            'landlord' => \App\Http\Middleware\EnsureLandlord::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'admin.can' => \App\Http\Middleware\EnsureAdminCan::class,
            'admin.or.landlord' => \App\Http\Middleware\EnsureAdminOrLandlord::class,
            'rate.limit.role' => \App\Http\Middleware\RateLimitByRole::class,
            'metrics' => \App\Http\Middleware\MetricsMiddleware::class,
            'security.headers' => \App\Http\Middleware\SecurityHeaders::class,
            // Ties a session to the user's current password hash so changing the
            // password (logoutOtherDevices) invalidates that admin's OTHER sessions.
            'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);

        // AUTHENTICATION MODEL (two intentionally isolated mechanisms):
        //  - Tenant/Landlord: stateless Sanctum BEARER tokens (Authorization header).
        //    Global SPA stateful mode stays OFF so a browser's cookies can never
        //    override a bearer identity on these routes.
        //  - Admin console: first-party COOKIE SESSION on the `admin` guard, applied
        //    ONLY to the admin routes via the `web` middleware group + `auth:admin`
        //    (see routes/api.php). The SPA calls GET /sanctum/csrf-cookie before
        //    logging in. This keeps the admin credential HttpOnly (never in JS) while
        //    leaving the tenant/landlord token flow untouched.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // In production, don't expose detailed exception messages
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return $request->expectsJson() || $request->is('api/*');
        });

        // Forensic audit WYN-017: when two requests change the same record at
        // once, the loser used to get a bare 500 ("database is locked" on
        // SQLite, a serialization/deadlock error on Postgres/MySQL) although
        // nothing was written. It is a routine, retryable conflict: answer 409
        // with a plain message so the SPA can say "try again".
        $exceptions->render(function (\Illuminate\Database\QueryException $e, $request) {
            if (! \App\Support\Database\Contention::isRetryable($e)) {
                return null;
            }

            \Illuminate\Support\Facades\Log::warning('Write contention answered with 409', [
                'path' => $request->path(),
                'sqlstate' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
            ]);

            return response()->json([
                'message' => 'Another change to this record was being saved at the same moment. Nothing was changed by this request. Refresh and try again.',
                'code' => 'write_conflict',
            ], 409);
        });
    })
    ->create();
