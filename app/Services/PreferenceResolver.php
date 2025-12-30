<?php

namespace App\Services;

use App\Models\User;
use App\Models\NotificationPreference;
use App\Enums\NotificationType;

/**
 * PreferenceResolver
 * 
 * Single place for resolving user notification preferences.
 * Phase 3.8: Used by both email and SMS delivery services.
 * Phase 3.9: UPDATED - Added getDeliveryMode method.
 */
class PreferenceResolver
{
    /**
     * Resolve user preferences for a notification type
     * 
     * Returns which delivery channels are enabled.
     * If no preference exists, returns defaults (email: true, sms: false)
     * Never throws exceptions.
     * 
     * @param User $user
     * @param NotificationType|string $notificationType
     * @return array{email: bool, sms: bool}
     */
    public function resolve(User $user, NotificationType|string $notificationType): array
    {
        // Convert to string if NotificationType enum
        $typeString = $notificationType instanceof NotificationType 
            ? $notificationType->value 
            : $notificationType;

        try {
            // Try to find existing preference
            $preference = NotificationPreference::where('user_id', $user->id)
                ->where('notification_type', $typeString)
                ->first();

            // If preference exists, return it
            if ($preference) {
                return [
                    'email' => $preference->email_enabled,
                    'sms' => $preference->sms_enabled,
                ];
            }

            // No preference → return defaults
            return $this->getDefaults();
        } catch (\Exception $e) {
            // Never fail, always return defaults on error
            return $this->getDefaults();
        }
    }

    /**
     * Get default preferences
     * 
     * Defaults preserve existing system behavior:
     * - Email enabled (Phase 3.6 default)
     * - SMS disabled (Phase 3.7 default)
     * 
     * @return array{email: bool, sms: bool}
     */
    public function getDefaults(): array
    {
        return [
            'email' => true,
            'sms' => false,
        ];
    }

    /**
     * Check if email is enabled for user and notification type
     * 
     * @param User $user
     * @param NotificationType|string $notificationType
     * @return bool
     */
    public function isEmailEnabled(User $user, NotificationType|string $notificationType): bool
    {
        return $this->resolve($user, $notificationType)['email'];
    }

    /**
     * Check if SMS is enabled for user and notification type
     * 
     * @param User $user
     * @param NotificationType|string $notificationType
     * @return bool
     */
    public function isSmsEnabled(User $user, NotificationType|string $notificationType): bool
    {
        return $this->resolve($user, $notificationType)['sms'];
    }

    /**
     * Get delivery mode for user and notification type
     * 
     * Phase 3.9: Returns when notifications should be delivered
     * 
     * @param User $user
     * @param NotificationType|string $notificationType
     * @return string 'immediate', 'daily_digest', or 'weekly_digest'
     */
    public function getDeliveryMode(User $user, NotificationType|string $notificationType): string
    {
        // Convert to string if NotificationType enum
        $typeString = $notificationType instanceof NotificationType 
            ? $notificationType->value 
            : $notificationType;

        try {
            // Try to find existing preference
            $preference = NotificationPreference::where('user_id', $user->id)
                ->where('notification_type', $typeString)
                ->first();

            // If preference exists, return delivery_mode
            if ($preference) {
                return $preference->delivery_mode ?? 'immediate';
            }

            // No preference → return default (immediate)
            return 'immediate';
        } catch (\Exception $e) {
            // Never fail, always return default on error
            return 'immediate';
        }
    }
}
