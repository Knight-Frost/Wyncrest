<?php

namespace App\Support\Cache;

use App\Events\Cache\CacheInvalidationCompleted;
use App\Events\Cache\CacheInvalidationFailed;
use App\Events\Cache\CacheInvalidationRouted;
use App\Jobs\InvalidateAnalyticsCacheJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5.2: Event-based Cache Invalidation
 * Phase 5.3: Selective Invalidation with Metadata
 * Phase 5.4: Async Invalidation for Large Key Sets
 * Phase 5.5: Observability & Metrics
 */
class AnalyticsCacheInvalidator
{
    const ASYNC_INVALIDATION_THRESHOLD = 100;

    const MAX_KEYS_PER_INVALIDATION = 100;

    /**
     * Invalidate analytics caches for a domain with specific scopes
     *
     * Phase 5.4: Routes to sync or async based on estimated key count
     * Phase 5.5: Emits metrics and structured logs
     */
    public static function invalidate(string $domain, array $scopes = []): void
    {
        $env = config('cache.env_prefix', 'nexus:local');

        // Phase 5.4: Estimate total key count
        $roles = ['tenant', 'landlord', 'admin'];
        $totalKeyCount = self::estimateKeyCount($env, $domain, $roles);

        // Phase 5.5: Route and emit metrics
        if ($totalKeyCount > self::ASYNC_INVALIDATION_THRESHOLD) {
            // Phase 5.5: Emit routing event
            event(new CacheInvalidationRouted(
                domain: $domain,
                mode: 'async',
                keyCount: $totalKeyCount,
                threshold: self::ASYNC_INVALIDATION_THRESHOLD,
                scopes: $scopes
            ));

            // Phase 5.5: Structured log
            Log::info('cache.invalidation.routed', [
                'domain' => $domain,
                'mode' => 'async',
                'key_count' => $totalKeyCount,
                'threshold' => self::ASYNC_INVALIDATION_THRESHOLD,
                'has_scopes' => ! empty($scopes),
            ]);

            self::dispatchAsyncInvalidation($domain, $scopes, $totalKeyCount);

            return;
        }

        // Phase 5.5: Emit routing event for sync
        event(new CacheInvalidationRouted(
            domain: $domain,
            mode: 'sync',
            keyCount: $totalKeyCount,
            threshold: self::ASYNC_INVALIDATION_THRESHOLD,
            scopes: $scopes
        ));

        // Phase 5.5: Structured log
        Log::info('cache.invalidation.routed', [
            'domain' => $domain,
            'mode' => 'sync',
            'key_count' => $totalKeyCount,
            'threshold' => self::ASYNC_INVALIDATION_THRESHOLD,
            'has_scopes' => ! empty($scopes),
        ]);

        // Phase 5.5: Track sync execution time
        $startTime = microtime(true);
        $invalidatedCount = 0;

        // Phase 5.3: Selective invalidation for each role
        foreach ($roles as $role) {
            $invalidatedCount += self::selectiveInvalidateForRole($env, $domain, $role, $scopes);
        }

        // Phase 5.5: Calculate duration and emit completion event
        $durationMs = (microtime(true) - $startTime) * 1000;

        event(new CacheInvalidationCompleted(
            domain: $domain,
            mode: 'sync',
            invalidatedCount: $invalidatedCount,
            durationMs: $durationMs
        ));

        Log::info('cache.invalidation.completed', [
            'domain' => $domain,
            'mode' => 'sync',
            'invalidated_count' => $invalidatedCount,
            'duration_ms' => round($durationMs, 2),
        ]);
    }

    /**
     * Phase 5.4: Estimate total key count across all roles
     * Phase 5.5: Handle ArrayStore gracefully (returns 0 for non-Redis)
     */
    private static function estimateKeyCount(string $env, string $domain, array $roles): int
    {
        // Check if we're using Redis
        $driver = config('cache.default');
        if ($driver !== 'redis') {
            // ArrayStore or other non-Redis drivers don't support pattern matching
            // Return 0 to force sync path
            return 0;
        }

        $totalKeys = 0;

        foreach ($roles as $role) {
            $pattern = "{$env}:analytics:{$domain}:{$role}:*";

            try {
                $redis = Cache::getRedis();
                $keys = $redis->keys($pattern);
                $totalKeys += is_array($keys) ? count($keys) : 0;
            } catch (\Exception $e) {
                // Redis connection failed - assume small count for sync
                return 0;
            }
        }

        return $totalKeys;
    }

