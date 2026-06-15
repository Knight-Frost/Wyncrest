<?php

namespace App\Listeners;

use App\Events\EmailVerified;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * LogEmailVerified Listener
 */
class LogEmailVerified implements ShouldQueue
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function handle(EmailVerified $event): void
    {
        $this->auditService->logEmailVerified($event->user);
    }
}
