<?php

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * AnalyticsCacheMetadata
 * 
 * Phase 5.3: Manages metadata sidecars for selective cache invalidation.
 * Metadata contains only non-PII internal identifiers for overlap detection.
 */
class AnalyticsCacheMetadata
{
    /**
     * Build metadata payload from request/filters
     * 
     * @param string $role User role (tenant, landlord, admin)
     * @param array $filters Filters used for the analytics query
     * @return array Metadata payload (NO PII)
     */
    public static function build(string $role, array $filters): array
    {
        $metadata = [
            'role' => $role,
            'user_id' => null,
            'property_id' => null,
            'start_date' => null,
            'end_date' => null,
        ];
        
        // Extract user_id for tenant scope
        if ($role === 'tenant' && isset($filters['user_id'])) {
            $metadata['user_id'] = (int) $filters['user_id'];
        }
        
        // Extract property_id for landlord scope
        if ($role === 'landlord' && isset($filters['property_id'])) {
            $metadata['property_id'] = (int) $filters['property_id'];
        }
        
        // Extract date range filters (if present)
        if (isset($filters['start_date'])) {
            $metadata['start_date'] = self::formatDate($filters['start_date']);
        }
        
        if (isset($filters['end_date'])) {
            $metadata['end_date'] = self::formatDate($filters['end_date']);
        }
        
        return $metadata;
    }
    
    /**
     * Write metadata to cache
     * 
     * @param string $cacheKey The analytics cache key
     * @param array $metadata The metadata payload
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public static function write(string $cacheKey, array $metadata, int $ttl): bool
    {
        try {
            $metaKey = self::getMetadataKey($cacheKey);
            Cache::put($metaKey, json_encode($metadata), $ttl);
            return true;
        } catch (Exception $e) {
            Log::warning('Failed to write analytics cache metadata', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Read metadata from cache
     * 
     * @param string $cacheKey The analytics cache key
     * @return array|null Metadata payload or null if missing/invalid
     */
    public static function read(string $cacheKey): ?array
    {
        try {
            $metaKey = self::getMetadataKey($cacheKey);
            $json = Cache::get($metaKey);
            
            if ($json === null) {
                return null;
            }
            
            $metadata = json_decode($json, true);
            
            if (!is_array($metadata)) {
                return null;
            }
            
            // Validate required fields
            if (!self::validate($metadata)) {
                return null;
            }
            
            return $metadata;
        } catch (Exception $e) {
            Log::warning('Failed to read analytics cache metadata', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Check if metadata overlaps with changed data
     * 
     * @param array $metadata Cache entry metadata
     * @param array $changedData Data that changed (user_id, property_id, date)
     * @return bool True if cache should be invalidated
     */
    public static function overlaps(array $metadata, array $changedData): bool
    {
        // Admin caches always overlap (always invalidate)
        if ($metadata['role'] === 'admin') {
            return true;
        }
        
        // Check user_id overlap (tenant scope)
        if (isset($changedData['user_id']) && $metadata['user_id'] !== null) {
            if ($metadata['user_id'] !== $changedData['user_id']) {
                return false; // Different user - no overlap
            }
        }
        
        // Check property_id overlap (landlord scope)
        if (isset($changedData['property_id']) && $metadata['property_id'] !== null) {
            if ($metadata['property_id'] !== $changedData['property_id']) {
                return false; // Different property - no overlap
            }
        }
        
        // Check date range overlap
        if (isset($changedData['date'])) {
            if (!self::dateOverlaps($metadata, $changedData['date'])) {
                return false; // Date out of range - no overlap
            }
        }
        
        // If we reach here, there is overlap or insufficient info to exclude
        return true;
    }
    
    /**
     * Check if a date overlaps with metadata date range
     * 
     * @param array $metadata Cache entry metadata
     * @param string $changedDate Date of the change (YYYY-MM-DD)
     * @return bool True if date overlaps with cached range
     */
    protected static function dateOverlaps(array $metadata, string $changedDate): bool
    {
        // If no date filters in metadata, assume overlap (safe default)
        if ($metadata['start_date'] === null && $metadata['end_date'] === null) {
            return true;
        }
        
        try {
            $date = new \DateTime($changedDate);
            
            // Check if date is after start_date
            if ($metadata['start_date'] !== null) {
                $startDate = new \DateTime($metadata['start_date']);
                if ($date < $startDate) {
                    return false; // Before range
                }
            }
            
            // Check if date is before end_date
            if ($metadata['end_date'] !== null) {
                $endDate = new \DateTime($metadata['end_date']);
                if ($date > $endDate) {
                    return false; // After range
                }
            }
            
            return true; // Within range
        } catch (Exception $e) {
            // If date parsing fails, assume overlap (safe default)
            Log::warning('Failed to parse date for overlap check', [
                'changed_date' => $changedDate,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }
    
    /**
     * Generate metadata cache key from analytics cache key
     * 
     * @param string $cacheKey The analytics cache key
     * @return string The metadata cache key
     */
    protected static function getMetadataKey(string $cacheKey): string
    {
        return $cacheKey . ':meta';
    }
    
    /**
     * Validate metadata structure
     * 
     * @param array $metadata The metadata to validate
     * @return bool True if valid
     */
    protected static function validate(array $metadata): bool
    {
        $required = ['role', 'user_id', 'property_id', 'start_date', 'end_date'];
        
        foreach ($required as $field) {
            if (!array_key_exists($field, $metadata)) {
                return false;
            }
        }
        
        // Validate role
        if (!in_array($metadata['role'], ['tenant', 'landlord', 'admin'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Format date for metadata storage
     * 
     * @param mixed $date Date object or string
     * @return string|null Formatted date (YYYY-MM-DD) or null
     */
    protected static function formatDate($date): ?string
    {
        if ($date === null) {
            return null;
        }
        
        try {
            if ($date instanceof \Carbon\Carbon || $date instanceof \DateTime) {
                return $date->format('Y-m-d');
            }
            
            if (is_string($date)) {
                $dt = new \DateTime($date);
                return $dt->format('Y-m-d');
            }
            
            return null;
        } catch (Exception $e) {
            Log::warning('Failed to format date for metadata', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Delete metadata for a cache key
     * 
     * @param string $cacheKey The analytics cache key
     * @return bool Success status
     */
    public static function delete(string $cacheKey): bool
    {
        try {
            $metaKey = self::getMetadataKey($cacheKey);
            Cache::forget($metaKey);
            return true;
        } catch (Exception $e) {
            Log::warning('Failed to delete analytics cache metadata', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