    /**
     * Phase 5.4: Dispatch async invalidation job
     * Phase 5.5: Added fallback metrics
     */
    private static function dispatchAsyncInvalidation(string $domain, array $scopes, int $keyCount): void
    {
        try {
            InvalidateAnalyticsCacheJob::dispatch($domain, $scopes);
        } catch (\Exception $e) {
            // Phase 5.5: Log fallback
            Log::warning('cache.invalidation.async.dispatch.failed', [
                'domain' => $domain,
                'key_count' => $keyCount,
                'error' => $e->getMessage(),
                'fallback' => 'sync_domain_wide',
            ]);

            // Phase 5.5: Emit failure event
            event(new CacheInvalidationFailed(
                domain: $domain,
                mode: 'async',
                error: 'Dispatch failed: '.$e->getMessage()
            ));

            // Phase 5.4: Fallback to Phase 5.2 domain-wide sync invalidation
            $env = config('cache.env_prefix', 'nexus:local');
            foreach (['tenant', 'landlord', 'admin'] as $role) {
                self::invalidateForRole($env, $domain, $role, []);
            }
        }
    }

    /**
     * Phase 5.3: Selective invalidation for a specific role
     * Only invalidates caches where metadata overlaps with scopes
     */
    private static function selectiveInvalidateForRole(
        string $env,
        string $domain,
        string $role,
        array $scopes
    ): int {
        $pattern = "{$env}:analytics:{$domain}:{$role}:*";
        $invalidatedCount = 0;

        // Check cache driver
        $driver = config('cache.default');
        if ($driver !== 'redis') {
            // ArrayStore doesn't support pattern matching - return 0
            return 0;
        }

        try {
            $redis = Cache::getRedis();
            $keys = $redis->keys($pattern);

            if (! is_array($keys) || count($keys) === 0) {
                return 0;
            }

            // Phase 5.3: Check if we exceed threshold - fallback to domain-wide
            if (count($keys) > self::MAX_KEYS_PER_INVALIDATION) {
                return self::invalidateForRole($env, $domain, $role, $scopes);
            }

            // Phase 5.3: Selective invalidation with metadata overlap check
            foreach ($keys as $key) {
                if (self::shouldInvalidate($key, $scopes)) {
                    Cache::forget($key);
                    AnalyticsCacheMetadata::delete($key);
                    $invalidatedCount++;
                }
            }

            return $invalidatedCount;

        } catch (\Exception $e) {
            // Redis failure or array cache - use Phase 5.2 fallback
            return self::invalidateForRole($env, $domain, $role, $scopes);
        }
    }

    /**
     * Phase 5.3: Check if cache should be invalidated based on metadata overlap
     */
    private static function shouldInvalidate(string $cacheKey, array $scopes): bool
    {
        // Phase 5.3: No scopes = global invalidation
        if (empty($scopes)) {
            return true;
        }

        // Phase 5.3: Read metadata
        $metadata = AnalyticsCacheMetadata::read($cacheKey);

        // Phase 5.3: Missing metadata = invalidate (safe default)
        if ($metadata === null) {
            return true;
        }

        // Phase 5.3: Admin caches always invalidated
        if ($metadata['role'] === 'admin') {
            return true;
        }

        // Phase 5.3: Check overlap with scopes
        return AnalyticsCacheMetadata::overlaps($metadata, $scopes);
    }

    /**
     * Phase 5.2: Invalidate all caches for a specific domain and role
     * Used as fallback when selective invalidation not possible
     */
    private static function invalidateForRole(
        string $env,
        string $domain,
        string $role,
        array $scopes
    ): int {
        $pattern = "{$env}:analytics:{$domain}:{$role}:*";
        $invalidatedCount = 0;

        // Check cache driver
        $driver = config('cache.default');
        if ($driver !== 'redis') {
            // ArrayStore doesn't support pattern matching - return 0
            return 0;
        }

        try {
            $redis = Cache::getRedis();
            $keys = $redis->keys($pattern);

            if (is_array($keys)) {
                foreach ($keys as $key) {
                    Cache::forget($key);
                    AnalyticsCacheMetadata::delete($key);
                    $invalidatedCount++;
                }
            }

            return $invalidatedCount;

        } catch (\Exception $e) {
            // Redis not available (likely using array cache in tests)
            // Array cache doesn't support pattern matching
            // Invalidation will happen via TTL expiry
            return 0;
        }
    }
}
