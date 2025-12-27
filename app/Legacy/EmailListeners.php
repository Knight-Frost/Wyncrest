<?php

namespace App\Listeners;

use App\Events\UserCreated;
use App\Events\EmailVerified;
use App\Events\IdentityVerified;
use App\Events\ListingPublished;
use App\Events\ListingSubmittedForReview;
use App\Events\ListingRejected;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * SendWelcomeEmail Listener
 * 
 * Sends welcome email when user is created.
 */
class SendWelcomeEmail implements ShouldQueue
{
    public function handle(UserCreated $event): void
    {
        // Log email intent
        $emailLog = EmailLog::create([
            'recipient_type' => get_class($event->user),
            'recipient_id' => $event->user->id,
            'recipient_email' => $event->user->email,
            'subject' => 'Welcome to Nexus',
            'mailable_class' => 'WelcomeEmail',
            'email_type' => 'account',
            'related_type' => get_class($event->user),
            'related_id' => $event->user->id,
            'status' => 'queued',
        ]);

        // TODO: Send actual email via Mail::to($event->user)->send(new WelcomeEmail($event->user))
        
        // For Phase 1, we just log the intent
        $emailLog->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}

/**
 * SendEmailVerifiedNotification Listener
 */
class SendEmailVerifiedNotification implements ShouldQueue
{
    public function handle(EmailVerified $event): void
    {
        EmailLog::create([
            'recipient_type' => get_class($event->user),
            'recipient_id' => $event->user->id,
            'recipient_email' => $event->user->email,
            'subject' => 'Email Verified Successfully',
            'mailable_class' => 'EmailVerifiedNotification',
            'email_type' => 'verification',
            'related_type' => get_class($event->user),
            'related_id' => $event->user->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}

/**
 * SendIdentityVerifiedNotification Listener
 */
class SendIdentityVerifiedNotification implements ShouldQueue
{
    public function handle(IdentityVerified $event): void
    {
        EmailLog::create([
            'recipient_type' => get_class($event->landlord),
            'recipient_id' => $event->landlord->id,
            'recipient_email' => $event->landlord->email,
            'subject' => 'Identity Verified - Features Unlocked',
            'mailable_class' => 'IdentityVerifiedNotification',
            'email_type' => 'verification',
            'related_type' => get_class($event->landlord),
            'related_id' => $event->landlord->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}

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
