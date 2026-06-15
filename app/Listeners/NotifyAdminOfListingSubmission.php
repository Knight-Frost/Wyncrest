<?php

namespace App\Listeners;

use App\Events\ListingSubmittedForReview;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * NotifyAdminOfListingSubmission Listener
 */
class NotifyAdminOfListingSubmission implements ShouldQueue
{
    public function handle(ListingSubmittedForReview $event): void
    {
        // In Phase 1, we log this for system monitoring
        // Phase 4 will add actual admin notification emails

        EmailLog::create([
            'recipient_type' => 'system',
            'recipient_id' => null,
            'recipient_email' => 'admin@nexus.com',
            'subject' => 'New Listing Pending Review',
            'mailable_class' => 'ListingSubmissionNotification',
            'email_type' => 'notification',
            'related_type' => get_class($event->listing),
            'related_id' => $event->listing->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
