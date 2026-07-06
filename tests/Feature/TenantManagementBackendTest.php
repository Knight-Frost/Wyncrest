<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\PaymentMethod;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TenantManagementBackendTest
 *
 * Covers the new backend surface added to support the landlord Tenant
 * Management rebuild: in-place contract renewal (+ history row + tenant
 * notification), landlord-recorded manual/offline ledger payments,
 * contract-scoped messaging, and landlord-authored contract notes.
 */
class TenantManagementBackendTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $this->listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);
    }

    // =========================================================================
    // Renew
    // =========================================================================

    public function test_landlord_can_renew_own_active_contract(): void
    {
        $contract = Contract::factory()->active()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'rent_amount' => 200000,
            'end_date' => now()->addMonths(2),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $newEndDate = now()->addYear()->format('Y-m-d');

        $response = $this->postJson("/api/landlord/contracts/{$contract->id}/renew", [
            'new_end_date' => $newEndDate,
            'new_rent_amount' => 220000,
            'note' => 'Renewed for another year at a slight increase.',
        ]);

        $response->assertStatus(200)->assertJson(['message' => 'Lease renewed']);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'rent_amount' => 220000,
        ]);
        $contract->refresh();
        $this->assertSame($newEndDate, $contract->end_date->format('Y-m-d'));

        $this->assertDatabaseHas('contract_renewals', [
            'contract_id' => $contract->id,
            'landlord_id' => $this->landlord->id,
            'previous_rent_amount' => 200000,
            'new_rent_amount' => 220000,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant->id,
            'type' => 'contract_renewed',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'contract_renewed',
            'subject_type' => Contract::class,
            'subject_id' => $contract->id,
        ]);
    }

    public function test_landlord_can_renew_open_ended_contract_with_no_prior_end_date(): void
    {
        $contract = Contract::factory()->active()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'rent_amount' => 200000,
            'end_date' => null,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $newEndDate = now()->addYear()->format('Y-m-d');

        $response = $this->postJson("/api/landlord/contracts/{$contract->id}/renew", [
            'new_end_date' => $newEndDate,
        ]);

        $response->assertStatus(200)->assertJson(['message' => 'Lease renewed']);

        $contract->refresh();
        $this->assertSame($newEndDate, $contract->end_date->format('Y-m-d'));
    }

    public function test_tenant_cannot_renew_contract(): void
    {
        $contract = Contract::factory()->active()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $this->postJson("/api/landlord/contracts/{$contract->id}/renew", [
            'new_end_date' => now()->addYear()->format('Y-m-d'),
        ])->assertStatus(403);
    }

    public function test_other_landlord_cannot_renew_contract(): void
    {
        $otherLandlord = User::factory()->landlord()->create();

        $contract = Contract::factory()->active()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
        ]);

        Sanctum::actingAs($otherLandlord, [], 'sanctum');

        $this->postJson("/api/landlord/contracts/{$contract->id}/renew", [
            'new_end_date' => now()->addYear()->format('Y-m-d'),
        ])->assertStatus(403);
    }

    public function test_non_active_contract_cannot_be_renewed(): void
    {
        $contract = Contract::factory()->draft()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $this->postJson("/api/landlord/contracts/{$contract->id}/renew", [
            'new_end_date' => now()->addYear()->format('Y-m-d'),
        ])->assertStatus(403);
    }

    // =========================================================================
    // Record manual payment
    // =========================================================================

    private function makeContract(array $overrides = []): Contract
    {
        return Contract::factory()->active()->create(array_merge([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
        ], $overrides));
    }

    public function test_landlord_can_record_manual_payment_against_own_overdue_entry(): void
    {
        $contract = $this->makeContract();

        $entry = LedgerEntry::factory()->rent()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 150000,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->postJson("/api/landlord/ledger/{$entry->id}/record-payment", [
            'method' => PaymentMethod::MOBILE_MONEY_MTN->value,
            'reference' => 'MTN-REF-123',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('ledger_entries', [
            'id' => $entry->id,
            'status' => LedgerStatus::PAID->value,
        ]);

        $this->assertDatabaseHas('ledger_entries', [
            'related_rent_entry_id' => $entry->id,
            'type' => LedgerType::PAYMENT->value,
            'amount_cents' => -150000,
            'status' => LedgerStatus::PAID->value,
            'payment_method' => PaymentMethod::MOBILE_MONEY_MTN->value,
            'payment_reference' => 'MTN-REF-123',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_recorded',
        ]);
    }

    public function test_cannot_record_payment_against_already_paid_entry(): void
    {
        $contract = $this->makeContract();

        $entry = LedgerEntry::factory()->rent()->paid()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $this->postJson("/api/landlord/ledger/{$entry->id}/record-payment", [
            'method' => PaymentMethod::CASH->value,
        ])->assertStatus(403);
    }

    public function test_cannot_record_payment_against_waived_entry(): void
    {
        $contract = $this->makeContract();

        $entry = LedgerEntry::factory()->rent()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => LedgerStatus::WAIVED,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $this->postJson("/api/landlord/ledger/{$entry->id}/record-payment", [
            'method' => PaymentMethod::CASH->value,
        ])->assertStatus(403);
    }

    public function test_other_landlord_cannot_record_payment(): void
    {
        $otherLandlord = User::factory()->landlord()->create();
        $contract = $this->makeContract();

        $entry = LedgerEntry::factory()->rent()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($otherLandlord, [], 'sanctum');

        $this->postJson("/api/landlord/ledger/{$entry->id}/record-payment", [
            'method' => PaymentMethod::CASH->value,
        ])->assertStatus(403);
    }

    public function test_tenant_cannot_record_payment(): void
    {
        $contract = $this->makeContract();

        $entry = LedgerEntry::factory()->rent()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $this->postJson("/api/landlord/ledger/{$entry->id}/record-payment", [
            'method' => PaymentMethod::CASH->value,
        ])->assertStatus(403);
    }

    // =========================================================================
    // Contract messages
    // =========================================================================

    public function test_landlord_can_send_and_fetch_contract_messages(): void
    {
        $contract = $this->makeContract();

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $empty = $this->getJson("/api/landlord/contracts/{$contract->id}/messages");
        $empty->assertStatus(200)->assertJson(['conversation_id' => null, 'messages' => []]);

        $send = $this->postJson("/api/landlord/contracts/{$contract->id}/messages", [
            'body' => 'Reminder: rent is due next week.',
        ]);
        $send->assertStatus(201);
        $this->assertCount(1, $send->json('messages'));
        $this->assertTrue($send->json('messages.0.sender.is_me'));

        $this->assertDatabaseHas('conversations', [
            'subject_type' => Contract::class,
            'subject_id' => $contract->id,
        ]);

        $fetch = $this->getJson("/api/landlord/contracts/{$contract->id}/messages");
        $fetch->assertStatus(200);
        $this->assertCount(1, $fetch->json('messages'));
        $this->assertSame('Reminder: rent is due next week.', $fetch->json('messages.0.body'));
    }

    public function test_other_landlord_cannot_message_on_contract_they_do_not_own(): void
    {
        $otherLandlord = User::factory()->landlord()->create();
        $contract = $this->makeContract();

        Sanctum::actingAs($otherLandlord, [], 'sanctum');

        $this->postJson("/api/landlord/contracts/{$contract->id}/messages", [
            'body' => 'Hello',
        ])->assertStatus(403);
    }

    // =========================================================================
    // Contract notes
    // =========================================================================

    public function test_landlord_can_add_and_list_notes_on_own_contract(): void
    {
        $contract = $this->makeContract();

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $add = $this->postJson("/api/landlord/contracts/{$contract->id}/notes", [
            'body' => 'Tenant asked about repainting the living room.',
        ]);
        $add->assertStatus(201);

        $this->assertDatabaseHas('contract_landlord_notes', [
            'contract_id' => $contract->id,
            'landlord_id' => $this->landlord->id,
            'body' => 'Tenant asked about repainting the living room.',
        ]);

        $list = $this->getJson("/api/landlord/contracts/{$contract->id}/notes");
        $list->assertStatus(200);
        $this->assertCount(1, $list->json());
    }

    public function test_other_landlord_cannot_see_notes_on_contract_they_do_not_own(): void
    {
        $otherLandlord = User::factory()->landlord()->create();
        $contract = $this->makeContract();

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $this->postJson("/api/landlord/contracts/{$contract->id}/notes", [
            'body' => 'Private note.',
        ])->assertStatus(201);

        Sanctum::actingAs($otherLandlord, [], 'sanctum');
        $this->getJson("/api/landlord/contracts/{$contract->id}/notes")->assertStatus(403);
    }
}
