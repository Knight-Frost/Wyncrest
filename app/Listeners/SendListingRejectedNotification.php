<?php

namespace App\Listeners;

use App\Events\ListingRejected;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * SendListingRejectedNotification Listener
 */
class SendListingRejectedNotification implements ShouldQueue
{
    public function handle(ListingRejected $event): void
    {
        EmailLog::create([
            'recipient_type' => get_class($event->listing->landlord),
            'recipient_id' => $event->listing->landlord_id,
            'recipient_email' => $event->listing->landlord->email,
            'subject' => 'Listing Rejected - Action Required',
            'mailable_class' => 'ListingRejectedNotification',
            'email_type' => 'notification',
            'related_type' => get_class($event->listing),
            'related_id' => $event->listing->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
