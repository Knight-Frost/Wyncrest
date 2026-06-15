<?php

namespace App\Support\Cache;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AnalyticsCache
 *
 * Phase 5.1: Cache-aside pattern wrapper for analytics.
 * Phase 5.3: Now writes metadata sidecars for selective invalidation.
 */
class AnalyticsCache
{
    /**
     * Remember analytics data with metadata
     *
     * @param  string  $cacheKey  The cache key
     * @param  int  $ttlSeconds  Time to live in seconds
     * @param  callable  $callback  Callback to execute on cache miss
     * @param  string  $role  User role (tenant, landlord, admin)
     * @param  array  $filters  Filters used for the query
     * @return mixed Cached or fresh data
     */
    public static function remember(
        string $cacheKey,
        int $ttlSeconds,
        callable $callback,
        string $role = 'admin',
        array $filters = []
    ) {
        try {
            // Try to get from cache
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }

            // Cache miss - execute callback
            $result = $callback();

            // Store result in cache
            Cache::put($cacheKey, $result, $ttlSeconds);

            // Store metadata sidecar (Phase 5.3)
            self::writeMetadata($cacheKey, $role, $filters, $ttlSeconds);

            return $result;

        } catch (Exception $e) {
            // Graceful fallback - log and execute callback
            Log::warning('Analytics cache operation failed', [
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * Write metadata sidecar for selective invalidation
     *
     * @param  string  $cacheKey  The cache key
     * @param  string  $role  User role
     * @param  array  $filters  Query filters
     * @param  int  $ttlSeconds  TTL to match cache entry
     */
    protected static function writeMetadata(
        string $cacheKey,
        string $role,
        array $filters,
        int $ttlSeconds
    ): void {
        try {
            $metadata = AnalyticsCacheMetadata::build($role, $filters);
            AnalyticsCacheMetadata::write($cacheKey, $metadata, $ttlSeconds);
        } catch (Exception $e) {
            // Best effort only - don't fail the cache operation
            Log::warning('Failed to write analytics metadata', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
