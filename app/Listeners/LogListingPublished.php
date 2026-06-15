<?php

namespace App\Listeners;

use App\Events\ListingPublished;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldQueue;

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
