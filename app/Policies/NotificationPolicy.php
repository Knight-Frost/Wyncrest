<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * NotificationPolicy
 *
 * Authorization rules for notifications.
 * Users can only access their own notifications.
 * SECURITY: Uses strict type comparisons (===) throughout.
 */
class NotificationPolicy
{
    /**
     * Determine if user can view any notifications.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view their own notifications
        return true;
    }

    /**
     * Determine if user can view the notification.
     */
    public function view(User $user, Notification $notification): bool
    {
        $userId = (int) $user->id;
        $notificationUserId = (int) $notification->user_id;

        return $userId === $notificationUserId;
    }

    /**
     * Determine if user can update the notification (mark as read).
     */
    public function update(User $user, Notification $notification): bool
    {
        $userId = (int) $user->id;
        $notificationUserId = (int) $notification->user_id;

        return $userId === $notificationUserId;
    }

    /**
     * No user can delete notifications.
     */
    public function delete(User $user, Notification $notification): bool
    {
        // Notifications cannot be deleted (audit trail)
        return false;
    }
}
