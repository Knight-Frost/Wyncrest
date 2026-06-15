<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NotificationPreference
 *
 * Stores user preferences for notification delivery channels and timing.
 * Phase 3.8: Controls which channels (email/SMS) are used per notification type.
 * Phase 3.9: Controls WHEN notifications are delivered (immediate vs digest).
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type',
        'email_enabled',
        'sms_enabled',
        'delivery_mode',
    ];

    protected $casts = [
        'notification_type' => NotificationType::class,
        'email_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
        'delivery_mode' => 'string',
    ];

    /**
     * User who owns this preference
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if delivery mode is immediate
     */
    public function isImmediate(): bool
    {
        return $this->delivery_mode === 'immediate';
    }

    /**
     * Check if delivery mode is daily digest
     */
    public function isDailyDigest(): bool
    {
        return $this->delivery_mode === 'daily_digest';
    }

    /**
     * Check if delivery mode is weekly digest
     */
    public function isWeeklyDigest(): bool
    {
        return $this->delivery_mode === 'weekly_digest';
    }
}
