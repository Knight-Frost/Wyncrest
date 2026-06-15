<?php

namespace App\Services\Analytics;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * NotificationAnalyticsService
 *
 * Read-only analytics for notification system.
 * Phase 4.0: Maximum insight into notification behavior.
 * Phase 5.1 Fix: Apply type filter to getNotificationsByType() helper
 */
class NotificationAnalyticsService
{
    /**
     * Get comprehensive notification analytics
     *
     * @param  array  $filters  ['start_date' => Carbon, 'end_date' => Carbon, 'user_id' => int, 'type' => string]
     */
    public function getAnalytics(array $filters = []): array
    {
        return [
            'volume' => $this->getVolumeMetrics($filters),
            'delivery' => $this->getDeliveryMetrics($filters),
            'performance' => $this->getPerformanceMetrics($filters),
            'preferences' => $this->getPreferenceMetrics(),
            'digests' => $this->getDigestMetrics($filters),
        ];
    }

    /**
     * Volume Metrics
     */
    public function getVolumeMetrics(array $filters = []): array
    {
        $query = Notification::query();

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return [
            'total_notifications' => $query->count(),
            'by_type' => $this->getNotificationsByType($filters),
            'by_day' => $this->getNotificationsByDay($filters),
            'by_user_role' => $this->getNotificationsByUserRole($filters),
            'per_user_avg' => $this->getAvgNotificationsPerUser($filters),
        ];
    }

    /**
     * Delivery Metrics
     */
    public function getDeliveryMetrics(array $filters = []): array
    {
        $query = Notification::query();

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $total = $query->count();
        $emailDelivered = (clone $query)->whereNotNull('delivered_at')->count();
        $smsDelivered = (clone $query)->whereNotNull('sms_delivered_at')->count();
        $emailFailed = (clone $query)->whereNotNull('delivery_failed_at')->count();
        $smsFailed = (clone $query)->whereNotNull('sms_failed_at')->count();

        return [
            'email_delivered' => $emailDelivered,
            'sms_delivered' => $smsDelivered,
            'email_pending' => (clone $query)->whereNull('delivered_at')->whereNull('delivery_failed_at')->count(),
            'sms_pending' => (clone $query)->whereNull('sms_delivered_at')->whereNull('sms_failed_at')->count(),
            'email_failed' => $emailFailed,
            'sms_failed' => $smsFailed,
            'email_success_rate' => $total > 0 ? round(($emailDelivered / $total) * 100, 2) : 0,
            'sms_success_rate' => $total > 0 ? round(($smsDelivered / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Performance Metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        $query = Notification::query()->whereNotNull('delivered_at');

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        // Calculate average delivery latency in seconds
        $latencies = $query->get()->map(function ($notification) {
            if ($notification->delivered_at && $notification->created_at) {
                return $notification->created_at->diffInSeconds($notification->delivered_at);
            }

            return null;
        })->filter()->sort()->values();

        if ($latencies->isEmpty()) {
            return [
                'avg_delivery_latency_seconds' => 0,
                'p50_latency_seconds' => 0,
                'p95_latency_seconds' => 0,
                'min_latency_seconds' => 0,
                'max_latency_seconds' => 0,
            ];
        }

        return [
            'avg_delivery_latency_seconds' => round($latencies->avg(), 2),
            'p50_latency_seconds' => $latencies->get((int) ($latencies->count() * 0.5)),
            'p95_latency_seconds' => $latencies->get((int) ($latencies->count() * 0.95)),
            'min_latency_seconds' => $latencies->min(),
            'max_latency_seconds' => $latencies->max(),
        ];
    }

    /**
     * Preference Metrics
     */
    public function getPreferenceMetrics(): array
    {
        $totalUsers = User::count();
        $usersWithPreferences = NotificationPreference::distinct('user_id')->count('user_id');

        $emailEnabled = NotificationPreference::where('email_enabled', true)->count();
        $smsEnabled = NotificationPreference::where('sms_enabled', true)->count();
        $emailDisabled = NotificationPreference::where('email_enabled', false)->count();
        $smsDisabled = NotificationPreference::where('sms_enabled', false)->count();

        return [
            'total_users' => $totalUsers,
            'users_with_preferences' => $usersWithPreferences,
            'email_enabled_count' => $emailEnabled,
            'sms_enabled_count' => $smsEnabled,
            'email_disabled_count' => $emailDisabled,
            'sms_disabled_count' => $smsDisabled,
            'email_opt_out_rate' => $emailEnabled > 0 ? round(($emailDisabled / ($emailEnabled + $emailDisabled)) * 100, 2) : 0,
            'sms_opt_in_rate' => ($smsEnabled + $smsDisabled) > 0 ? round(($smsEnabled / ($smsEnabled + $smsDisabled)) * 100, 2) : 0,
        ];
    }

    /**
     * Digest Metrics
     */
    public function getDigestMetrics(array $filters = []): array
    {
        $immediateCount = NotificationPreference::where('delivery_mode', 'immediate')->count();
        $dailyCount = NotificationPreference::where('delivery_mode', 'daily_digest')->count();
        $weeklyCount = NotificationPreference::where('delivery_mode', 'weekly_digest')->count();
        $total = $immediateCount + $dailyCount + $weeklyCount;

        return [
            'immediate_users' => $immediateCount,
            'daily_digest_users' => $dailyCount,
            'weekly_digest_users' => $weeklyCount,
            'digest_adoption_rate' => $total > 0 ? round((($dailyCount + $weeklyCount) / $total) * 100, 2) : 0,
            'daily_vs_weekly_ratio' => ($dailyCount + $weeklyCount) > 0 ? round($dailyCount / ($dailyCount + $weeklyCount), 2) : 0,
        ];
    }

    /**
     * Helper: Notifications by type
     * Phase 5.1 Fix: Added type filter support
     */
    protected function getNotificationsByType(array $filters = []): array
    {
        $query = Notification::query();

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // FIX: Apply type filter if present
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Get results and manually convert enum to string for array keys
        $results = $query->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->get();

        // Convert enum objects to strings for array keys
        $output = [];
        foreach ($results as $result) {
            $typeString = $result->type instanceof \App\Enums\NotificationType
                ? $result->type->value
                : (string) $result->type;
            $output[$typeString] = $result->count;
        }

        return $output;
    }

    /**
     * Helper: Notifications by day
     */
    protected function getNotificationsByDay(array $filters = []): array
    {
        $query = Notification::query();

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        return $query->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * Helper: Notifications by user role
     */
    protected function getNotificationsByUserRole(array $filters = []): array
    {
        $query = Notification::query()->join('users', 'notifications.user_id', '=', 'users.id');

        if (isset($filters['start_date'])) {
            $query->where('notifications.created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('notifications.created_at', '<=', $filters['end_date']);
        }

        return $query->select('users.user_type', DB::raw('count(*) as count'))
            ->groupBy('users.user_type')
            ->get()
            ->pluck('count', 'user_type')
            ->toArray();
    }

    /**
     * Helper: Average notifications per user
     */
    protected function getAvgNotificationsPerUser(array $filters = []): float
    {
        $query = Notification::query();

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $totalNotifications = $query->count();
        $uniqueUsers = $query->distinct('user_id')->count('user_id');

        return $uniqueUsers > 0 ? round($totalNotifications / $uniqueUsers, 2) : 0;
    }
}
