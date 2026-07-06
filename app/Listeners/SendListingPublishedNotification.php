<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\ListingPublished;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * SendListingPublishedNotification Listener
 *
 * Creates an in-app notification for the listing's landlord. The real email
 * send (if enabled) happens later via the scheduled NotificationDeliveryService,
 * which reads from the notifications table — this listener never fabricates
 * an email_logs row.
 */
class SendListingPublishedNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(ListingPublished $event): void
    {
        $listing = $event->listing;
        $landlord = $listing->landlord;

        // Idempotent event ID
        $eventId = "listing-approved:{$listing->id}";

        // Create in-app notification for the landlord (idempotent)
        if (! $this->notificationService->exists($landlord, $eventId)) {
            $this->notificationService->create(
                user: $landlord,
                type: NotificationType::LISTING_APPROVED,
                title: 'Listing Approved',
                message: "Your listing \"{$listing->title}\" has been approved and is now live.",
                data: [
                    'event_id' => $eventId,
                    'listing_id' => $listing->id,
                ]
            );
        }
    }
}
