<?php

namespace App\Jobs;

use App\Support\Cache\AnalyticsCacheInvalidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5.4: Async Cache Invalidation Job
 * 
 * Offloads expensive cache invalidation work from the write path.
 * Reuses Phase 5.3 selective invalidation logic.
 * 
 * CRITICAL RULES:
 * - Fire-and-forget (never fails permanently)
 * - Best-effort only
 * - Wrapped in try/catch
 * - Logs warnings, not errors
 * - Reuses existing invalidator logic
 */
class InvalidateAnalyticsCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 5;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * The analytics domain to invalidate.
     *
     * @var string
     */
    protected string $domain;

    /**
     * The scopes that were affected by the change.
     *
     * @var array
     */
    protected array $scopes;

    /**
     * Create a new job instance.
     *
     * @param string $domain Analytics domain (contracts, financial, platform, notifications)
     * @param array $scopes Changed scope identifiers (user_id, property_id, date, etc.)
     */
    public function __construct(string $domain, array $scopes)
    {
        $this->domain = $domain;
        $this->scopes = $scopes;
        
        // Use dedicated queue for analytics invalidation
        $this->onQueue('analytics-invalidation');
    }

    /**
     * Execute the job.
     *
     * Reuses Phase 5.3 selective invalidation logic.
     * Never throws exceptions - logs warnings only.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::info('Async cache invalidation started', [
                'domain' => $this->domain,
                'scopes' => $this->scopes,
            ]);

            $invalidator = app(AnalyticsCacheInvalidator::class);
            
            // Reuse Phase 5.3 selective invalidation logic
            $invalidator->invalidate($this->domain, $this->scopes);

            Log::info('Async cache invalidation completed', [
                'domain' => $this->domain,
            ]);

        } catch (\Exception $e) {
            // CRITICAL: Never fail the job permanently
            // TTL safety net (Phase 5.1) ensures correctness
            Log::warning('Async cache invalidation failed - relying on TTL', [
                'domain' => $this->domain,
                'error' => $e->getMessage(),
                'scopes' => $this->scopes,
            ]);

            // Don't rethrow - let the job succeed even if invalidation failed
            // Cache will expire via TTL (300s), maintaining correctness
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // Log failure but don't escalate
        // TTL safety net ensures correctness
        Log::warning('Async cache invalidation job failed permanently', [
            'domain' => $this->domain,
            'scopes' => $this->scopes,
            'error' => $exception->getMessage(),
            'note' => 'TTL safety net will ensure correctness',
        ]);
    }
}
