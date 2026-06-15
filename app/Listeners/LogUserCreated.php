<?php

namespace App\Listeners;

use App\Events\UserCreated;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * LogUserCreated Listener
 */
class LogUserCreated implements ShouldQueue
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function handle(UserCreated $event): void
    {
        $this->auditService->logUserCreated($event->user);
    }
}
