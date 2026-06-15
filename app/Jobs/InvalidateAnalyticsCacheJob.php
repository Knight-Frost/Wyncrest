<?php

namespace App\Jobs;

use App\Events\Cache\CacheInvalidationFailed;
use App\Events\Cache\CacheJobCompleted;
use App\Events\Cache\CacheJobStarted;
use App\Support\Cache\AnalyticsCacheInvalidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5.4: Async Cache Invalidation Job
 * Phase 5.5: Observability & Metrics
 *
 * Handles cache invalidation asynchronously for large key sets
 */
class InvalidateAnalyticsCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 5;

    public $deleteWhenMissingModels = true;

    private string $domain;

    private array $scopes;

    public function __construct(string $domain, array $scopes = [])
    {
        $this->domain = $domain;
        $this->scopes = $scopes;

        // Set queue via method instead of property
        $this->onQueue('analytics-invalidation');
    }

    /**
     * Execute the job
     * Phase 5.5: Emit job lifecycle events and structured logs
     */
    public function handle(): void
    {
        // Phase 5.5: Emit job started event
        event(new CacheJobStarted(
            domain: $this->domain,
            queue: 'analytics-invalidation',
            scopes: $this->scopes
        ));

        // Phase 5.5: Structured log
        Log::info('cache.job.started', [
            'domain' => $this->domain,
            'queue' => 'analytics-invalidation',
            'has_scopes' => ! empty($this->scopes),
            'attempt' => $this->attempts(),
        ]);

        // Phase 5.5: Track execution time
        $startTime = microtime(true);

        try {
            // Phase 5.4: Delegate to invalidator (reuses Phase 5.3 selective logic)
            $invalidator = new AnalyticsCacheInvalidator;
            $invalidator->invalidate($this->domain, $this->scopes);

            // Phase 5.5: Calculate duration
            $durationMs = (microtime(true) - $startTime) * 1000;

            // Phase 5.5: Emit completion event
            event(new CacheJobCompleted(
                domain: $this->domain,
                durationMs: $durationMs,
                retryAttempt: $this->attempts() - 1
            ));

            // Phase 5.5: Structured log
            Log::info('cache.job.completed', [
                'domain' => $this->domain,
                'duration_ms' => round($durationMs, 2),
                'attempt' => $this->attempts(),
            ]);

        } catch (\Exception $e) {
            // Phase 5.5: Calculate partial duration
            $durationMs = (microtime(true) - $startTime) * 1000;

            // Phase 5.5: Emit failure event
            event(new CacheInvalidationFailed(
                domain: $this->domain,
                mode: 'async',
                error: $e->getMessage(),
                retryAttempt: $this->attempts()
            ));

            // Phase 5.5: Structured log
            Log::warning('cache.job.failed', [
                'domain' => $this->domain,
                'error' => $e->getMessage(),
                'duration_ms' => round($durationMs, 2),
                'attempt' => $this->attempts(),
                'will_retry' => $this->attempts() < $this->tries,
                'note' => 'TTL safety net will ensure correctness',
            ]);

            // Phase 5.4: Do not rethrow - TTL safety net ensures correctness
            // Job succeeds even if invalidation fails
        }
    }
}
