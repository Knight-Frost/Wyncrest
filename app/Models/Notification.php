<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification
 * 
 * Represents a user notification triggered by system events.
 * Immutable except for read_at and delivery tracking fields.
 * 
 * Phase 3.5: Event-driven notifications
 * Phase 3.6: Email delivery tracking
 * Phase 3.7: SMS delivery tracking
 */
class Notification extends Model
{
    use HasFactory, HasUuids;

    /**
     * Disable updated_at (notifications are immutable)
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
    ];

    protected $casts = [
        'type' => NotificationType::class,
        'data' => 'array',
        'read_at' => 'datetime',
        'delivered_at' => 'datetime',
        'delivery_failed_at' => 'datetime',
        'sms_delivered_at' => 'datetime',
        'sms_failed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * User who receives this notification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if notification has been read
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Check if notification is unread
     */
    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Check if notification has been delivered via email
     * 
     * Phase 3.6
     */
    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    /**
     * Check if notification email delivery failed
     * 
     * Phase 3.6
     */
    public function hasDeliveryFailed(): bool
    {
        return $this->delivery_failed_at !== null;
    }

    /**
     * Check if notification is pending email delivery
     * 
     * Phase 3.6
     */
    public function isPendingDelivery(): bool
    {
        return $this->delivered_at === null && $this->delivery_failed_at === null;
    }

    /**
     * Check if notification has been delivered via SMS
     * 
     * Phase 3.7
     */
    public function isSmsDelivered(): bool
    {
        return $this->sms_delivered_at !== null;
    }

    /**
     * Check if notification SMS delivery failed
     * 
     * Phase 3.7
     */
    public function hasSmsDeliveryFailed(): bool
    {
        return $this->sms_failed_at !== null;
    }

    /**
     * Check if notification is pending SMS delivery
     * 
     * Phase 3.7
     */
    public function isPendingSmsDelivery(): bool
    {
        return $this->sms_delivered_at === null && $this->sms_failed_at === null;
    }

    /**
     * Scope: Unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope: Read notifications
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope: By type
     */
    public function scopeOfType($query, NotificationType $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Email delivered notifications
     * 
     * Phase 3.6
     */
    public function scopeDelivered($query)
    {
        return $query->whereNotNull('delivered_at');
    }

    /**
     * Scope: Pending email delivery
     * 
     * Phase 3.6
     */
    public function scopePendingDelivery($query)
    {
        return $query->whereNull('delivered_at')
            ->whereNull('delivery_failed_at');
    }

    /**
     * Scope: Failed email delivery
     * 
     * Phase 3.6
     */
    public function scopeFailedDelivery($query)
    {
        return $query->whereNotNull('delivery_failed_at');
    }

    /**
     * Scope: SMS delivered notifications
     * 
     * Phase 3.7
     */
    public function scopeSmsDelivered($query)
    {
        return $query->whereNotNull('sms_delivered_at');
    }

    /**
     * Scope: Pending SMS delivery
     * 
     * Phase 3.7
     */
    public function scopePendingSmsDelivery($query)
    {
        return $query->whereNull('sms_delivered_at')
            ->whereNull('sms_failed_at');
    }

    /**
     * Scope: Failed SMS delivery
     * 
     * Phase 3.7
     */
    public function scopeFailedSmsDelivery($query)
    {
        return $query->whereNotNull('sms_failed_at');
    }
}
