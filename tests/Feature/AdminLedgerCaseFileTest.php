<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AdminLedgerCaseFileTest
 *
 * Covers the real backend behind the rebuilt admin Ledger page: the
 * single-entry case file (audit trail / linked entries / notifications),
 * waiving an obligation, CSV export, and platform search — every one of
 * these traces to real data, never a fabricated dispute/payout concept.
 */
class AdminLedgerCaseFileTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected Admin $scopedAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
        $this->scopedAdmin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
    }

    public function test_show_includes_audit_trail_linked_entries_and_notifications(): void
    {
        $contract = Contract::factory()->active()->create();

        $rent = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => 70_000,
        ]);

        $payment = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::PAYMENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => -70_000,
            'related_rent_entry_id' => $rent->id,
        ]);

        AuditLog::factory()->create([
            'actor_type' => null,
            'actor_id' => null,
            'subject_type' => LedgerEntry::class,
            'subject_id' => $rent->id,
            'action' => 'rent_entry_created',
            'description' => 'Rent entry created',
            'severity' => 'info',
        ]);

        Notification::factory()->create([
            'user_id' => $contract->tenant_id,
            'type' => NotificationType::PAYMENT_SUCCEEDED,
            'title' => 'Payment Received',
            'message' => 'Payment of GH₵ 700.00 received! Thank you.',
            'data' => ['payment_entry_id' => $payment->id, 'rent_entry_id' => $rent->id],
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson("/api/admin/ledger/{$rent->id}");
        $response->assertStatus(200);

        $this->assertCount(1, $response->json('audit_trail'));
        $this->assertSame('rent_entry_created', $response->json('audit_trail.0.action'));

        $linked = collect($response->json('linked_entries'));
        $this->assertTrue($linked->pluck('id')->contains($payment->id));

        $paymentResponse = $this->getJson("/api/admin/ledger/{$payment->id}");
        $paymentNotifications = collect($paymentResponse->json('notifications'));
        $this->assertTrue($paymentNotifications->isNotEmpty());
        $paymentLinked = collect($paymentResponse->json('linked_entries'));
        $this->assertTrue($paymentLinked->pluck('id')->contains($rent->id));
    }

    public function test_admin_can_waive_a_pending_entry_with_reason(): void
    {
        $contract = Contract::factory()->active()->create();
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::LATE_FEE,
            'status' => LedgerStatus::OVERDUE,
            'amount_cents' => 25_00,
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->postJson("/api/admin/ledger/{$entry->id}/waive", [
            'reason' => 'Tenant provided proof of on-time bank transfer.',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'waived');

        $this->assertDatabaseHas('ledger_entries', [
            'id' => $entry->id,
            'status' => 'waived',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => LedgerEntry::class,
            'subject_id' => $entry->id,
            'action' => 'entry_waived',
        ]);
    }

    public function test_waive_requires_a_reason(): void
    {
        $contract = Contract::factory()->active()->create();
        $entry = LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
        ]);

        $this->actingAs($this->admin, 'admin');

        $this->postJson("/api/admin/ledger/{$entry->id}/waive", [])
            ->assertStatus(422);
    }

    public function test_waive_rejects_a_paid_entry(): void
    {
        $contract = Contract::factory()->active()->create();
        $entry = LedgerEntry::factory()->paid()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
        ]);

        $this->actingAs($this->admin, 'admin');

        $this->postJson("/api/admin/ledger/{$entry->id}/waive", ['reason' => 'Testing'])
            ->assertStatus(422);
    }

    public function test_scoped_admin_without_manage_ledger_cannot_waive(): void
    {
        $contract = Contract::factory()->active()->create();
        $entry = LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
        ]);

        $this->actingAs($this->scopedAdmin, 'admin');

        $this->postJson("/api/admin/ledger/{$entry->id}/waive", ['reason' => 'Testing'])
            ->assertStatus(403);
    }

    /**
     * Regression: the SPA sends boolean query flags via axios, which
     * serializes JS `true` as the literal query string "overdue_only=true".
     * Laravel's `boolean` validation rule only natively accepts
     * true/false/1/0/"1"/"0" and would 422 on the string "true" — this is
     * exactly the request the browser makes when clicking the Overdue tab.
     */
    public function test_overdue_only_flag_accepts_the_literal_string_true_from_query_params(): void
    {
        $contract = Contract::factory()->active()->create();
        LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => 50_000,
        ]);
        LedgerEntry::factory()->paid()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => 70_000,
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/ledger?overdue_only=true');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('overdue', $response->json('data.0.status'));
    }

    public function test_charges_only_filters_to_rent_and_late_fee(): void
    {
        $contract = Contract::factory()->active()->create();
        LedgerEntry::factory()->paid()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => 60_000,
        ]);
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::PAYMENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => -60_000,
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/ledger?charges_only=true');

        $response->assertStatus(200);
        $rows = collect($response->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('rent', $rows->first()['type']);
    }

    public function test_export_streams_csv_and_is_audit_logged(): void
    {
        $contract = Contract::factory()->active()->create();
        LedgerEntry::factory()->paid()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => 70_000,
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->get('/api/admin/ledger/export');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ledger_exported',
        ]);
    }

    public function test_search_filters_the_list_and_summary_consistently(): void
    {
        $contractA = Contract::factory()->active()->create();
        $contractA->tenant->update(['first_name' => 'Ama', 'last_name' => 'Boateng']);

        $contractB = Contract::factory()->active()->create();
        $contractB->tenant->update(['first_name' => 'Kojo', 'last_name' => 'Mensah']);

        LedgerEntry::factory()->paid()->create([
            'contract_id' => $contractA->id,
            'tenant_id' => $contractA->tenant_id,
            'landlord_id' => $contractA->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => 60_000,
        ]);
        LedgerEntry::factory()->paid()->create([
            'contract_id' => $contractB->id,
            'tenant_id' => $contractB->tenant_id,
            'landlord_id' => $contractB->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => 90_000,
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/ledger?search=Boateng');

        $response->assertStatus(200);
        $rows = collect($response->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame(60_000, $response->json('summary.rent_charged_cents'));
    }
}
