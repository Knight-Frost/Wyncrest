<?php

namespace App\Http\Middleware;

use App\Services\MetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MetricsMiddleware - Phase 7.5 Task 2
 *
 * Automatically records metrics for all API requests:
 * - Request method and path
 * - Response status code
 * - Request duration (latency)
 * - User ID and role (if authenticated)
 *
 * Metrics are stored in cache and accessible via /api/metrics endpoint.
 */
class MetricsMiddleware
{
    public function __construct(
        private MetricsService $metricsService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        // Process request
        $response = $next($request);

        // Calculate duration in milliseconds
        $duration = (microtime(true) - $startTime) * 1000;

        // Extract user info if authenticated
        $user = $request->user();
        $userId = $user?->id;
        $userType = $user?->user_type?->value;

        // Record metrics
        $this->metricsService->recordRequest(
            method: $request->method(),
            path: $this->normalizePath($request->path()),
            statusCode: $response->getStatusCode(),
            duration: $duration,
            userId: $userId,
            userType: $userType
        );

        return $response;
    }

    /**
     * Normalize path to group similar routes
     * Example: /api/tenant/contracts/123 -> /api/tenant/contracts/{id}
     */
    private function normalizePath(string $path): string
    {
        // Replace UUIDs with {id}
        $path = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '{id}',
            $path
        );

        // Replace numeric IDs with {id}
        $path = preg_replace('/\/\d+/', '/{id}', $path);

        return $path;
    }
}
