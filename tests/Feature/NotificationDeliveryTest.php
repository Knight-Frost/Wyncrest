<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Mail\NotificationEmail;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * NotificationDeliveryTest
 *
 * Tests email delivery of notifications.
 * Phase 3.6: Email delivery only, idempotent, safe.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected NotificationDeliveryService $deliveryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->tenant()->create([
            'email' => 'tenant@example.com',
        ]);

        $this->deliveryService = app(NotificationDeliveryService::class);
    }

    public function test_notification_is_delivered_via_email()
    {
        Mail::fake();

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
            'title' => 'Rent Generated',
            'message' => 'Your rent is due',
        ]);

        // Deliver
        $result = $this->deliveryService->deliver($notification);

        // Verify result
        $this->assertTrue($result);

        // Verify email sent
        Mail::assertSent(NotificationEmail::class, function ($mail) use ($notification) {
            return $mail->notification->id === $notification->id
                && $mail->hasTo('tenant@example.com');
        });

        // Verify delivered_at set
        $notification->refresh();
        $this->assertNotNull($notification->delivered_at);
        $this->assertNull($notification->delivery_failed_at);
        $this->assertNull($notification->delivery_error);
    }

    public function test_delivered_notification_is_not_sent_twice()
    {
        Mail::fake();

        // Create and deliver notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        $this->deliveryService->deliver($notification);
        Mail::assertSentCount(1);

        // Try to deliver again
        $result = $this->deliveryService->deliver($notification);

        // Verify skipped
        $this->assertFalse($result);
        Mail::assertSentCount(1); // Still only 1
    }

    public function test_failed_delivery_sets_failed_at()
    {
        // Force Mail to throw exception
        Mail::shouldReceive('to')
            ->andThrow(new \Exception('SMTP connection failed'));

        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt delivery
        $result = $this->deliveryService->deliver($notification);

        // Verify failed
        $this->assertFalse($result);

        // Verify failure fields set
        $notification->refresh();
        $this->assertNull($notification->delivered_at);
        $this->assertNotNull($notification->delivery_failed_at);
        $this->assertEquals('SMTP connection failed', $notification->delivery_error);
    }

    public function test_delivery_does_not_modify_notification_content()
    {
        Mail::fake();

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
        $this->deliveryService->deliver($notification);

        // Verify content unchanged
        $notification->refresh();
        $this->assertEquals($originalTitle, $notification->title);
        $this->assertEquals($originalMessage, $notification->message);
        $this->assertEquals($originalData, $notification->data);
        $this->assertEquals($originalType, $notification->type);
    }

    public function test_only_undelivered_notifications_are_processed()
    {
        Mail::fake();

        // Create 3 notifications: undelivered, delivered, failed
        $undelivered = Notification::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $delivered = Notification::factory()->create([
            'user_id' => $this->user->id,
            'delivered_at' => now(),
        ]);

        $failed = Notification::factory()->create([
            'user_id' => $this->user->id,
            'delivery_failed_at' => now(),
        ]);

        // Deliver pending
        $result = $this->deliveryService->deliverPending(10);

        // Verify only 1 delivered
        $this->assertEquals(1, $result['delivered']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);

        // Verify only 1 email sent
        Mail::assertSentCount(1);
    }

    public function test_delivery_command_outputs_summary()
    {
        Mail::fake();

        // Create 3 notifications
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        // Run command
        $this->artisan('notifications:deliver')
            ->expectsOutput('📬 Nexus - Notification Delivery')
            ->assertSuccessful();

        // Verify all delivered
        $this->assertEquals(3, Notification::whereNotNull('delivered_at')->count());
        Mail::assertSentCount(3);
    }

    public function test_delivery_is_idempotent()
    {
        Mail::fake();

        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Deliver multiple times
        $this->deliveryService->deliver($notification);
        $this->deliveryService->deliver($notification);
        $this->deliveryService->deliver($notification);

        // Verify only sent once
        Mail::assertSentCount(1);

        // Verify only one delivered_at value
        $notification->refresh();
        $firstDeliveredAt = $notification->delivered_at;

        // Wait and try again
        sleep(1);
        $this->deliveryService->deliver($notification);

        $notification->refresh();
        $this->assertEquals($firstDeliveredAt, $notification->delivered_at);
    }

    public function test_batch_delivery_respects_limit()
    {
        Mail::fake();

        // Create 10 notifications
        Notification::factory()->count(10)->create([
            'user_id' => $this->user->id,
        ]);

        // Deliver with limit of 5
        $result = $this->deliveryService->deliverPending(5);

        // Verify only 5 delivered
        $this->assertEquals(5, $result['delivered']);
        Mail::assertSentCount(5);

        // Verify 5 still pending
        $pending = Notification::whereNull('delivered_at')
            ->whereNull('delivery_failed_at')
            ->count();
        $this->assertEquals(5, $pending);
    }

    public function test_notifications_with_invalid_user_are_skipped()
    {
        Mail::fake();

        // Create notification for non-existent user (deleted user scenario)
        $tempUser = User::factory()->tenant()->create();
        $notification = Notification::factory()->create([
            'user_id' => $tempUser->id,
        ]);

        // Delete the user
        $tempUser->delete();

        // Attempt delivery
        $result = $this->deliveryService->deliverPending(10);

        // Verify skipped (user no longer exists)
        $this->assertEquals(0, $result['delivered']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(1, $result['skipped']);

        Mail::assertNothingSent();
    }

    public function test_failed_notifications_can_be_retried()
    {
        Mail::fake();

        // Create notification with failed delivery
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'delivery_failed_at' => now()->subHour(),
            'delivery_error' => 'Previous error',
        ]);

        // Retry failed
        $result = $this->deliveryService->retryFailed(10);

        // Verify delivered
        $this->assertEquals(1, $result['delivered']);
        $this->assertEquals(0, $result['failed']);

        // Verify failure fields cleared
        $notification->refresh();
        $this->assertNotNull($notification->delivered_at);
        $this->assertNull($notification->delivery_failed_at);
        $this->assertNull($notification->delivery_error);

        Mail::assertSentCount(1);
    }

    public function test_email_contains_notification_content()
    {
        Mail::fake();

        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_OVERDUE,
            'title' => 'Rent Payment Overdue',
            'message' => 'Your rent payment of $2,500 is now overdue',
            'data' => [
                'amount_cents' => 250000,
                'due_date' => '2025-03-01',
            ],
        ]);

        $this->deliveryService->deliver($notification);

        Mail::assertSent(NotificationEmail::class, function ($mail) use ($notification) {
            return $mail->notification->id === $notification->id
                && $mail->envelope()->subject === 'Rent Payment Overdue';
        });
    }

    public function test_pending_count_is_accurate()
    {
        // Create various notification states
        Notification::factory()->count(3)->create(['user_id' => $this->user->id]);
        Notification::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'delivered_at' => now(),
        ]);
        Notification::factory()->create([
            'user_id' => $this->user->id,
            'delivery_failed_at' => now(),
        ]);

        // Verify count
        $count = $this->deliveryService->getPendingCount();
        $this->assertEquals(3, $count);
    }

    public function test_failed_count_is_accurate()
    {
        // Create various notification states
        Notification::factory()->count(3)->create(['user_id' => $this->user->id]);
        Notification::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'delivery_failed_at' => now(),
        ]);

        // Verify count
        $count = $this->deliveryService->getFailedCount();
        $this->assertEquals(2, $count);
    }
}
