<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\ListingChangesRequested;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * SendListingChangesRequestedNotification Listener
 *
 * Notifies the listing's landlord that a moderator has asked for changes,
 * carrying the admin's message. Distinct from a rejection: the listing is
 * back in the landlord's hands as a draft. The real email send (if enabled)
 * happens later via the scheduled NotificationDeliveryService, which reads
 * from the notifications table — this listener never fabricates an
 * email_logs row.
 */
class SendListingChangesRequestedNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(ListingChangesRequested $event): void
    {
        $listing = $event->listing;
        $landlord = $listing->landlord;
        $reason = $event->reason;

        // Idempotent per request: the timestamp keeps repeat requests distinct.
        $eventId = 'listing-changes-requested:'.$listing->id.':'.($listing->changes_requested_at?->timestamp ?? '0');

        if (! $this->notificationService->exists($landlord, $eventId)) {
            $this->notificationService->create(
                user: $landlord,
                type: NotificationType::LISTING_CHANGES_REQUESTED,
                title: 'Changes requested on your listing',
                message: "Your listing \"{$listing->title}\" needs changes before it can go live. {$reason}",
                data: [
                    'event_id' => $eventId,
                    'listing_id' => $listing->id,
                    'reason' => $reason,
                ]
            );
        }
    }
}
