<?php

namespace App\Services;

use App\Mail\NotificationDigestEmail;
use App\Models\Notification;
use App\Models\User;
use App\Services\Sms\SmsClientInterface;
use Illuminate\Support\Facades\Mail;

/**
 * NotificationDigestService
 *
 * Handles batched delivery of notifications via email and SMS digests.
 * Phase 3.9: Groups notifications and sends digest messages.
 */
class NotificationDigestService
{
    public function __construct(
        protected SmsClientInterface $smsClient
    ) {}

    /**
     * Send daily email digests
     *
     * Groups notifications from the last 24 hours and sends one email per user
     *
     * @return array ['users' => int, 'email_digests' => int, 'sms_digests' => int, 'notifications' => int]
     */
    public function sendDailyDigests(): array
    {
        return $this->sendDigests('daily_digest', now()->subDay());
    }

    /**
     * Send weekly email digests
     *
     * Groups notifications from the last 7 days and sends one email per user
     *
     * @return array ['users' => int, 'email_digests' => int, 'sms_digests' => int, 'notifications' => int]
     */
    public function sendWeeklyDigests(): array
    {
        return $this->sendDigests('weekly_digest', now()->subWeek());
    }

    /**
     * Send digests for a specific delivery mode
     *
     * @param  \Carbon\Carbon  $since
     */
    protected function sendDigests(string $deliveryMode, $since): array
    {
        $emailDigestsSent = 0;
        $smsDigestsSent = 0;
        $totalNotifications = 0;

        // Get users who have digest preferences
        $users = User::whereHas('notificationPreferences', function ($query) use ($deliveryMode) {
            $query->where('delivery_mode', $deliveryMode);
        })->get();

        foreach ($users as $user) {
            // Send email digest
            $emailResult = $this->sendEmailDigest($user, $deliveryMode, $since);
            if ($emailResult['sent']) {
                $emailDigestsSent++;
                $totalNotifications += $emailResult['count'];
            }

            // Send SMS digest
            $smsResult = $this->sendSmsDigest($user, $deliveryMode, $since);
            if ($smsResult['sent']) {
                $smsDigestsSent++;
                $totalNotifications += $smsResult['count'];
            }
        }

        return [
            'users' => $users->count(),
            'email_digests' => $emailDigestsSent,
            'sms_digests' => $smsDigestsSent,
            'notifications' => $totalNotifications,
        ];
    }

    /**
     * Send email digest for a user
     *
     * @param  \Carbon\Carbon  $since
     * @return array ['sent' => bool, 'count' => int]
     */
    protected function sendEmailDigest(User $user, string $deliveryMode, $since): array
    {
        // Get eligible notifications for email digest
        $notifications = $this->getEligibleNotifications(
            $user,
            $deliveryMode,
            $since,
            'email'
        );

        if ($notifications->isEmpty()) {
            return ['sent' => false, 'count' => 0];
        }

        try {
            // Send digest email
            Mail::to($user->email)->send(new NotificationDigestEmail($notifications));

            // Mark all as delivered via email
            foreach ($notifications as $notification) {
                $notification->delivered_at = now();
                $notification->saveQuietly();
            }

            return ['sent' => true, 'count' => $notifications->count()];
        } catch (\Exception $e) {
            // Mark all as failed
            foreach ($notifications as $notification) {
                $notification->delivery_failed_at = now();
                $notification->delivery_error = $e->getMessage();
                $notification->saveQuietly();
            }

            return ['sent' => false, 'count' => 0];
        }
    }

    /**
     * Send SMS digest for a user
     *
     * @param  \Carbon\Carbon  $since
     * @return array ['sent' => bool, 'count' => int]
     */
    protected function sendSmsDigest(User $user, string $deliveryMode, $since): array
    {
        // Skip if user has no phone
        if (! $user->phone) {
            return ['sent' => false, 'count' => 0];
        }

        // Get eligible notifications for SMS digest
        $notifications = $this->getEligibleNotifications(
            $user,
            $deliveryMode,
            $since,
            'sms'
        );

        if ($notifications->isEmpty()) {
            return ['sent' => false, 'count' => 0];
        }

        try {
            // Format SMS digest message
            $message = $this->formatSmsDigest($notifications);

            // Send SMS
            $this->smsClient->send($user->phone, $message);

            // Mark all as delivered via SMS
            foreach ($notifications as $notification) {
                $notification->sms_delivered_at = now();
                $notification->saveQuietly();
            }

            return ['sent' => true, 'count' => $notifications->count()];
        } catch (\Exception $e) {
            // Mark all as failed
            foreach ($notifications as $notification) {
                $notification->sms_failed_at = now();
                $notification->sms_error = $e->getMessage();
                $notification->saveQuietly();
            }

            return ['sent' => false, 'count' => 0];
        }
    }

    /**
     * Get notifications eligible for digest delivery
     *
     * A notification is eligible if:
     * - Belongs to the user
     * - Has preference with matching delivery_mode
     * - Channel is enabled
     * - NOT already delivered on that channel
     * - NOT marked as failed on that channel
     * - Created within the digest window
     *
     * @param  \Carbon\Carbon  $since
     * @param  string  $channel  'email' or 'sms'
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getEligibleNotifications(User $user, string $deliveryMode, $since, string $channel)
    {
        // Get preference types with matching delivery mode and enabled channel
        $eligibleTypes = $user->notificationPreferences()
            ->where('delivery_mode', $deliveryMode)
            ->where($channel.'_enabled', true)
            ->pluck('notification_type')
            ->toArray();

        if (empty($eligibleTypes)) {
            return collect([]);
        }

        // Build query for eligible notifications
        $query = Notification::where('user_id', $user->id)
            ->whereIn('type', $eligibleTypes)
            ->where('created_at', '>=', $since);

        // Add channel-specific filters
        if ($channel === 'email') {
            $query->whereNull('delivered_at')
                ->whereNull('delivery_failed_at');
        } else { // sms
            $query->whereNull('sms_delivered_at')
                ->whereNull('sms_failed_at');
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Format SMS digest message
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $notifications
     */
    protected function formatSmsDigest($notifications): string
    {
        $count = $notifications->count();

        return "Nexus Digest: {$count} new notification".($count === 1 ? '' : 's').'. Log in to view details.';
    }
}
