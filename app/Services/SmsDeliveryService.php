<?php

namespace App\Services;

use App\Models\Notification;
use App\Services\Sms\SmsClientInterface;
use App\Services\PreferenceResolver;

/**
 * SmsDeliveryService
 * 
 * Handles SMS delivery of notifications.
 * Phase 3.7: SMS only, idempotent, safe to retry.
 * Phase 3.8: Now checks user preferences before delivery.
 * Phase 3.9: UPDATED - Now checks delivery_mode (immediate vs digest).
 */
class SmsDeliveryService
{
    public function __construct(
        protected SmsClientInterface $smsClient,
        protected PreferenceResolver $preferenceResolver
    ) {}

    /**
     * Deliver a single notification via SMS
     * 
     * @param Notification $notification
     * @return bool True if delivered, false if failed or skipped
     */
    public function deliver(Notification $notification): bool
    {
        // Skip if already delivered via SMS
        if ($notification->sms_delivered_at !== null) {
            return false; // Already delivered
        }
        
        // Skip if SMS delivery already failed (manual retry required)
        if ($notification->sms_failed_at !== null) {
            return false; // Failed previously, needs manual intervention
        }

        // Skip if user doesn't have phone number
        if (!$notification->user || !$notification->user->phone) {
            return false; // Cannot deliver SMS without phone number
        }

        // Phase 3.8: Check user preferences BEFORE attempting delivery
        if ($notification->user && $notification->type) {
            $smsEnabled = $this->preferenceResolver->isSmsEnabled(
                $notification->user, 
                $notification->type
            );
            
            if (!$smsEnabled) {
                // User has disabled SMS for this notification type
                // This is NOT a failure - just skip silently
                return false;
            }

            // Phase 3.9: Check delivery_mode BEFORE attempting delivery
            $deliveryMode = $this->preferenceResolver->getDeliveryMode(
                $notification->user,
                $notification->type
            );

            if ($deliveryMode !== 'immediate') {
                // User wants digest delivery, not immediate
                // This is NOT a failure - just skip silently
                // Digest command will handle it later
                return false;
            }
        }

        try {
            // Format SMS message
            $message = $this->formatSmsMessage($notification);

            // Send SMS
            $this->smsClient->send($notification->user->phone, $message);
            
            // Mark as delivered
            $notification->sms_delivered_at = now();
            $notification->saveQuietly(); // Don't fire events
            
            return true;
        } catch (\Exception $e) {
            // Mark as failed
            $notification->sms_failed_at = now();
            $notification->sms_error = $e->getMessage();
            $notification->saveQuietly();
            
            return false;
        }
    }

    /**
     * Deliver pending SMS notifications in batches
     * 
     * @param int $limit Maximum number to deliver
     * @return array ['delivered' => int, 'failed' => int, 'skipped' => int]
     */
    public function deliverPending(int $limit = 50): array
    {
        $delivered = 0;
        $failed = 0;
        $skipped = 0;
        
        // Get undelivered notifications (SMS-wise)
        $notifications = Notification::whereNull('sms_delivered_at')
            ->whereNull('sms_failed_at')
            ->with('user') // Eager load user for phone number
            ->limit($limit)
            ->get();
        
        foreach ($notifications as $notification) {
            // Skip if user doesn't have phone number
            if (!$notification->user || !$notification->user->phone) {
                $skipped++;
                continue;
            }
            
            $result = $this->deliver($notification);
            
            if ($result) {
                $delivered++;
            } else {
                // Check if it failed (not just skipped)
                $notification->refresh();
                if ($notification->sms_failed_at !== null) {
                    $failed++;
                } else {
                    $skipped++;
                }
            }
        }
        
        return [
            'delivered' => $delivered,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Get count of pending SMS notifications
     * 
     * @return int
     */
    public function getPendingCount(): int
    {
        return Notification::whereNull('sms_delivered_at')
            ->whereNull('sms_failed_at')
            ->count();
    }

    /**
     * Get count of failed SMS notifications
     * 
     * @return int
     */
    public function getFailedCount(): int
    {
        return Notification::whereNotNull('sms_failed_at')
            ->count();
    }

    /**
     * Retry failed SMS notification deliveries
     * 
     * @param int $limit
     * @return array
     */
    public function retryFailed(int $limit = 10): array
    {
        $delivered = 0;
        $failed = 0;
        
        // Get failed SMS notifications
        $notifications = Notification::whereNotNull('sms_failed_at')
            ->with('user')
            ->limit($limit)
            ->get();
        
        foreach ($notifications as $notification) {
            // Clear failure fields to allow retry
            $notification->sms_failed_at = null;
            $notification->sms_error = null;
            $notification->saveQuietly();
            
            // Attempt delivery
            $result = $this->deliver($notification);
            
            if ($result) {
                $delivered++;
            } else {
                $failed++;
            }
        }
        
        return [
            'delivered' => $delivered,
            'failed' => $failed,
        ];
    }

    /**
     * Format notification message for SMS
     * 
     * SMS has character limits, so we keep it concise
     * 
     * @param Notification $notification
     * @return string
     */
    protected function formatSmsMessage(Notification $notification): string
    {
        // Format: "Nexus: {title} - {message}"
        // Max 160 chars for single SMS
        $prefix = "Nexus: ";
        $title = $notification->title;
        $message = $notification->message;
        
        // Truncate if too long
        $maxLength = 160 - strlen($prefix);
        $content = "{$title} - {$message}";
        
        if (strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength - 3) . '...';
        }
        
        return $prefix . $content;
    }
}
