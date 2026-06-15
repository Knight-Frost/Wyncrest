<?php

namespace App\Listeners;

use App\Events\ListingPublished;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * SendListingPublishedNotification Listener
 */
class SendListingPublishedNotification implements ShouldQueue
{
    public function handle(ListingPublished $event): void
    {
        EmailLog::create([
            'recipient_type' => get_class($event->listing->landlord),
            'recipient_id' => $event->listing->landlord_id,
            'recipient_email' => $event->listing->landlord->email,
            'subject' => 'Your Listing is Now Live',
            'mailable_class' => 'ListingPublishedNotification',
            'email_type' => 'notification',
            'related_type' => get_class($event->listing),
            'related_id' => $event->listing->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
