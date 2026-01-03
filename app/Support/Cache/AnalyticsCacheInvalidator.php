<?php

namespace App\Support\Cache;

use App\Jobs\InvalidateAnalyticsCacheJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * AnalyticsCacheInvalidator
 * 
 * Phase 5.2: Event-based cache invalidation for analytics.
 * Phase 5.3: Selective invalidation using metadata overlap detection.
 * Phase 5.4: Async invalidation for large key sets to prevent write-path blocking.
 * 
 * Key Format: nexus:{env}:analytics:{endpoint}:{role}:{scope_hash}
 * Metadata Key: nexus:{env}:analytics:{endpoint}:{role}:{scope_hash}:meta
 */
class AnalyticsCacheInvalidator
{
    /**
     * Phase 5.3: Maximum keys to process synchronously per invalidation
     * If exceeded in Phase 5.3, fall back to domain-wide invalidation
     */
    const MAX_KEYS_PER_INVALIDATION = 100;
    
    /**
     * Phase 5.4: Threshold for async invalidation
     * If key count exceeds this, dispatch async job instead of blocking write path
     */
    const ASYNC_INVALIDATION_THRESHOLD = 100;
    
    /**
     * Invalidate analytics cache for a specific domain and scopes
     * 
     * Phase 5.3: Uses selective invalidation with metadata overlap detection
     * Phase 5.4: Routes large invalidations to async job
     * 
     * @param string $domain The analytics domain (contracts, platform, financial, notifications)
     * @param array $scopes The scope data ['user_id' => int, 'property_id' => int, 'date' => string, 'global' => bool]
     * @return void
     */
    public static function invalidate(string $domain, array $scopes): void
    {
        try {
            $env = config('app.env', 'local');
            $roles = ['tenant', 'landlord']; // User roles (admin handled separately)
            
            // If global scope requested, invalidate everything for domain
            if (isset($scopes['global']) && $scopes['global'] === true) {
                self::invalidateGlobal($env, $domain);
                return;
            }
            
            // Phase 5.4: Check total key count for async routing decision
            $totalKeyCount = self::estimateKeyCount($env, $domain, $roles);
            
            // Phase 5.4: Route to async if threshold exceeded
            if ($totalKeyCount > self::ASYNC_INVALIDATION_THRESHOLD) {
                self::dispatchAsyncInvalidation($domain, $scopes, $totalKeyCount);
                return;
            }
            
            // Phase 5.3: Selective invalidation by role (synchronous)
            $totalInvalidated = 0;
            
            foreach ($roles as $role) {
                $invalidated = self::selectiveInvalidateForRole($env, $domain, $role, $scopes);
                $totalInvalidated += $invalidated;
            }
            
            // Always invalidate admin caches (they're global)
            self::invalidateForRole($env, $domain, 'admin', []);
            
            if ($totalInvalidated > 0) {
                Log::info('Synchronous cache invalidation complete', [
                    'domain' => $domain,
                    'count' => $totalInvalidated,
                ]);
            }
            
        } catch (Exception $e) {
            // Best-effort only - log but never fail
            Log::warning('Analytics cache invalidation failed', [
                'domain' => $domain,
                'scope_type' => self::determineScopeType($scopes),
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Phase 5.4: Estimate total key count for async routing decision
     * 
     * @param string $env
     * @param string $domain
     * @param array $roles
     * @return int
     */
    protected static function estimateKeyCount(string $env, string $domain, array $roles): int
    {
        $totalCount = 0;
        
        foreach ($roles as $role) {
            $pattern = "nexus:{$env}:analytics:{$domain}:{$role}:*";
            $keys = self::getMatchingKeys($pattern);
            $totalCount += count($keys);
        }
        
        // Add admin keys
        $adminPattern = "nexus:{$env}:analytics:{$domain}:admin:*";
        $adminKeys = self::getMatchingKeys($adminPattern);
        $totalCount += count($adminKeys);
        
        return $totalCount;
    }
    
    /**
     * Phase 5.4: Dispatch async invalidation job
     * 
     * @param string $domain
     * @param array $scopes
     * @param int $keyCount
     * @return void
     */
    protected static function dispatchAsyncInvalidation(string $domain, array $scopes, int $keyCount): void
    {
        try {
            // Dispatch to dedicated queue
            InvalidateAnalyticsCacheJob::dispatch($domain, $scopes);
            
            Log::info('Async cache invalidation dispatched', [
                'domain' => $domain,
                'key_count' => $keyCount,
                'threshold' => self::ASYNC_INVALIDATION_THRESHOLD,
            ]);
            
        } catch (Exception $e) {
            // If job dispatch fails, fall back to Phase 5.2 synchronous domain-wide invalidation
            Log::warning('Async dispatch failed - falling back to synchronous domain invalidation', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            
            $env = config('app.env', 'local');
            
            // Fallback: invalidate all roles synchronously
            foreach (['tenant', 'landlord', 'admin'] as $role) {
                self::invalidateForRole($env, $domain, $role, []);
            }
        }
    }
    
    /**
     * Selective invalidation for a specific role using metadata
     * Phase 5.3: Check metadata overlap before invalidating
     * 
     * @param string $env
     * @param string $domain
     * @param string $role
     * @param array $scopes
     * @return int Number of keys invalidated
     */
    protected static function selectiveInvalidateForRole(
        string $env,
        string $domain,
        string $role,
        array $scopes
    ): int {
        // Build pattern for this role
        $pattern = "nexus:{$env}:analytics:{$domain}:{$role}:*";
        
        // Get all matching keys
        $keys = self::getMatchingKeys($pattern);
        
        // Safety threshold check (Phase 5.3 behavior)
        if (count($keys) > self::MAX_KEYS_PER_INVALIDATION) {
            Log::warning('Too many cache keys - falling back to domain invalidation', [
                'domain' => $domain,
                'role' => $role,
                'count' => count($keys),
                'threshold' => self::MAX_KEYS_PER_INVALIDATION,
            ]);
            
            // Fallback to Phase 5.2 behavior
            return self::invalidateForRole($env, $domain, $role, []);
        }
        
        // Selective invalidation using metadata
        $invalidatedCount = 0;
        
        foreach ($keys as $key) {
            if (self::shouldInvalidate($key, $scopes)) {
                Cache::forget($key);
                AnalyticsCacheMetadata::delete($key); // Clean up metadata
                $invalidatedCount++;
            }
        }
        
        return $invalidatedCount;
    }
    
    /**
     * Check if a cache key should be invalidated based on metadata overlap
     * 
     * @param string $cacheKey The cache key to check
     * @param array $changedData The data that changed
     * @return bool True if should invalidate
     */
    protected static function shouldInvalidate(string $cacheKey, array $changedData): bool
    {
        // Try to load metadata
        $metadata = AnalyticsCacheMetadata::read($cacheKey);
        
        // If metadata missing or invalid, invalidate (safe default)
        if ($metadata === null) {
            return true;
        }
        
        // Check overlap using metadata
        return AnalyticsCacheMetadata::overlaps($metadata, $changedData);
    }
    
    /**
     * Invalidate cache for a specific role (Phase 5.2 behavior)
     * 
     * @param string $env
     * @param string $domain
     * @param string $role
     * @param array $scopes
     * @return int Number of keys invalidated
     */
    protected static function invalidateForRole(
        string $env,
        string $domain,
        string $role,
        array $scopes
    ): int {
        $pattern = "nexus:{$env}:analytics:{$domain}:{$role}:*";
        $keys = self::getMatchingKeys($pattern);
        
        if (!empty($keys)) {
            foreach ($keys as $key) {
                Cache::forget($key);
                AnalyticsCacheMetadata::delete($key);
            }
            
            return count($keys);
        }
        
        return 0;
    }
    
    /**
     * Invalidate all cache keys for a domain (global scope)
     * 
     * @param string $env
     * @param string $domain
     * @return void
     */
    protected static function invalidateGlobal(string $env, string $domain): void
    {
        $pattern = "nexus:{$env}:analytics:{$domain}:*";
        $keys = self::getMatchingKeys($pattern);
        
        if (!empty($keys)) {
            foreach ($keys as $key) {
                Cache::forget($key);
                AnalyticsCacheMetadata::delete($key);
            }
            
            Log::info('Analytics cache invalidated (global)', [
                'domain' => $domain,
                'count' => count($keys),
            ]);
        }
    }
    
    /**
     * Get cache keys matching a pattern
     * 
     * @param string $pattern
     * @return array
     */
    protected static function getMatchingKeys(string $pattern): array
    {
        try {
            // For array driver (tests), return empty
            if (config('cache.default') === 'array') {
                return [];
            }
            
            // For Redis driver, use SCAN
            $redis = Cache::getStore()->getRedis();
            $keys = [];
            $cursor = 0;
            
            do {
                $result = $redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 100);
                if ($result === false) {
                    break;
                }
                
                $cursor = $result[0];
                $foundKeys = $result[1] ?? [];
                
                foreach ($foundKeys as $key) {
                    // Exclude metadata keys from main key list
                    if (!str_ends_with($key, ':meta')) {
                        $keys[] = $key;
                    }
                }
            } while ($cursor !== 0);
            
            return $keys;
            
        } catch (Exception $e) {
            Log::warning('Failed to scan cache keys', [
                'pattern' => self::sanitizePattern($pattern),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
    
    /**
     * Determine scope type for logging
     * 
     * @param array $scopes
     * @return string
     */
    protected static function determineScopeType(array $scopes): string
    {
        if (isset($scopes['global']) && $scopes['global']) {
            return 'global';
        }
        
        if (isset($scopes['property_id'])) {
            return 'property';
        }
        
        if (isset($scopes['user_id'])) {
            return 'user';
        }
        
        return 'unknown';
    }
    
    /**
     * Sanitize pattern for logging (remove sensitive data)
     * 
     * @param string $pattern
     * @return string
     */
    protected static function sanitizePattern(string $pattern): string
    {
        // Keep only env, analytics namespace, and domain
        $parts = explode(':', $pattern);
        if (count($parts) >= 4) {
            return implode(':', array_slice($parts, 0, 4)) . ':***';
        }
        return substr($pattern, 0, 50) . '***';
    }
}
