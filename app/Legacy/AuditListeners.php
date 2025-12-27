<?php

namespace App\Listeners;

use App\Events\UserCreated;
use App\Events\EmailVerified;
use App\Events\IdentityVerified;
use App\Events\ListingPublished;
use App\Events\ListingRejected;
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

/**
 * LogListingPublished Listener
 */
class LogListingPublished implements ShouldQueue
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function handle(ListingPublished $event): void
    {
        $this->auditService->logListingPublished(
            $event->listing,
            $event->listing->landlord
        );
    }
}

/**
 * LogListingRejected Listener
 */
class LogListingRejected implements ShouldQueue
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function handle(ListingRejected $event): void
    {
        // In production, admin should be passed in event
        // For Phase 1, we log with system as actor
        $this->auditService->log(
            actor: $event->listing->landlord,
            action: 'listing_rejected',
            subject: $event->listing,
            description: "Listing rejected: {$event->listing->title}",
            metadata: ['reason' => $event->reason],
            severity: 'warning'
        );
    }
}
