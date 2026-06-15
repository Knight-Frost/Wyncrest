<?php

namespace App\Events\Cache;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5.5: Cache Invalidation Routed Event
 *
 * Emitted when invalidator decides between sync vs async execution
 */
class CacheInvalidationRouted
{
    use Dispatchable, SerializesModels;

    public string $domain;

    public string $mode; // 'sync' or 'async'

    public int $keyCount;

    public int $threshold;

    public array $scopes;

    public function __construct(
        string $domain,
        string $mode,
        int $keyCount,
        int $threshold,
        array $scopes = []
    ) {
        $this->domain = $domain;
        $this->mode = $mode;
        $this->keyCount = $keyCount;
        $this->threshold = $threshold;
        $this->scopes = $scopes;
    }
}
