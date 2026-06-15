<?php

namespace App\Support\Cache;

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

        // User role (user_type enum value)
        $role = $user->user_type?->value ?? 'guest';

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
        $scopeData = [
            'user_type' => $user->user_type?->value ?? 'guest',
        ];

        // For tenants, include user_id for personal scoping
        if ($user->user_type?->value === 'tenant') {
            $scopeData['user_id'] = $user->id;
        }

        // For landlords, include property_id if applicable
        if ($user->user_type?->value === 'landlord') {
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
