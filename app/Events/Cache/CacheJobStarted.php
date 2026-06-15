<?php

namespace App\Events\Cache;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5.5: Cache Job Started Event
 *
 * Emitted when async invalidation job begins execution
 */
class CacheJobStarted
{
    use Dispatchable, SerializesModels;

    public string $domain;

    public string $queue;

    public array $scopes;

    public function __construct(
        string $domain,
        string $queue,
        array $scopes = []
    ) {
        $this->domain = $domain;
        $this->queue = $queue;
        $this->scopes = $scopes;
    }
}
