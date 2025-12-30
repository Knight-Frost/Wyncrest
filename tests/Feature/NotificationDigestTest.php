<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Enums\NotificationType;
use App\Services\NotificationDeliveryService;
use App\Services\SmsDeliveryService;
use App\Services\NotificationDigestService;
use App\Services\Sms\FakeSmsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * NotificationDigestTest
 * 
 * Tests notification digest delivery.
 * Phase 3.9: Batched notification delivery based on delivery_mode.
 */
class NotificationDigestTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected NotificationDeliveryService $emailDeliveryService;
    protected SmsDeliveryService $smsDeliveryService;
    protected NotificationDigestService $digestService;
    protected FakeSmsClient $fakeSmsClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->tenant()->create([
            'email' => 'tenant@example.com',
            'phone' => '+12025551234',
        ]);

        // Bind fake SMS client
        $this->fakeSmsClient = new FakeSmsClient();
        $this->app->instance(\App\Services\Sms\SmsClientInterface::class, $this->fakeSmsClient);

        $this->emailDeliveryService = app(NotificationDeliveryService::class);
        $this->smsDeliveryService = app(SmsDeliveryService::class);
        $this->digestService = app(NotificationDigestService::class);
    }

    public function test_immediate_notifications_still_deliver_immediately()
    {
        Mail::fake();

        // Set preference to immediate (default)
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'immediate',
        ]);

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Deliver immediately
        $result = $this->emailDeliveryService->deliver($notification);

        // Verify delivered
        $this->assertTrue($result);
        Mail::assertSentCount(1);
        
        $notification->refresh();
        $this->assertNotNull($notification->delivered_at);
    }

    public function test_digest_mode_prevents_immediate_delivery()
    {
        Mail::fake();

        // Set preference to daily_digest
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt immediate delivery
        $result = $this->emailDeliveryService->deliver($notification);

        // Verify skipped (not delivered immediately)
        $this->assertFalse($result);
        Mail::assertNothingSent();
        
        $notification->refresh();
        $this->assertNull($notification->delivered_at);
        $this->assertNull($notification->delivery_failed_at); // NOT a failure
    }

    public function test_digest_command_sends_batched_notifications()
    {
        Mail::fake();

        // Set preference to daily_digest
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create 3 notifications
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Send daily digest
        $result = $this->digestService->sendDailyDigests();

        // Verify digest sent
        $this->assertEquals(1, $result['users']);
        $this->assertEquals(1, $result['email_digests']);
        $this->assertEquals(3, $result['notifications']);
        
        Mail::assertSentCount(1);
    }

    public function test_notifications_are_marked_delivered_after_digest()
    {
        Mail::fake();

        // Set preference to daily_digest
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create 2 notifications
        $n1 = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);
        
        $n2 = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Send daily digest
        $this->digestService->sendDailyDigests();

        // Verify both marked as delivered
        $n1->refresh();
        $n2->refresh();
        
        $this->assertNotNull($n1->delivered_at);
        $this->assertNotNull($n2->delivered_at);
    }

    public function test_email_and_sms_digests_remain_independent()
    {
        Mail::fake();

        // Set preference: email daily_digest, SMS immediate
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => true,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt immediate email delivery (should skip)
        $emailResult = $this->emailDeliveryService->deliver($notification);
        $this->assertFalse($emailResult);
        Mail::assertNothingSent();

        // Attempt immediate SMS delivery (should skip - digest mode)
        $smsResult = $this->smsDeliveryService->deliver($notification);
        $this->assertFalse($smsResult);
        $this->assertEquals(0, $this->fakeSmsClient->getSentCount());

        // Send digest
        $this->digestService->sendDailyDigests();

        // Verify both delivered via digest
        $notification->refresh();
        $this->assertNotNull($notification->delivered_at);
        $this->assertNotNull($notification->sms_delivered_at);
    }

    public function test_preferences_and_digests_interact_correctly()
    {
        Mail::fake();

        // Email enabled + daily_digest, SMS disabled
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create notification
        Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Send digest
        $result = $this->digestService->sendDailyDigests();

        // Verify only email digest sent (SMS disabled)
        $this->assertEquals(1, $result['email_digests']);
        $this->assertEquals(0, $result['sms_digests']);
    }

    public function test_no_duplicate_deliveries_occur()
    {
        Mail::fake();

        // Set preference to daily_digest
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create notification
        Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Send digest twice
        $this->digestService->sendDailyDigests();
        $this->digestService->sendDailyDigests();

        // Verify only 1 email sent
        Mail::assertSentCount(1);
    }

    public function test_skipping_due_to_digest_is_not_failure()
    {
        Mail::fake();

        // Set preference to daily_digest
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt immediate delivery
        $this->emailDeliveryService->deliver($notification);

        // Verify NOT marked as failed
        $notification->refresh();
        $this->assertNull($notification->delivery_failed_at);
        $this->assertNull($notification->delivery_error);
    }

    public function test_weekly_digest_includes_older_notifications()
    {
        Mail::fake();

        // Set preference to weekly_digest
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'weekly_digest',
        ]);

        // Create notifications over several days
        Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'created_at' => now()->subDays(5),
        ]);

        Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'created_at' => now()->subDays(2),
        ]);

        // Send weekly digest
        $result = $this->digestService->sendWeeklyDigests();

        // Verify both included
        $this->assertEquals(1, $result['email_digests']);
        $this->assertEquals(2, $result['notifications']);
    }

    public function test_digest_command_outputs_summary()
    {
        // Set preference to daily_digest
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create notification
        Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Run command
        $this->artisan('notifications:digest-daily')
            ->expectsOutput('📬 Nexus - Daily Notification Digest')
            ->assertSuccessful();
    }

    public function test_sms_digest_sends_concise_message()
    {
        // Set preference: SMS daily_digest
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => false,
            'sms_enabled' => true,
            'delivery_mode' => 'daily_digest',
        ]);

        // Create 3 notifications
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Send digest
        $this->digestService->sendDailyDigests();

        // Verify SMS sent
        $this->assertEquals(1, $this->fakeSmsClient->getSentCount());
        
        // Verify message format
        $sent = $this->fakeSmsClient->getSent()[0];
        $this->assertStringContainsString('Nexus Digest:', $sent['message']);
        $this->assertStringContainsString('3 new notifications', $sent['message']);
    }
}
