<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\ListingRejected;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * SendListingRejectedNotification Listener
 *
 * Creates an in-app notification for the listing's landlord (with rejection
 * reason). The real email send (if enabled) happens later via the scheduled
 * NotificationDeliveryService, which reads from the notifications table —
 * this listener never fabricates an email_logs row.
 */
class SendListingRejectedNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(ListingRejected $event): void
    {
        $listing = $event->listing;
        $landlord = $listing->landlord;
        $reason = $event->reason;

        // Idempotent event ID
        $eventId = "listing-rejected:{$listing->id}";

        // Create in-app notification for the landlord (idempotent)
        if (! $this->notificationService->exists($landlord, $eventId)) {
            $this->notificationService->create(
                user: $landlord,
                type: NotificationType::LISTING_REJECTED,
                title: 'Listing Needs Changes',
                message: "Your listing \"{$listing->title}\" was not approved. Reason: {$reason}",
                data: [
                    'event_id' => $eventId,
                    'listing_id' => $listing->id,
                    'reason' => $reason,
                ]
            );
        }
    }
}
