<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\NotificationType;
use App\Events\LedgerEntryMarkedOverdue;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RentGenerated;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationWorkflowTest
 *
 * Tests notification system triggered by domain events.
 * Phase 3.5: Event-driven notifications (no delivery).
 */
class NotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Contract $contract;

    protected LedgerEntry $rentEntry;

    protected NotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();

        // Register observer

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();

        // Create property, unit, listing
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // Create active contract
        $this->contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
            'rent_amount' => 250000,
            'payment_day' => 1,
        ]);

        $this->notificationService = app(NotificationService::class);
    }

    public function test_notification_created_when_rent_generated()
    {
        // Create rent entry
        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 250000,
            'billing_period_start' => Carbon::parse('2025-02-15'),
            'billing_period_end' => Carbon::parse('2025-03-14'),
            'due_date' => Carbon::parse('2025-03-01'),
        ]);

        // Fire event
        event(new RentGenerated($this->rentEntry, $this->tenant));

        // Verify notification created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant->id,
            'type' => NotificationType::RENT_GENERATED->value,
        ]);

        // Verify notification content
        $notification = Notification::where('user_id', $this->tenant->id)->first();
        $this->assertEquals('Rent Generated', $notification->title);
        $this->assertStringContainsString('$2,500', $notification->message);
        $this->assertStringContainsString('March 1, 2025', $notification->message);
        $this->assertEquals($this->rentEntry->id, $notification->data['ledger_entry_id']);
    }

    public function test_notification_created_when_entry_marked_overdue()
    {
        // Create rent entry
        $this->rentEntry = LedgerEntry::factory()->rent()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => LedgerStatus::OVERDUE,
            'due_date' => Carbon::parse('2025-02-01'),
        ]);

        // Fire event
        event(new LedgerEntryMarkedOverdue($this->rentEntry, $this->tenant));

        // Verify notification created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant->id,
            'type' => NotificationType::RENT_OVERDUE->value,
        ]);

        // Verify notification content
        $notification = Notification::where('user_id', $this->tenant->id)->first();
        $this->assertEquals('Rent Payment Overdue', $notification->title);
        $this->assertStringContainsString('overdue', $notification->message);
        $this->assertStringContainsString('Please pay as soon as possible', $notification->message);
    }

    public function test_notification_created_when_payment_succeeds()
    {
        // Create rent entry
        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 250000,
        ]);

        // Create payment entry
        $paymentEntry = LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => -250000,
            'status' => LedgerStatus::PAID,
            'related_rent_entry_id' => $this->rentEntry->id,
            'stripe_payment_intent_id' => 'pi_test_123',
        ]);

        // Fire event
        event(new PaymentSucceeded($paymentEntry, $this->rentEntry, $this->tenant));

        // Verify notification created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant->id,
            'type' => NotificationType::PAYMENT_SUCCEEDED->value,
        ]);

        // Verify notification content
        $notification = Notification::where('user_id', $this->tenant->id)->first();
        $this->assertEquals('Payment Received', $notification->title);
        $this->assertStringContainsString('Payment of $2,500.00 received', $notification->message);
        $this->assertStringContainsString('Thank you', $notification->message);
    }

    public function test_notification_created_when_payment_fails()
    {
        // Create rent entry
        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 250000,
        ]);

        // Fire event
        event(new PaymentFailed(
            'pi_test_456',
            $this->rentEntry,
            $this->tenant,
            'Insufficient funds'
        ));

        // Verify notification created
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant->id,
            'type' => NotificationType::PAYMENT_FAILED->value,
        ]);

        // Verify notification content
        $notification = Notification::where('user_id', $this->tenant->id)->first();
        $this->assertEquals('Payment Failed', $notification->title);
        $this->assertStringContainsString('Payment of $2,500.00 failed', $notification->message);
        $this->assertStringContainsString('Insufficient funds', $notification->message);
    }

    public function test_notifications_belong_only_to_intended_user()
    {
        // Create another tenant
        $otherTenant = User::factory()->tenant()->create();

        // Create rent entry for original tenant
        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // Fire event
        event(new RentGenerated($this->rentEntry, $this->tenant));

        // Verify original tenant has notification
        $this->assertEquals(1, Notification::where('user_id', $this->tenant->id)->count());

        // Verify other tenant has NO notification
        $this->assertEquals(0, Notification::where('user_id', $otherTenant->id)->count());

        // Verify other tenant cannot access via API
        $notification = Notification::where('user_id', $this->tenant->id)->first();
        $response = $this->actingAs($otherTenant, 'sanctum')
            ->patchJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(403); // Forbidden
    }

    public function test_marking_notification_as_read_works()
    {
        // Create rent entry
        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // Fire event to create notification
        event(new RentGenerated($this->rentEntry, $this->tenant));

        $notification = Notification::where('user_id', $this->tenant->id)->first();
        $this->assertNull($notification->read_at);
        $this->assertTrue($notification->isUnread());

        // Mark as read
        $this->notificationService->markAsRead($notification);

        // Verify read_at set
        $notification->refresh();
        $this->assertNotNull($notification->read_at);
        $this->assertTrue($notification->isRead());
    }

    public function test_no_duplicate_notifications_for_same_event()
    {
        // Create rent entry
        $this->rentEntry = LedgerEntry::factory()->rent()->pending()->create([
            'contract_id' => $this->contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // Fire event twice
        event(new RentGenerated($this->rentEntry, $this->tenant));
        event(new RentGenerated($this->rentEntry, $this->tenant));

        // Verify only 1 notification created
        $count = Notification::where('user_id', $this->tenant->id)
            ->where('type', NotificationType::RENT_GENERATED)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_user_can_retrieve_their_notifications()
    {
        // Create multiple notifications
        for ($i = 0; $i < 3; $i++) {
            $entry = LedgerEntry::factory()->rent()->pending()->create([
                'contract_id' => $this->contract->id,
                'tenant_id' => $this->tenant->id,
                'landlord_id' => $this->landlord->id,
            ]);
            event(new RentGenerated($entry, $this->tenant));
        }

        // Retrieve via API
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_user_can_get_unread_count()
    {
        // Create 5 notifications, mark 2 as read
        for ($i = 0; $i < 5; $i++) {
            $entry = LedgerEntry::factory()->rent()->pending()->create([
                'contract_id' => $this->contract->id,
                'tenant_id' => $this->tenant->id,
                'landlord_id' => $this->landlord->id,
            ]);
            event(new RentGenerated($entry, $this->tenant));
        }

        // Mark 2 as read
        $notifications = Notification::where('user_id', $this->tenant->id)->take(2)->get();
        foreach ($notifications as $notification) {
            $this->notificationService->markAsRead($notification);
        }

        // Get unread count via API
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/notifications/unread-count');

        $response->assertStatus(200)
            ->assertJson(['unread_count' => 3]);
    }

    public function test_user_can_mark_all_notifications_as_read()
    {
        // Create 5 notifications
        for ($i = 0; $i < 5; $i++) {
            $entry = LedgerEntry::factory()->rent()->pending()->create([
                'contract_id' => $this->contract->id,
                'tenant_id' => $this->tenant->id,
                'landlord_id' => $this->landlord->id,
            ]);
            event(new RentGenerated($entry, $this->tenant));
        }

        // Verify all unread
        $this->assertEquals(5, Notification::where('user_id', $this->tenant->id)->unread()->count());

        // Mark all as read via API
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson('/api/notifications/mark-all-read');

        $response->assertStatus(200)
            ->assertJson(['count' => 5]);

        // Verify all read
        $this->assertEquals(0, Notification::where('user_id', $this->tenant->id)->unread()->count());
    }
}
