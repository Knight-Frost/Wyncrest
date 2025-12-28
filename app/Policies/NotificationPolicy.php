<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * NotificationPolicy
 * 
 * Authorization rules for notifications.
 * Users can only access their own notifications.
 */
class NotificationPolicy
{
    /**
     * Determine if user can view the notification
     */
    public function view(User $user, Notification $notification): bool
    {
        return $user->id == $notification->user_id;
    }

    /**
     * Determine if user can update the notification (mark as read)
     */
    public function update(User $user, Notification $notification): bool
    {
        return $user->id == $notification->user_id;
    }
}
