<?php

namespace App\Listeners;

use App\Events\ListingRejected;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;

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
