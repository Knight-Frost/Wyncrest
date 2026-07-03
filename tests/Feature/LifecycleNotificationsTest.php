<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Enums\NotificationType;
use App\Events\ListingPublished;
use App\Events\ListingRejected;
use App\Events\UserCreated;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\EmailLog;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LifecycleNotificationsTest
 *
 * Covers Parts A, B, D, E of the spec:
 *
 * Part A: Event wiring & registration, UserCreated dispatch on register.
 * Part B: Contract signed/terminated, account suspended/reactivated, late fee notifications.
 * Part D: Authorization negative tests (contract terminate cross-owner, payment IDOR).
 * Part E: Admin contract/ledger bigint filter regression.
 */
class LifecycleNotificationsTest extends TestCase
{
    use RefreshDatabase;

    // ======================================================================
    // Helpers
    // ======================================================================

    private function makeContractSetup(): array
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();
        $admin = Admin::factory()->create(['is_super_admin' => true]);

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        $contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        return compact('landlord', 'tenant', 'admin', 'listing', 'contract');
    }

    private function makeOverdueLedgerEntry(Contract $contract): LedgerEntry
    {
        return LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'amount_cents' => 100000,
            'due_date' => Carbon::now()->subDays(5),
            'billing_period_start' => Carbon::now()->subMonth(),
            'billing_period_end' => Carbon::now()->subDay(),
        ]);
    }

    // ======================================================================
    // Part A — Event wiring: ListingPublished creates in-app notification
    // ======================================================================

    public function test_listing_approved_creates_in_app_notification_for_landlord(): void
    {
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        // Fire the event
        event(new ListingPublished($listing));

        // In-app notification created for landlord
        $this->assertDatabaseHas('notifications', [
            'user_id' => $landlord->id,
            'type' => NotificationType::LISTING_APPROVED->value,
        ]);

        $notification = Notification::where('user_id', $landlord->id)
            ->where('type', NotificationType::LISTING_APPROVED->value)
            ->first();

        $this->assertEquals('Listing Approved', $notification->title);
        $this->assertStringContainsString($listing->title, $notification->message);

        // Email log also written
        $this->assertDatabaseHas('email_logs', [
            'recipient_email' => $landlord->email,
            'mailable_class' => 'ListingPublishedNotification',
        ]);
    }

    public function test_listing_approved_notification_is_idempotent(): void
    {
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        // Fire twice
        event(new ListingPublished($listing));
        event(new ListingPublished($listing));

        // Only one in-app notification
        $this->assertEquals(
            1,
            Notification::where('user_id', $landlord->id)
                ->where('type', NotificationType::LISTING_APPROVED->value)
                ->count()
        );
    }

    public function test_listing_rejected_creates_in_app_notification_with_reason(): void
    {
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'status' => ListingStatus::PENDING_REVIEW,
        ]);

        $reason = 'Photos are missing. Please upload at least two photos.';

        event(new ListingRejected($listing, $reason));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $landlord->id,
            'type' => NotificationType::LISTING_REJECTED->value,
        ]);

        $notification = Notification::where('user_id', $landlord->id)
            ->where('type', NotificationType::LISTING_REJECTED->value)
            ->first();

        $this->assertEquals('Listing Needs Changes', $notification->title);
        $this->assertStringContainsString($reason, $notification->message);

        // Email log written
        $this->assertDatabaseHas('email_logs', [
            'recipient_email' => $landlord->email,
            'mailable_class' => 'ListingRejectedNotification',
        ]);
    }

    // ======================================================================
    // Part A — UserCreated fired on registration (triggers EmailLog)
    // ======================================================================

    public function test_user_created_event_fires_on_registration_and_creates_email_log(): void
    {
        $response = $this->postJson('/api/register', [
            'email' => 'newuser@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'first_name' => 'Test',
            'last_name' => 'User',
            'user_type' => 'tenant',
        ]);

        $response->assertStatus(201);

        // SendWelcomeEmail listener creates an EmailLog row
        $this->assertDatabaseHas('email_logs', [
            'recipient_email' => 'newuser@example.com',
            'mailable_class' => 'WelcomeEmail',
            'status' => 'sent',
        ]);
    }

    public function test_audit_log_created_on_user_registration(): void
    {
        $this->postJson('/api/register', [
            'email' => 'audituser@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'first_name' => 'Audit',
            'last_name' => 'User',
            'user_type' => 'landlord',
        ])->assertStatus(201);

        // LogUserCreated listener writes an audit log
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_created',
        ]);
    }

    // ======================================================================
    // Part B — Contract signed: landlord notified when tenant accepts
    // ======================================================================

    public function test_landlord_notified_when_tenant_accepts_contract(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        $contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'status' => ContractStatus::PENDING_TENANT,
        ]);

        Sanctum::actingAs($tenant, [], 'sanctum');

        $response = $this->postJson("/api/tenant/contracts/{$contract->id}/accept");

        $response->assertStatus(200);

        // Landlord receives CONTRACT_SIGNED notification
        $this->assertDatabaseHas('notifications', [
            'user_id' => $landlord->id,
            'type' => NotificationType::CONTRACT_SIGNED->value,
        ]);

        $notification = Notification::where('user_id', $landlord->id)
            ->where('type', NotificationType::CONTRACT_SIGNED->value)
            ->first();

        $this->assertStringContainsString($tenant->full_name, $notification->message);
    }

    // ======================================================================
    // Part B — Contract terminated: other party notified
    // ======================================================================

    public function test_landlord_notified_when_tenant_terminates(): void
    {
        ['landlord' => $landlord, 'tenant' => $tenant, 'contract' => $contract] = $this->makeContractSetup();

        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->postJson("/api/tenant/contracts/{$contract->id}/terminate", [
            'reason' => 'I am moving to another city for work.',
        ])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $landlord->id,
            'type' => NotificationType::CONTRACT_TERMINATED->value,
        ]);

        $notification = Notification::where('user_id', $landlord->id)
            ->where('type', NotificationType::CONTRACT_TERMINATED->value)
            ->first();

        $this->assertStringContainsString($tenant->full_name, $notification->message);
        $this->assertStringContainsString('I am moving to another city for work.', $notification->message);
    }

    public function test_tenant_notified_when_landlord_terminates(): void
    {
        ['landlord' => $landlord, 'tenant' => $tenant, 'contract' => $contract] = $this->makeContractSetup();

        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->postJson("/api/landlord/contracts/{$contract->id}/terminate", [
            'reason' => 'Lease breach: repeated late payments.',
        ])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => NotificationType::CONTRACT_TERMINATED->value,
        ]);

        $notification = Notification::where('user_id', $tenant->id)
            ->where('type', NotificationType::CONTRACT_TERMINATED->value)
            ->first();

        $this->assertStringContainsString($landlord->full_name, $notification->message);
        $this->assertStringContainsString('Lease breach: repeated late payments.', $notification->message);
    }

    public function test_both_parties_notified_when_admin_force_terminates(): void
    {
        ['landlord' => $landlord, 'tenant' => $tenant, 'admin' => $admin, 'contract' => $contract] = $this->makeContractSetup();

        $this->actingAs($admin, 'admin');

        $this->postJson("/api/admin/contracts/{$contract->id}/terminate", [
            'reason' => 'Fraud investigation.',
        ])->assertStatus(200);

        // Tenant notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => NotificationType::CONTRACT_TERMINATED->value,
        ]);

        // Landlord notified
        $this->assertDatabaseHas('notifications', [
            'user_id' => $landlord->id,
            'type' => NotificationType::CONTRACT_TERMINATED->value,
        ]);

        // Both messages mention the reason
        $tenantNotif = Notification::where('user_id', $tenant->id)
            ->where('type', NotificationType::CONTRACT_TERMINATED->value)->first();
        $this->assertStringContainsString('Fraud investigation.', $tenantNotif->message);

        $landlordNotif = Notification::where('user_id', $landlord->id)
            ->where('type', NotificationType::CONTRACT_TERMINATED->value)->first();
        $this->assertStringContainsString('Fraud investigation.', $landlordNotif->message);
    }

    // ======================================================================
    // Part B — Account suspended / reactivated
    // ======================================================================

    public function test_user_notified_when_account_suspended(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->tenant()->create();

        $this->actingAs($admin, 'admin');

        $this->postJson("/api/admin/users/{$user->id}/suspend", [
            'reason' => 'Violation of platform terms.',
        ])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => NotificationType::ACCOUNT_SUSPENDED->value,
        ]);

        $notification = Notification::where('user_id', $user->id)
            ->where('type', NotificationType::ACCOUNT_SUSPENDED->value)
            ->first();

        $this->assertStringContainsString('Violation of platform terms.', $notification->message);
    }

    public function test_user_notified_when_account_reactivated(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->tenant()->create([
            'suspended_at' => now(),
            'is_active' => false,
        ]);

        $this->actingAs($admin, 'admin');

        $this->postJson("/api/admin/users/{$user->id}/activate")
            ->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => NotificationType::ACCOUNT_REACTIVATED->value,
        ]);
    }

    // ======================================================================
    // Part B — Late fee notification
    // ======================================================================

    public function test_tenant_notified_when_late_fee_added(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        ['landlord' => $landlord, 'tenant' => $tenant, 'contract' => $contract] = $this->makeContractSetup();

        $overduEntry = $this->makeOverdueLedgerEntry($contract);

        $this->actingAs($admin, 'admin');

        $response = $this->postJson("/api/admin/ledger/{$overduEntry->id}/late-fee", [
            'amount_cents' => 5000,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tenant->id,
            'type' => NotificationType::LATE_FEE_ADDED->value,
        ]);

        $notification = Notification::where('user_id', $tenant->id)
            ->where('type', NotificationType::LATE_FEE_ADDED->value)
            ->first();

        $this->assertStringContainsString('GH₵', $notification->message);
        $this->assertStringContainsString('50.00', $notification->message);
    }

    // ======================================================================
    // Part D — Authorization negatives
    // ======================================================================

    public function test_tenant_cannot_terminate_another_tenants_contract(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant1 = User::factory()->tenant()->create();
        $tenant2 = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        // Contract belongs to tenant1
        $contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant1->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        // tenant2 tries to terminate tenant1's contract
        Sanctum::actingAs($tenant2, [], 'sanctum');

        $response = $this->postJson("/api/tenant/contracts/{$contract->id}/terminate", [
            'reason' => 'Attempting unauthorized termination.',
        ]);

        $response->assertStatus(403);
    }

    public function test_tenant_cannot_initiate_payment_on_another_tenants_ledger_entry(): void
    {
        $landlord = User::factory()->landlord()->create();
        $tenant1 = User::factory()->tenant()->create();
        $tenant2 = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        $contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant1->id,
        ]);

        // Ledger entry belongs to tenant1
        $ledgerEntry = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $tenant1->id,
            'landlord_id' => $landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PENDING,
            'due_date' => now()->addDays(5),
            'billing_period_start' => now()->subDays(10),
            'billing_period_end' => now()->addDays(20),
        ]);

        // tenant2 tries to pay for tenant1's rent
        Sanctum::actingAs($tenant2, [], 'sanctum');

        $response = $this->postJson("/api/tenant/payments/initiate/{$ledgerEntry->id}");

        $response->assertStatus(403);
    }

    // ======================================================================
    // Part E — Admin contract/ledger bigint filter regression
    // ======================================================================

    public function test_admin_contracts_filter_by_landlord_id_integer_matches(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->getJson("/api/admin/contracts?landlord_id={$landlord->id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_admin_contracts_filter_by_tenant_id_integer_matches(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        Contract::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->getJson("/api/admin/contracts?tenant_id={$tenant->id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_admin_ledger_filter_by_landlord_id_integer_matches(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);
        $contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PENDING,
            'due_date' => now()->addDays(5),
            'billing_period_start' => now()->subDays(10),
            'billing_period_end' => now()->addDays(20),
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->getJson("/api/admin/ledger?landlord_id={$landlord->id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_admin_ledger_filter_by_tenant_id_integer_matches(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);
        $contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 100000,
            'status' => LedgerStatus::PENDING,
            'due_date' => now()->addDays(5),
            'billing_period_start' => now()->subDays(10),
            'billing_period_end' => now()->addDays(20),
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->getJson("/api/admin/ledger?tenant_id={$tenant->id}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }
}
