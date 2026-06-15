<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Sms\FakeSmsClient;
use App\Services\SmsDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationSmsDeliveryTest
 *
 * Tests SMS delivery of notifications.
 * Phase 3.7: SMS delivery only, idempotent, safe.
 * Phase 3.8: UPDATED - Now respects user preferences
 */
class NotificationSmsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SmsDeliveryService $smsDeliveryService;

    protected FakeSmsClient $fakeSmsClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->tenant()->create([
            'email' => 'tenant@example.com',
            'phone' => '+12025551234',
        ]);

        // Bind fake SMS client
        $this->fakeSmsClient = new FakeSmsClient;
        $this->app->instance(\App\Services\Sms\SmsClientInterface::class, $this->fakeSmsClient);

        $this->smsDeliveryService = app(SmsDeliveryService::class);

        // Phase 3.8: Enable SMS by default for all tests
        // (SMS is disabled by default in preferences)
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => true, // Enable SMS for tests
        ]);

        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'payment_succeeded',
            'email_enabled' => true,
            'sms_enabled' => true,
        ]);

        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'payment_failed',
            'email_enabled' => true,
            'sms_enabled' => true,
        ]);

        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_overdue',
            'email_enabled' => true,
            'sms_enabled' => true,
        ]);
    }

    public function test_sms_is_delivered_successfully()
    {
        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'title' => 'Rent Generated',
            'message' => 'Your rent is due',
        ]);

        // Deliver
        $result = $this->smsDeliveryService->deliver($notification);

        // Verify result
        $this->assertTrue($result);

        // Verify SMS sent
        $this->assertEquals(1, $this->fakeSmsClient->getSentCount());
        $this->assertTrue($this->fakeSmsClient->assertSentTo('+12025551234'));
        $this->assertTrue($this->fakeSmsClient->assertMessageSent('Rent Generated'));

        // Verify sms_delivered_at set
        $notification->refresh();
        $this->assertNotNull($notification->sms_delivered_at);
        $this->assertNull($notification->sms_failed_at);
        $this->assertNull($notification->sms_error);
    }

    public function test_sms_is_not_sent_twice()
    {
        // Create and deliver notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        $this->smsDeliveryService->deliver($notification);
        $this->assertEquals(1, $this->fakeSmsClient->getSentCount());

        // Try to deliver again
        $result = $this->smsDeliveryService->deliver($notification);

        // Verify skipped
        $this->assertFalse($result);
        $this->assertEquals(1, $this->fakeSmsClient->getSentCount()); // Still only 1
    }

    public function test_failed_sms_sets_sms_failed_at()
    {
        // Configure fake client to fail
        $this->fakeSmsClient->shouldFail('SMS gateway timeout');

        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt delivery
        $result = $this->smsDeliveryService->deliver($notification);

        // Verify failed
        $this->assertFalse($result);

        // Verify failure fields set
        $notification->refresh();
        $this->assertNull($notification->sms_delivered_at);
        $this->assertNotNull($notification->sms_failed_at);
        $this->assertStringContainsString('SMS gateway timeout', $notification->sms_error);
    }

    public function test_sms_delivery_does_not_modify_notification_content()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::PAYMENT_SUCCEEDED,
            'title' => 'Payment Received',
            'message' => 'Payment of $2,500.00 received',
            'data' => ['amount_cents' => 250000],
        ]);

        // Store original values
        $originalTitle = $notification->title;
        $originalMessage = $notification->message;
        $originalData = $notification->data;
        $originalType = $notification->type;

        // Deliver
        $this->smsDeliveryService->deliver($notification);

        // Verify content unchanged
        $notification->refresh();
        $this->assertEquals($originalTitle, $notification->title);
        $this->assertEquals($originalMessage, $notification->message);
        $this->assertEquals($originalData, $notification->data);
        $this->assertEquals($originalType, $notification->type);
    }

    public function test_only_undelivered_sms_notifications_are_processed()
    {
        // Create 3 notifications: undelivered, delivered, failed
        $undelivered = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        $delivered = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'sms_delivered_at' => now(),
        ]);

        $failed = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'sms_failed_at' => now(),
        ]);

        // Deliver pending
        $result = $this->smsDeliveryService->deliverPending(10);

        // Verify only 1 delivered
        $this->assertEquals(1, $result['delivered']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);

        // Verify only 1 SMS sent
        $this->assertEquals(1, $this->fakeSmsClient->getSentCount());
    }

    public function test_sms_delivery_command_outputs_summary()
    {
        // Create 3 notifications
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Run command
        $this->artisan('notifications:sms-deliver')
            ->expectsOutput('📱 Nexus - SMS Notification Delivery')
            ->assertSuccessful();

        // Verify all delivered
        $this->assertEquals(3, Notification::whereNotNull('sms_delivered_at')->count());
        $this->assertEquals(3, $this->fakeSmsClient->getSentCount());
    }

    public function test_sms_delivery_is_idempotent()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Deliver multiple times
        $this->smsDeliveryService->deliver($notification);
        $this->smsDeliveryService->deliver($notification);
        $this->smsDeliveryService->deliver($notification);

        // Verify only sent once
        $this->assertEquals(1, $this->fakeSmsClient->getSentCount());

        // Verify only one sms_delivered_at value
        $notification->refresh();
        $firstDeliveredAt = $notification->sms_delivered_at;

        // Wait and try again
        sleep(1);
        $this->smsDeliveryService->deliver($notification);

        $notification->refresh();
        $this->assertEquals($firstDeliveredAt, $notification->sms_delivered_at);
    }

    public function test_batch_sms_delivery_respects_limit()
    {
        // Create 10 notifications
        Notification::factory()->count(10)->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Deliver with limit of 5
        $result = $this->smsDeliveryService->deliverPending(5);

        // Verify only 5 delivered
        $this->assertEquals(5, $result['delivered']);
        $this->assertEquals(5, $this->fakeSmsClient->getSentCount());

        // Verify 5 still pending
        $pending = Notification::whereNull('sms_delivered_at')
            ->whereNull('sms_failed_at')
            ->count();
        $this->assertEquals(5, $pending);
    }

    public function test_notifications_without_phone_are_skipped()
    {
        // Create user without phone
        $userWithoutPhone = User::factory()->tenant()->create([
            'phone' => null,
        ]);

        $notification = Notification::factory()->create([
            'user_id' => $userWithoutPhone->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt delivery
        $result = $this->smsDeliveryService->deliverPending(10);

        // Verify skipped
        $this->assertEquals(0, $result['delivered']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(1, $result['skipped']);

        $this->assertEquals(0, $this->fakeSmsClient->getSentCount());
    }

    public function test_failed_sms_can_be_retried()
    {
        // Create notification with failed delivery
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'sms_failed_at' => now()->subHour(),
            'sms_error' => 'Previous error',
        ]);

        // Retry failed
        $result = $this->smsDeliveryService->retryFailed(10);

        // Verify delivered
        $this->assertEquals(1, $result['delivered']);
        $this->assertEquals(0, $result['failed']);

        // Verify failure fields cleared
        $notification->refresh();
        $this->assertNotNull($notification->sms_delivered_at);
        $this->assertNull($notification->sms_failed_at);
        $this->assertNull($notification->sms_error);

        $this->assertEquals(1, $this->fakeSmsClient->getSentCount());
    }

    public function test_sms_pending_count_is_accurate()
    {
        // Create various notification states
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);
        Notification::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'sms_delivered_at' => now(),
        ]);
        Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'sms_failed_at' => now(),
        ]);

        // Verify count
        $count = $this->smsDeliveryService->getPendingCount();
        $this->assertEquals(3, $count);
    }

    public function test_sms_failed_count_is_accurate()
    {
        // Create various notification states
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);
        Notification::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'sms_failed_at' => now(),
        ]);

        // Verify count
        $count = $this->smsDeliveryService->getFailedCount();
        $this->assertEquals(2, $count);
    }

    public function test_email_and_sms_delivery_are_independent()
    {
        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'delivered_at' => now(), // Email already delivered
        ]);

        // Deliver SMS (should work independently)
        $result = $this->smsDeliveryService->deliver($notification);

        // Verify SMS delivered
        $this->assertTrue($result);
        $this->assertEquals(1, $this->fakeSmsClient->getSentCount());

        // Verify email delivery unchanged
        $notification->refresh();
        $this->assertNotNull($notification->delivered_at);
        $this->assertNotNull($notification->sms_delivered_at);
    }
}
