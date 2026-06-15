<?php

namespace App\Events\Cache;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5.5: Cache Job Completed Event
 *
 * Emitted when async invalidation job finishes successfully
 */
class CacheJobCompleted
{
    use Dispatchable, SerializesModels;

    public string $domain;

    public float $durationMs;

    public int $retryAttempt;

    public function __construct(
        string $domain,
        float $durationMs,
        int $retryAttempt = 0
    ) {
        $this->domain = $domain;
        $this->durationMs = $durationMs;
        $this->retryAttempt = $retryAttempt;
    }
}
