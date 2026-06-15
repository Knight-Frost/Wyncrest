<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationDeliveryService;
use App\Services\PreferenceResolver;
use App\Services\Sms\FakeSmsClient;
use App\Services\SmsDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * NotificationPreferenceTest
 *
 * Tests user notification preferences.
 * Phase 3.8: Email/SMS preferences per notification type.
 */
class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected NotificationDeliveryService $emailDeliveryService;

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

        $this->emailDeliveryService = app(NotificationDeliveryService::class);
        $this->smsDeliveryService = app(SmsDeliveryService::class);
    }

    public function test_email_delivery_is_skipped_when_email_disabled()
    {
        Mail::fake();

        // Disable email for rent_generated
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => false,
            'sms_enabled' => false,
        ]);

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt email delivery
        $result = $this->emailDeliveryService->deliver($notification);

        // Verify skipped (not failed)
        $this->assertFalse($result);
        Mail::assertNothingSent();

        // Verify NO failure timestamps set
        $notification->refresh();
        $this->assertNull($notification->delivered_at);
        $this->assertNull($notification->delivery_failed_at);
        $this->assertNull($notification->delivery_error);
    }

    public function test_sms_delivery_is_skipped_when_sms_disabled()
    {
        // Disable SMS for rent_generated (default is disabled anyway)
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => false,
        ]);

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt SMS delivery
        $result = $this->smsDeliveryService->deliver($notification);

        // Verify skipped (not failed)
        $this->assertFalse($result);
        $this->assertEquals(0, $this->fakeSmsClient->getSentCount());

        // Verify NO failure timestamps set
        $notification->refresh();
        $this->assertNull($notification->sms_delivered_at);
        $this->assertNull($notification->sms_failed_at);
        $this->assertNull($notification->sms_error);
    }

    public function test_email_and_sms_remain_independent()
    {
        Mail::fake();

        // Enable SMS, disable email
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => false,
            'sms_enabled' => true,
        ]);

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt email delivery (should skip)
        $emailResult = $this->emailDeliveryService->deliver($notification);
        $this->assertFalse($emailResult);
        Mail::assertNothingSent();

        // Attempt SMS delivery (should succeed)
        $smsResult = $this->smsDeliveryService->deliver($notification);
        $this->assertTrue($smsResult);
        $this->assertEquals(1, $this->fakeSmsClient->getSentCount());

        // Verify state
        $notification->refresh();
        $this->assertNull($notification->delivered_at); // Email skipped
        $this->assertNotNull($notification->sms_delivered_at); // SMS delivered
    }

    public function test_no_preference_record_uses_defaults()
    {
        Mail::fake();

        // No preference record created
        // Defaults: email = true, sms = false

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Email should deliver (default enabled)
        $emailResult = $this->emailDeliveryService->deliver($notification);
        $this->assertTrue($emailResult);
        Mail::assertSentCount(1);

        // SMS should skip (default disabled)
        $smsResult = $this->smsDeliveryService->deliver($notification);
        $this->assertFalse($smsResult);
        $this->assertEquals(0, $this->fakeSmsClient->getSentCount());
    }

    public function test_skipping_does_not_set_failure_timestamps()
    {
        Mail::fake();

        // Disable both channels
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => false,
            'sms_enabled' => false,
        ]);

        // Create notification
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'type' => NotificationType::RENT_GENERATED,
        ]);

        // Attempt deliveries
        $this->emailDeliveryService->deliver($notification);
        $this->smsDeliveryService->deliver($notification);

        // Verify NO failure timestamps
        $notification->refresh();
        $this->assertNull($notification->delivered_at);
        $this->assertNull($notification->delivery_failed_at);
        $this->assertNull($notification->sms_delivered_at);
        $this->assertNull($notification->sms_failed_at);
    }

    public function test_preference_resolver_returns_correct_values()
    {
        $resolver = app(PreferenceResolver::class);

        // Create preference
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => false,
            'sms_enabled' => true,
        ]);

        // Resolve
        $preferences = $resolver->resolve($this->user, NotificationType::RENT_GENERATED);

        $this->assertFalse($preferences['email']);
        $this->assertTrue($preferences['sms']);
    }

    public function test_preference_resolver_returns_defaults_when_no_record()
    {
        $resolver = app(PreferenceResolver::class);

        // No record exists
        $preferences = $resolver->resolve($this->user, NotificationType::RENT_GENERATED);

        // Defaults: email = true, sms = false
        $this->assertTrue($preferences['email']);
        $this->assertFalse($preferences['sms']);
    }

    public function test_user_can_get_their_preferences_via_api()
    {
        // Create some preferences
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => false,
            'sms_enabled' => true,
        ]);

        // Get preferences
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/notification-preferences');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'rent_generated' => ['email', 'sms'],
            'payment_succeeded' => ['email', 'sms'],
        ]);

        // Verify rent_generated matches
        $this->assertFalse($response->json('rent_generated.email'));
        $this->assertTrue($response->json('rent_generated.sms'));
    }

    public function test_user_can_update_their_preferences_via_api()
    {
        // Update preferences
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/notification-preferences', [
                'rent_generated' => [
                    'email' => false,
                    'sms' => true,
                ],
                'payment_failed' => [
                    'email' => true,
                    'sms' => true,
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Preferences updated successfully',
        ]);

        // Verify database
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => false,
            'sms_enabled' => true,
        ]);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->user->id,
            'notification_type' => 'payment_failed',
            'email_enabled' => true,
            'sms_enabled' => true,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_preferences()
    {
        $response = $this->getJson('/api/notification-preferences');
        $response->assertStatus(401);

        $response = $this->putJson('/api/notification-preferences', []);
        $response->assertStatus(401);
    }

    public function test_preferences_are_isolated_per_user()
    {
        $otherUser = User::factory()->tenant()->create();

        // Create preference for user1
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => false,
            'sms_enabled' => false,
        ]);

        // Create preference for user2
        NotificationPreference::create([
            'user_id' => $otherUser->id,
            'notification_type' => 'rent_generated',
            'email_enabled' => true,
            'sms_enabled' => true,
        ]);

        // Resolver should respect user isolation
        $resolver = app(PreferenceResolver::class);

        $user1Prefs = $resolver->resolve($this->user, NotificationType::RENT_GENERATED);
        $user2Prefs = $resolver->resolve($otherUser, NotificationType::RENT_GENERATED);

        $this->assertFalse($user1Prefs['email']);
        $this->assertTrue($user2Prefs['email']);
    }
}
