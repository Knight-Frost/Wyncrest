<?php

namespace App\Listeners;

use App\Events\EmailVerified;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;

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
