<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Sms\SmsClientInterface;
use App\Services\Sms\TwilioSmsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Launch closeout: SMS is not configured for the first launch. The API must
 * say so, and must not store an SMS opt-in that can never be delivered.
 */
class NotificationChannelAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_sms_is_reported_and_never_stored_as_enabled(): void
    {
        config(['services.twilio.sid' => null, 'services.twilio.token' => null, 'services.twilio.from' => null]);
        $this->app->instance(SmsClientInterface::class, new TwilioSmsClient);
        $user = User::factory()->tenant()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/notification-preferences/channels')
            ->assertOk()->assertJson(['in_app' => true, 'sms' => false]);

        $this->actingAs($user, 'sanctum')->putJson('/api/notification-preferences', [
            'rent_overdue' => ['email' => true, 'sms' => true],
        ])->assertOk()->assertJsonPath('sms_available', false)->assertJsonPath('preferences.rent_overdue.sms', false);

        $this->assertFalse(NotificationPreference::where('user_id', $user->id)->sole()->sms_enabled);
    }
}
