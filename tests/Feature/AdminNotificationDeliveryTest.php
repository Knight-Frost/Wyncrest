<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin platform delivery monitor (GET /api/admin/notifications/deliveries).
 *
 * Confirms the monitor reports real per-channel delivery status derived from
 * the notifications table, computes accurate summary counts, filters by
 * outcome/channel/recipient, and is admin-only.
 */
class AdminNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
    }

    private function seedDeliveries(): array
    {
        $recipient = User::factory()->create([
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'email' => 'ama@example.com',
        ]);

        $delivered = Notification::factory()->create([
            'user_id' => $recipient->id,
            'type' => NotificationType::RENT_GENERATED,
            'delivered_at' => now()->subHours(2),
        ]);

        $failed = Notification::factory()->create([
            'user_id' => $recipient->id,
            'type' => NotificationType::PAYMENT_FAILED,
            'delivery_failed_at' => now()->subHour(),
            'delivery_error' => 'SMTP 550 mailbox unavailable',
        ]);

        $smsFailed = Notification::factory()->create([
            'user_id' => $recipient->id,
            'type' => NotificationType::RENT_OVERDUE,
            'sms_failed_at' => now()->subMinutes(30),
            'sms_error' => 'Twilio 21610 unsubscribed recipient',
        ]);

        $notSent = Notification::factory()->create([
            'user_id' => $recipient->id,
            'type' => NotificationType::CONTRACT_SIGNED,
        ]);

        return compact('recipient', 'delivered', 'failed', 'smsFailed', 'notSent');
    }

    public function test_lists_deliveries_with_per_channel_status_and_summary(): void
    {
        $this->seedDeliveries();
        Sanctum::actingAs($this->admin, [], 'sanctum');

        $response = $this->getJson('/api/admin/notifications/deliveries');

        $response->assertOk();
        $response->assertJsonPath('summary.total', 4);
        $response->assertJsonPath('summary.email.delivered', 1);
        $response->assertJsonPath('summary.email.failed', 1);
        $response->assertJsonPath('summary.sms.failed', 1);
        // failed_total counts distinct notifications with any channel failure.
        $response->assertJsonPath('summary.failed_total', 2);

        // Recipient info and channel error surface truthfully.
        $response->assertJsonFragment(['email' => 'ama@example.com']);
        $response->assertJsonFragment(['error' => 'SMTP 550 mailbox unavailable']);
    }

    public function test_filters_by_failed_status(): void
    {
        $this->seedDeliveries();
        Sanctum::actingAs($this->admin, [], 'sanctum');

        $response = $this->getJson('/api/admin/notifications/deliveries?status=failed');

        $response->assertOk();
        // Only the two failures (email-failed + sms-failed) appear.
        $response->assertJsonCount(2, 'data');
    }

    public function test_filters_by_channel_and_status(): void
    {
        $this->seedDeliveries();
        Sanctum::actingAs($this->admin, [], 'sanctum');

        $response = $this->getJson('/api/admin/notifications/deliveries?channel=email&status=failed');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.email.status', 'failed');
    }

    public function test_search_by_recipient(): void
    {
        $this->seedDeliveries();
        User::factory()->create(['email' => 'someone-else@example.com']);
        Notification::factory()->create([
            'user_id' => User::where('email', 'someone-else@example.com')->first()->id,
            'delivered_at' => now(),
        ]);

        Sanctum::actingAs($this->admin, [], 'sanctum');

        $response = $this->getJson('/api/admin/notifications/deliveries?search=ama@example.com');

        $response->assertOk();
        $response->assertJsonCount(4, 'data');
    }

    public function test_forbidden_for_non_admin(): void
    {
        $landlord = User::factory()->landlord()->create();
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->getJson('/api/admin/notifications/deliveries')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/notifications/deliveries')->assertUnauthorized();
    }
}
