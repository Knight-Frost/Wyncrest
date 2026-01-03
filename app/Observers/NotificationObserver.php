<?php

namespace App\Observers;

use App\Models\Notification;
use App\Support\Cache\AnalyticsCacheInvalidator;

/**
 * NotificationObserver
 * 
 * Phase 5.2: Invalidates notification analytics cache when notifications change.
 * Affects: Notification analytics only
 */
class NotificationObserver
{
    /**
     * Handle the Notification "created" event.
     */
    public function created(Notification $notification): void
    {
        $this->invalidateNotificationAnalytics($notification);
    }

    /**
     * Handle the Notification "updated" event.
     * Triggers on delivery status changes.
     */
    public function updated(Notification $notification): void
    {
        // Only invalidate if delivery-related fields changed
        if ($this->isDeliveryStatusChange($notification)) {
            $this->invalidateNotificationAnalytics($notification);
        }
    }

    /**
     * Handle the Notification "deleted" event.
     */
    public function deleted(Notification $notification): void
    {
        $this->invalidateNotificationAnalytics($notification);
    }
    
    /**
     * Check if the update is delivery-related
     * 
     * @param Notification $notification
     * @return bool
     */
    protected function isDeliveryStatusChange(Notification $notification): bool
    {
        $deliveryFields = [
            'delivered_at',
            'delivery_failed_at',
            'sms_delivered_at',
            'sms_failed_at',
        ];
        
        foreach ($deliveryFields as $field) {
            if ($notification->isDirty($field)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Invalidate notification analytics cache
     * 
     * @param Notification $notification
     * @return void
     */
    protected function invalidateNotificationAnalytics(Notification $notification): void
    {
        // Invalidate notification analytics
        // Scope: user-specific (tenant or landlord), global (admin)
        AnalyticsCacheInvalidator::invalidate('notifications', [
            'user_id' => $notification->user_id,
            'global' => true, // Also invalidate admin view
        ]);
    }
}
