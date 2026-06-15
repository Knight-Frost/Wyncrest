<?php

namespace App\Events\Cache;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5.5: Cache Invalidation Failed Event
 *
 * Emitted when cache invalidation encounters an error
 */
class CacheInvalidationFailed
{
    use Dispatchable, SerializesModels;

    public string $domain;

    public string $mode; // 'sync' or 'async'

    public string $error;

    public ?int $retryAttempt;

    public function __construct(
        string $domain,
        string $mode,
        string $error,
        ?int $retryAttempt = null
    ) {
        $this->domain = $domain;
        $this->mode = $mode;
        $this->error = $error;
        $this->retryAttempt = $retryAttempt;
    }
}
