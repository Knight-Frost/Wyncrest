<?php

namespace App\Listeners;

use App\Events\IdentityVerified;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;

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
