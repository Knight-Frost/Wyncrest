<?php

namespace App\Listeners;

use App\Events\IdentityVerified;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * LogIdentityVerified Listener
 */
class LogIdentityVerified implements ShouldQueue
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function handle(IdentityVerified $event): void
    {
        // Admin info should be passed in event in production
        // For Phase 1, we log without admin reference
        $this->auditService->log(
            actor: $event->landlord,
            action: 'identity_verified',
            subject: $event->landlord,
            description: "Identity verified for landlord: {$event->landlord->email}",
            severity: 'warning'
        );
    }
}
