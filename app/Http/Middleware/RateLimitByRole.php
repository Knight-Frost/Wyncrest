<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * RateLimitByRole Middleware - Phase 7.5
 *
 * Implements role-based rate limiting:
 * - Tenant: 60 requests/minute
 * - Landlord: 120 requests/minute
 * - Public/unauthenticated: 30 requests/minute
 *
 * Returns 429 Too Many Requests when limit exceeded.
 */
class RateLimitByRole
{
    /**
     * Rate limits per role (requests per minute)
     */
    private const RATE_LIMITS = [
        UserType::TENANT->value => 60,
        UserType::LANDLORD->value => 120,
        'admin' => 300, // Higher limit for admin operations
        'public' => 30,
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->resolveRateLimitKey($request);
        $maxAttempts = $this->resolveMaxAttempts($request);

        // Check if rate limit exceeded
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429)
                ->header('Retry-After', $retryAfter)
                ->header('X-RateLimit-Limit', $maxAttempts)
                ->header('X-RateLimit-Remaining', 0);
        }

        // Increment the counter
        RateLimiter::hit($key, 60); // 60 seconds = 1 minute window

        $response = $next($request);

        // Add rate limit headers to response
        $remaining = RateLimiter::remaining($key, $maxAttempts);

        return $response
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', max(0, $remaining));
    }

    /**
     * Resolve the rate limit key for the request.
     */
    protected function resolveRateLimitKey(Request $request): string
    {
        $user = $request->user();

        if ($user) {
            // Check if user is an Admin
            if ($user instanceof \App\Models\Admin) {
                return "api:rate-limit:admin:{$user->id}";
            }

            // Authenticated user: use user ID + role
            $role = $user->user_type?->value ?? 'unknown';

            return "api:rate-limit:{$role}:{$user->id}";
        }

        // Unauthenticated: use IP address
        return "api:rate-limit:public:{$request->ip()}";
    }

    /**
     * Resolve the maximum attempts allowed for the request.
     */
    protected function resolveMaxAttempts(Request $request): int
    {
        $user = $request->user();

        if ($user) {
            // Check if user is an Admin
            if ($user instanceof \App\Models\Admin) {
                return self::RATE_LIMITS['admin'];
            }

            // Regular user with user_type
            if (isset($user->user_type)) {
                $roleValue = $user->user_type->value;

                return self::RATE_LIMITS[$roleValue] ?? self::RATE_LIMITS['public'];
            }
        }

        return self::RATE_LIMITS['public'];
    }
}
