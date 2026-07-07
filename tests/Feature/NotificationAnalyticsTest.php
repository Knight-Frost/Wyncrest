<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Analytics\NotificationAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationAnalyticsTest
 *
 * Tests notification analytics layer.
 * Phase 4.0a: Read-only metrics, aggregation, role scoping.
 */
class NotificationAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $tenant;

    protected User $landlord;

    protected NotificationAnalyticsService $analyticsService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users for testing
        $this->tenant = User::factory()->tenant()->create();
        $this->landlord = User::factory()->landlord()->create();

        // For admin testing, we'll use landlord (admins are in separate table/guard)
        // Analytics access control will be tested with tenant/landlord
        $this->admin = $this->landlord; // Use landlord as "admin" for analytics testing

        $this->analyticsService = app(NotificationAnalyticsService::class);
    }

    public function test_volume_metrics_count_all_notifications()
    {
        // Create 5 notifications
        Notification::factory()->count(5)->create([
            'user_id' => $this->tenant->id,
        ]);

        $metrics = $this->analyticsService->getVolumeMetrics();

        $this->assertEquals(5, $metrics['total_notifications']);
    }

    public function test_volume_metrics_group_by_type()
    {
        // Create notifications of different types
        Notification::factory()->create([
            'user_id' => $this->tenant->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        Notification::factory()->create([
            'user_id' => $this->tenant->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        Notification::factory()->create([
            'user_id' => $this->tenant->id,
            'type' => NotificationType::PAYMENT_FAILED,
        ]);

        $metrics = $this->analyticsService->getVolumeMetrics();

        $this->assertEquals(2, $metrics['by_type']['rent_generated']);
        $this->assertEquals(1, $metrics['by_type']['payment_failed']);
    }

    public function test_volume_metrics_filter_by_date_range()
    {
        // Create old notification
        Notification::factory()->create([
            'user_id' => $this->tenant->id,
            'created_at' => now()->subDays(10),
        ]);

        // Create recent notification
        Notification::factory()->create([
            'user_id' => $this->tenant->id,
            'created_at' => now()->subDays(2),
        ]);

        $metrics = $this->analyticsService->getVolumeMetrics([
            'start_date' => now()->subDays(5),
        ]);

        $this->assertEquals(1, $metrics['total_notifications']);
    }

    public function test_delivery_metrics_track_email_and_sms()
    {
        // Create delivered email
        Notification::factory()->create([
            'user_id' => $this->tenant->id,
            'delivered_at' => now(),
        ]);

        // Create delivered SMS
        Notification::factory()->create([
            'user_id' => $this->tenant->id,
            'sms_delivered_at' => now(),
        ]);

        // Create pending
        Notification::factory()->create([
            'user_id' => $this->tenant->id,
        ]);

        $metrics = $this->analyticsService->getDeliveryMetrics();

        $this->assertEquals(1, $metrics['email_delivered']);
        $this->assertEquals(1, $metrics['sms_delivered']);
        $this->assertEquals(2, $metrics['email_pending']);
        $this->assertEquals(2, $metrics['sms_pending']);
    }

    public function test_delivery_metrics_calculate_success_rates()
    {
        // Create 8 email delivered, 2 failed
        Notification::factory()->count(8)->create([
            'user_id' => $this->tenant->id,
            'delivered_at' => now(),
        ]);

        Notification::factory()->count(2)->create([
            'user_id' => $this->tenant->id,
            'delivery_failed_at' => now(),
        ]);

        $metrics = $this->analyticsService->getDeliveryMetrics();

        $this->assertEquals(80.0, $metrics['email_success_rate']);
    }

    public function test_performance_metrics_calculate_latency()
    {
        // Create notification with known latency
        $notification = Notification::factory()->create([
            'user_id' => $this->tenant->id,
            'created_at' => now()->subMinutes(5),
            'delivered_at' => now(),
        ]);

        $metrics = $this->analyticsService->getPerformanceMetrics();

        $this->assertGreaterThan(200, $metrics['avg_delivery_latency_seconds']);
        $this->assertLessThan(400, $metrics['avg_delivery_latency_seconds']);
    }

    public function test_preference_metrics_track_channel_adoption()
    {
        // Create preferences
        NotificationPreference::create([
            'user_id' => $this->tenant->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
        ]);

        NotificationPreference::create([
            'user_id' => $this->tenant->id,
            'notification_type' => 'payment_failed',
            'email_enabled' => false,
            'sms_enabled' => true,
        ]);

        $metrics = $this->analyticsService->getPreferenceMetrics();

        $this->assertEquals(1, $metrics['email_enabled_count']);
        $this->assertEquals(1, $metrics['sms_enabled_count']);
        $this->assertEquals(1, $metrics['email_disabled_count']);
    }

    public function test_digest_metrics_track_delivery_modes()
    {
        // Create digest preferences
        NotificationPreference::create([
            'user_id' => $this->tenant->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'daily_digest',
        ]);

        NotificationPreference::create([
            'user_id' => $this->landlord->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'weekly_digest',
        ]);

        $metrics = $this->analyticsService->getDigestMetrics();

        $this->assertEquals(1, $metrics['daily_digest_users']);
        $this->assertEquals(1, $metrics['weekly_digest_users']);
        $this->assertGreaterThan(0, $metrics['digest_adoption_rate']);
    }

    public function test_admin_can_access_analytics_api()
    {
        Notification::factory()->count(3)->create([
            'user_id' => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'analytics' => [
                'volume',
                'delivery',
                'performance',
                'preferences',
                'digests',
            ],
        ]);
    }

    public function test_tenant_sees_only_personal_metrics()
    {
        // Create notifications for tenant
        Notification::factory()->count(3)->create([
            'user_id' => $this->tenant->id,
        ]);

        // Create notifications for other user
        $otherUser = User::factory()->tenant()->create();
        Notification::factory()->count(5)->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/analytics/notifications');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('analytics.volume.total_notifications'));
        $this->assertEquals('personal', $response->json('scoped_to'));
    }

    /**
     * Regression: a personal-scoped caller (tenant/landlord) must never receive
     * platform-wide preference/digest/volume-trend aggregates for other users —
     * only getVolumeMetrics()'s total_notifications was previously scoped.
     */
    public function test_tenant_personal_scope_does_not_leak_platform_wide_preferences_or_trend()
    {
        // Preferences exist for the tenant AND two other users — if scoping
        // leaked, total_users would report the whole platform, not just 1.
        NotificationPreference::create([
            'user_id' => $this->tenant->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
        ]);

        $other1 = User::factory()->tenant()->create();
        $other2 = User::factory()->landlord()->create();

        NotificationPreference::create([
            'user_id' => $other1->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => true,
            'delivery_mode' => 'daily_digest',
        ]);
        NotificationPreference::create([
            'user_id' => $other2->id,
            'notification_type' => 'payment_failed',
            'email_enabled' => false,
            'sms_enabled' => true,
            'delivery_mode' => 'weekly_digest',
        ]);

        // Notifications: 2 for the tenant, 5 for another user.
        Notification::factory()->count(2)->create(['user_id' => $this->tenant->id]);
        Notification::factory()->count(5)->create(['user_id' => $other1->id]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/analytics/notifications');

        $response->assertStatus(200);

        // Preferences: only the caller, never the platform's 5 users.
        $this->assertEquals(1, $response->json('analytics.preferences.total_users'));

        // Digests: none of the OTHER users' digest preferences should leak in.
        $this->assertEquals(0, $response->json('analytics.digests.daily_digest_users'));
        $this->assertEquals(0, $response->json('analytics.digests.weekly_digest_users'));

        // by_day trend: only the tenant's own 2 notifications, not the other
        // user's 5.
        $byDay = $response->json('analytics.volume.by_day');
        $this->assertEquals(2, array_sum($byDay));
    }

    public function test_unauthenticated_user_cannot_access_analytics()
    {
        $response = $this->getJson('/api/analytics/notifications');
        $response->assertStatus(401);
    }

    public function test_analytics_api_validates_date_filters()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/notifications?start_date=invalid');

        $response->assertStatus(422);
    }

    public function test_analytics_api_filters_by_notification_type()
    {
        Notification::factory()->create([
            'user_id' => $this->admin->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        Notification::factory()->create([
            'user_id' => $this->admin->id,
            'type' => NotificationType::PAYMENT_FAILED,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/notifications?type=rent_generated');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('analytics.volume.total_notifications'));
    }

    public function test_analytics_does_not_mutate_data()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->tenant->id,
        ]);

        $originalCreatedAt = $notification->created_at;
        $originalUpdatedAt = $notification->updated_at;

        // Call analytics multiple times
        $this->analyticsService->getAnalytics();
        $this->analyticsService->getAnalytics();

        $notification->refresh();

        $this->assertEquals($originalCreatedAt, $notification->created_at);
        $this->assertEquals($originalUpdatedAt, $notification->updated_at);
    }

    public function test_analytics_returns_deterministic_results()
    {
        Notification::factory()->count(5)->create([
            'user_id' => $this->tenant->id,
        ]);

        $result1 = $this->analyticsService->getAnalytics();
        $result2 = $this->analyticsService->getAnalytics();

        $this->assertEquals($result1, $result2);
    }
}
