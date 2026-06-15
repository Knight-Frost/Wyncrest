<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;

/**
 * NotificationService
 *
 * Centralized service for creating and managing notifications.
 * Handles message formatting, idempotency, and persistence.
 */
class NotificationService
{
    /**
     * Create a new notification
     */
    public function create(
        User $user,
        NotificationType $type,
        string $title,
        string $message,
        array $data = []
    ): Notification {
        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Notification $notification): void
    {
        if ($notification->isRead()) {
            return; // Already read
        }

        $notification->read_at = now();
        $notification->saveQuietly(); // Bypass events
    }

    /**
     * Mark all user notifications as read
     */
    public function markAllAsRead(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Get unread count for user
     */
    public function getUnreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Check if notification already exists for user and event
     * (Idempotency protection)
     */
    public function exists(User $user, string $eventId): bool
    {
        return Notification::where('user_id', $user->id)
            ->where('data->event_id', $eventId)
            ->exists();
    }

    /**
     * Get user's notifications (paginated)
     */
    public function getUserNotifications(User $user, int $perPage = 20)
    {
        return Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get user's unread notifications
     */
    public function getUnreadNotifications(User $user)
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
