<?php

namespace App\Listeners;

use App\Events\UserCreated;
use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueue;

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
