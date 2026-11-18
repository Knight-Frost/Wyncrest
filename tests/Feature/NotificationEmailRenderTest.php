<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Mail\NotificationEmail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Launch closeout: every notification email failed to render ("htmlspecialchars():
 * Argument #1 must be of type string, Illuminate\Mail\Message given") because
 * the view variable was named $message, which Laravel reserves in mail views.
 * Mail::fake() in the delivery tests never renders the view, which hid it.
 * These tests render and send for real (array transport).
 */
class NotificationEmailRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_email_renders_the_message_body(): void
    {
        $user = User::factory()->tenant()->create();
        $n = Notification::create([
            'user_id' => $user->id,
            'type' => NotificationType::RENT_DUE_DATE_CHANGED,
            'title' => 'Rent due date changed',
            'message' => 'The December 2026 rent charge is now due on 20 Dec 2026 (was 1 Dec 2026).',
            'data' => [],
        ]);

        $html = (new NotificationEmail($n))->render();

        $this->assertStringContainsString('now due on 20 Dec 2026', $html);
    }

    public function test_delivery_command_delivers_through_a_real_transport_and_reports_failure_honestly(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->tenant()->create();
        Notification::create([
            'user_id' => $user->id, 'type' => NotificationType::RENT_GENERATED,
            'title' => 'Rent charge posted', 'message' => 'Your December rent of GH₵ 2,500.00 is due on 1 Dec 2026.', 'data' => [],
        ]);

        $this->artisan('notifications:deliver')->assertSuccessful();
        $this->assertNotNull(Notification::first()->delivered_at);
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('due on 1 Dec 2026', $sent[0]->getOriginalMessage()->getHtmlBody());

        // A failing transport must make the run fail, not print "all delivered".
        Notification::create([
            'user_id' => $user->id, 'type' => NotificationType::RENT_GENERATED,
            'title' => 'Another', 'message' => 'x', 'data' => [],
        ]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $this->artisan('notifications:deliver')->assertFailed();
    }
}
