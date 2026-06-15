<?php

namespace App\Events\Cache;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5.5: Cache Invalidation Completed Event
 *
 * Emitted when cache invalidation finishes successfully
 */
class CacheInvalidationCompleted
{
    use Dispatchable, SerializesModels;

    public string $domain;

    public string $mode; // 'sync' or 'async'

    public int $invalidatedCount;

    public ?float $durationMs;

    public function __construct(
        string $domain,
        string $mode,
        int $invalidatedCount,
        ?float $durationMs = null
    ) {
        $this->domain = $domain;
        $this->mode = $mode;
        $this->invalidatedCount = $invalidatedCount;
        $this->durationMs = $durationMs;
    }
}
