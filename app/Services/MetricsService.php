<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * MetricsService - Phase 7.5 Task 2
 *
 * Lightweight observability service that tracks:
 * - Request rate
 * - Latency (p50, p95, p99)
 * - 4xx / 5xx errors
 * - Queue depth
 *
 * Uses Laravel's built-in caching for metric storage.
 * No external APM required.
 */
class MetricsService
{
    private const METRICS_TTL = 3600; // 1 hour

    private const LATENCY_BUCKET_SIZE = 100; // Store last 100 requests

    /**
     * Record a request metric
     */
    public function recordRequest(
        string $method,
        string $path,
        int $statusCode,
        float $duration, // milliseconds
        ?string $userId = null,
        ?string $userType = null
    ): void {
        $timestamp = now()->timestamp;
        $minute = now()->format('Y-m-d H:i');

        // Increment request counter
        $this->incrementCounter('requests:total');
        $this->incrementCounter("requests:method:{$method}");
        $this->incrementCounter("requests:status:{$statusCode}");
        $this->incrementCounter("requests:minute:{$minute}");

        // Track by user type if authenticated
        if ($userType) {
            $this->incrementCounter("requests:role:{$userType}");
        }

        // Track errors
        if ($statusCode >= 400 && $statusCode < 500) {
            $this->incrementCounter('errors:4xx');
            $this->incrementCounter("errors:4xx:{$statusCode}");
        } elseif ($statusCode >= 500) {
            $this->incrementCounter('errors:5xx');
            $this->incrementCounter("errors:5xx:{$statusCode}");
        }

        // Record latency
        $this->recordLatency($path, $duration);

        // Store recent requests for analysis
        $this->storeRecentRequest([
            'timestamp' => $timestamp,
            'method' => $method,
            'path' => $path,
            'status' => $statusCode,
            'duration' => $duration,
            'user_id' => $userId,
            'user_type' => $userType,
        ]);
    }

    /**
     * Record latency for a request
     */
    private function recordLatency(string $path, float $duration): void
    {
        $key = 'latency:all';
        $latencies = Cache::get($key, []);

        // Add new latency
        $latencies[] = $duration;

        // Keep only last N measurements
        if (count($latencies) > self::LATENCY_BUCKET_SIZE) {
            array_shift($latencies);
        }

        Cache::put($key, $latencies, self::METRICS_TTL);
    }

    /**
     * Get latency percentiles
     */
    public function getLatencyPercentiles(): array
    {
        $latencies = Cache::get('latency:all', []);

        if (empty($latencies)) {
            return [
                'p50' => 0,
                'p95' => 0,
                'p99' => 0,
                'count' => 0,
            ];
        }

        sort($latencies);
        $count = count($latencies);

        return [
            'p50' => $this->percentile($latencies, 50),
            'p95' => $this->percentile($latencies, 95),
            'p99' => $this->percentile($latencies, 99),
            'avg' => array_sum($latencies) / $count,
            'min' => min($latencies),
            'max' => max($latencies),
            'count' => $count,
        ];
    }

    /**
     * Calculate percentile from sorted array
     */
    private function percentile(array $sorted, int $percentile): float
    {
        $count = count($sorted);
        $index = ceil(($percentile / 100) * $count) - 1;

        return $sorted[$index] ?? 0;
    }

    /**
     * Get current queue depth
     */
    public function getQueueDepth(): array
    {
        try {
            // Get queue size from database (assumes database queue driver)
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            return [
                'pending' => $pending,
                'failed' => $failed,
                'total' => $pending + $failed,
            ];
        } catch (\Exception $e) {
            // Fallback if jobs table doesn't exist
            return [
                'pending' => 0,
                'failed' => 0,
                'total' => 0,
                'error' => 'Queue metrics unavailable',
            ];
        }
    }

    /**
     * Get error rate (4xx and 5xx)
     */
    public function getErrorRate(int $minutes = 5): array
    {
        $total4xx = 0;
        $total5xx = 0;
        $totalRequests = 0;

        // Sum up last N minutes
        for ($i = 0; $i < $minutes; $i++) {
            $minute = now()->subMinutes($i)->format('Y-m-d H:i');
            $requests = Cache::get("requests:minute:{$minute}", 0);
            $totalRequests += $requests;
        }

        // Get error totals
        $total4xx = Cache::get('errors:4xx', 0);
        $total5xx = Cache::get('errors:5xx', 0);

        return [
            '4xx_count' => $total4xx,
            '5xx_count' => $total5xx,
            'total_errors' => $total4xx + $total5xx,
            'total_requests' => $totalRequests,
            'error_rate' => $totalRequests > 0
                ? round(($total4xx + $total5xx) / $totalRequests * 100, 2)
                : 0,
        ];
    }

    /**
     * Get request rate (requests per minute)
     */
    public function getRequestRate(int $minutes = 5): array
    {
        $rates = [];
        $total = 0;

        for ($i = 0; $i < $minutes; $i++) {
            $minute = now()->subMinutes($i)->format('Y-m-d H:i');
            $count = Cache::get("requests:minute:{$minute}", 0);
            $rates[$minute] = $count;
            $total += $count;
        }

        return [
            'current_minute' => $rates[now()->format('Y-m-d H:i')] ?? 0,
            'last_5_minutes' => $rates,
            'total_last_5_minutes' => $total,
            'avg_per_minute' => round($total / $minutes, 2),
        ];
    }

    /**
     * Get comprehensive metrics summary
     */
    public function getSummary(): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'requests' => [
                'total' => Cache::get('requests:total', 0),
                'rate' => $this->getRequestRate(),
            ],
            'latency' => $this->getLatencyPercentiles(),
            'errors' => $this->getErrorRate(),
            'queue' => $this->getQueueDepth(),
            'by_role' => [
                'tenant' => Cache::get('requests:role:tenant', 0),
                'landlord' => Cache::get('requests:role:landlord', 0),
                'admin' => Cache::get('requests:role:admin', 0),
            ],
        ];
    }

    /**
     * Store recent request for detailed analysis
     */
    private function storeRecentRequest(array $request): void
    {
        $key = 'recent_requests';
        $requests = Cache::get($key, []);

        $requests[] = $request;

        // Keep only last 50 requests
        if (count($requests) > 50) {
            array_shift($requests);
        }

        Cache::put($key, $requests, self::METRICS_TTL);
    }

    /**
     * Get recent requests
     */
    public function getRecentRequests(int $limit = 20): array
    {
        $requests = Cache::get('recent_requests', []);

        return array_slice($requests, -$limit);
    }

    /**
     * Increment a counter metric
     */
    private function incrementCounter(string $key): void
    {
        Cache::increment($key, 1);

        // Set expiry if this is a new key
        if (Cache::get($key) === 1) {
            Cache::put($key, 1, self::METRICS_TTL);
        }
    }

    /**
     * Reset all metrics (for testing)
     */
    public function reset(): void
    {
        $patterns = [
            'requests:*',
            'errors:*',
            'latency:*',
            'recent_requests',
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }
}
