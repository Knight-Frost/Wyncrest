<?php

namespace App\Support\Cache;

use App\Models\Admin;
use Illuminate\Http\Request;

/**
 * AnalyticsCacheKey
 *
 * Generates deterministic, role-safe cache keys for analytics endpoints.
 *
 * Key Format: nexus:{env}:analytics:{endpoint}:{role}:{scope_hash}
 */
class AnalyticsCacheKey
{
    /**
     * Generate a cache key for an analytics endpoint
     *
     * @param  string  $endpoint  The endpoint name (e.g., 'contracts', 'platform')
     * @param  \Illuminate\Http\Request  $request  The authenticated request
     * @return string The generated cache key
     */
    public static function generate(string $endpoint, Request $request): string
    {
        $user = $request->user();

        // Environment prefix
        $env = config('app.env', 'local');

        // Role: admins are a separate model with no user_type; everyone else
        // is scoped by their user_type enum (tenant/landlord).
        $role = self::resolveRole($user);

        // Build scope data for hashing
        $scopeData = self::buildScopeData($user, $request);

        // Generate deterministic hash
        $scopeHash = self::hashScope($scopeData);

        return "nexus:{$env}:analytics:{$endpoint}:{$role}:{$scopeHash}";
    }

    /**
     * Build scope data array for cache key
     *
     * @param  mixed  $user  The authenticated user
     * @param  \Illuminate\Http\Request  $request  The request
     * @return array Scope data to be hashed
     */
    protected static function buildScopeData($user, Request $request): array
    {
        $role = self::resolveRole($user);

        $scopeData = [
            'user_type' => $role,
        ];

        // Tenants and landlords are scoped per-user — without this, two
        // different tenants/landlords hitting the same endpoint with no query
        // params would collide on the same cache key and see each other's
        // data. Admins are intentionally unscoped (platform-wide view), so
        // the role alone is a sufficient key for them.
        if ($role === 'tenant' || $role === 'landlord') {
            $scopeData['user_id'] = $user->id;
        }

        // For landlords, include property_id if applicable
        if ($role === 'landlord') {
            // Property ID might come from query params or be auto-assigned
            if ($request->has('property_id')) {
                $scopeData['property_id'] = $request->input('property_id');
            }
        }

        // Include all query parameters (sorted for determinism)
        $queryParams = $request->query();
        ksort($queryParams);

        foreach ($queryParams as $key => $value) {
            // Normalize query parameter values
            $scopeData["query_{$key}"] = self::normalizeValue($value);
        }

        return $scopeData;
    }

    /**
     * Resolve a role label for the cache key. Admins are a separate,
     * non-Sanctum model with no `user_type` attribute, so they must be
     * detected by class rather than by reading that property (which would
     * error/return null on an Admin).
     *
     * @param  mixed  $user  The authenticated user or admin
     */
    protected static function resolveRole($user): string
    {
        if ($user instanceof Admin) {
            return 'admin';
        }

        return $user?->user_type?->value ?? 'guest';
    }

    /**
     * Hash scope data into a deterministic string
     *
     * @param  array  $scopeData  The scope data to hash
     * @return string The hashed scope identifier
     */
    protected static function hashScope(array $scopeData): string
    {
        // Sort keys for determinism
        ksort($scopeData);

        // Create stable JSON representation
        $json = json_encode($scopeData, JSON_THROW_ON_ERROR);

        // Generate short hash (first 12 chars of SHA256)
        return substr(hash('sha256', $json), 0, 12);
    }

    /**
     * Normalize a value for consistent hashing
     *
     * @param  mixed  $value  The value to normalize
     * @return string Normalized string representation
     */
    protected static function normalizeValue($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            ksort($value);

            return json_encode($value);
        }

        // Convert to string and trim
        return trim((string) $value);
    }
}
