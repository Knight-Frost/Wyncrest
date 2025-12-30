<?php

namespace App\Services;

use App\Models\Notification;
use App\Mail\NotificationEmail;
use App\Services\PreferenceResolver;
use Illuminate\Support\Facades\Mail;

/**
 * NotificationDeliveryService
 * 
 * Handles email delivery of notifications.
 * Phase 3.6: Email delivery only, idempotent, safe to retry.
 * Phase 3.8: Now checks user preferences before delivery.
 * Phase 3.9: UPDATED - Now checks delivery_mode (immediate vs digest).
 */
class NotificationDeliveryService
{
    public function __construct(
        protected PreferenceResolver $preferenceResolver
    ) {}

    /**
     * Deliver a single notification via email
     * 
     * @param Notification $notification
     * @return bool True if delivered, false if failed or skipped
     */
    public function deliver(Notification $notification): bool
    {
        // Skip if already delivered
        if ($notification->delivered_at !== null) {
            return false; // Already delivered
        }
        
        // Skip if delivery already failed (manual retry required)
        if ($notification->delivery_failed_at !== null) {
            return false; // Failed previously, needs manual intervention
        }

        // Phase 3.8: Check user preferences BEFORE attempting delivery
        if ($notification->user && $notification->type) {
            $emailEnabled = $this->preferenceResolver->isEmailEnabled(
                $notification->user, 
                $notification->type
            );
            
            if (!$emailEnabled) {
                // User has disabled email for this notification type
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
            // Send email
            Mail::to($notification->user->email)
                ->send(new NotificationEmail($notification));
            
            // Mark as delivered
            $notification->delivered_at = now();
            $notification->saveQuietly(); // Don't fire events
            
            return true;
        } catch (\Exception $e) {
            // Mark as failed
            $notification->delivery_failed_at = now();
            $notification->delivery_error = $e->getMessage();
            $notification->saveQuietly();
            
            return false;
        }
    }

    /**
     * Deliver pending notifications in batches
     * 
     * @param int $limit Maximum number to deliver
     * @return array ['delivered' => int, 'failed' => int, 'skipped' => int]
     */
    public function deliverPending(int $limit = 50): array
    {
        $delivered = 0;
        $failed = 0;
        $skipped = 0;
        
        // Get undelivered notifications
        $notifications = Notification::whereNull('delivered_at')
            ->whereNull('delivery_failed_at')
            ->with('user') // Eager load user for email
            ->limit($limit)
            ->get();
        
        foreach ($notifications as $notification) {
            // Skip if user doesn't have email
            if (!$notification->user || !$notification->user->email) {
                $skipped++;
                continue;
            }
            
            $result = $this->deliver($notification);
            
            if ($result) {
                $delivered++;
            } else {
                // Check if it failed (not just skipped)
                $notification->refresh();
                if ($notification->delivery_failed_at !== null) {
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
     * Get count of pending notifications
     * 
     * @return int
     */
    public function getPendingCount(): int
    {
        return Notification::whereNull('delivered_at')
            ->whereNull('delivery_failed_at')
            ->count();
    }

    /**
     * Get count of failed notifications
     * 
     * @return int
     */
    public function getFailedCount(): int
    {
        return Notification::whereNotNull('delivery_failed_at')
            ->count();
    }

    /**
     * Retry failed notification deliveries
     * 
     * @param int $limit
     * @return array
     */
    public function retryFailed(int $limit = 10): array
    {
        $delivered = 0;
        $failed = 0;
        
        // Get failed notifications
        $notifications = Notification::whereNotNull('delivery_failed_at')
            ->with('user')
            ->limit($limit)
            ->get();
        
        foreach ($notifications as $notification) {
            // Clear failure fields to allow retry
            $notification->delivery_failed_at = null;
            $notification->delivery_error = null;
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
}
