<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuditEventPresenterTest
 *
 * Proves the admin Audit Log detail endpoint returns a truthful, resolved
 * "case file" (event_title, plain_summary, key_facts, related_records,
 * financial_context) built from real related records — never fabricated —
 * and degrades honestly when a record can't be resolved.
 */
class AuditEventPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
        $this->actingAs($this->admin, 'admin');
    }

    private function show(AuditLog $log): array
    {
        $response = $this->getJson("/api/admin/audit-logs/{$log->id}");
        $response->assertStatus(200);

        return $response->json();
    }

    // -------------------------------------------------------------------------
    // Rent entry created — the flagship "case file" scenario
    // -------------------------------------------------------------------------

    public function test_rent_entry_created_resolves_human_readable_case_file(): void
    {
        $contract = Contract::factory()->active()->create(['rent_amount' => 250000]);
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => 250000,
            'status' => LedgerStatus::PENDING,
        ]);
        $log = AuditLog::factory()->create([
            'action' => 'rent_entry_created',
            'actor_type' => null,
            'actor_id' => null,
            'subject_type' => LedgerEntry::class,
            'subject_id' => $entry->id,
            'description' => "Rent entry created for contract {$contract->id}: Jun 2026",
            'severity' => 'info',
        ]);

        $data = $this->show($log);

        $this->assertSame('Monthly Rent Generated', $data['event_title']);
        $this->assertStringNotContainsString($contract->id, $data['plain_summary'], 'Plain summary must not leak a raw UUID.');
        $this->assertSame('Ledger', $data['classification']['category']);
        $this->assertSame('Financial record', $data['classification']['sensitivity']);
        $this->assertSame('Routine', $data['classification']['label']);

        $factLabels = array_column($data['key_facts'], 'label');
        $this->assertContains('Amount', $factLabels);
        $this->assertContains('Period', $factLabels);
        $this->assertContains('Tenant', $factLabels);
        $this->assertContains('Landlord', $factLabels);

        $recordTypes = array_column($data['related_records'], 'type');
        $this->assertContains('Contract', $recordTypes);
        $this->assertContains('Tenant', $recordTypes);
        $this->assertContains('Landlord', $recordTypes);
        $this->assertContains('Ledger entry', $recordTypes);

        $contractRecord = collect($data['related_records'])->firstWhere('type', 'Contract');
        $this->assertSame("/app/contracts/{$contract->id}", $contractRecord['href']);
        $this->assertStringNotContainsString($contract->id, $contractRecord['label'], 'Contract card label must be a name, not a UUID.');

        $this->assertNotNull($data['created_record_summary']);
        $this->assertNotNull($data['financial_context']);
        $this->assertSame(250000, $data['financial_context']['display_amount_cents']);
        $this->assertSame(250000, $data['financial_context']['balance_impact_cents']);
        $this->assertSame('charge', $data['financial_context']['direction']);

        $recommendedTo = array_column($data['recommended_steps'], 'to');
        $this->assertContains("/app/contracts/{$contract->id}", $recommendedTo);

        // Raw contract still nowhere disguised as the story — it's only in the technical/raw fields.
        $this->assertSame($entry->id, $data['subject']['id']);
    }

    // -------------------------------------------------------------------------
    // Payments — sign convention must be honest, never a confusing raw negative
    // -------------------------------------------------------------------------

    public function test_payment_recorded_shows_positive_amount_and_negative_balance_impact(): void
    {
        $contract = Contract::factory()->active()->create();
        $payment = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => -250000,
            'status' => LedgerStatus::PAID,
        ]);
        $log = AuditLog::factory()->create([
            'action' => 'payment_recorded',
            'actor_type' => null,
            'actor_id' => null,
            'subject_type' => LedgerEntry::class,
            'subject_id' => $payment->id,
        ]);

        $data = $this->show($log);

        $this->assertSame('Payment Received', $data['event_title']);
        $this->assertSame(250000, $data['financial_context']['display_amount_cents'], 'Display amount must always be positive.');
        $this->assertSame(-250000, $data['financial_context']['balance_impact_cents'], 'Balance impact must be explicitly signed.');
        $this->assertSame('payment', $data['financial_context']['direction']);
    }

    public function test_payment_failed_states_tenant_may_still_owe(): void
    {
        $contract = Contract::factory()->active()->create();
        $entry = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PENDING,
        ]);
        $log = AuditLog::factory()->create([
            'action' => 'payment_failed',
            'actor_type' => null,
            'actor_id' => null,
            'subject_type' => LedgerEntry::class,
            'subject_id' => $entry->id,
            'severity' => 'warning',
        ]);

        $data = $this->show($log);

        $this->assertSame('Payment Failed', $data['event_title']);
        $this->assertStringContainsString('still owe', $data['plain_summary']);
    }

    // -------------------------------------------------------------------------
    // Listing moderation
    // -------------------------------------------------------------------------

    public function test_listing_rejected_links_to_the_specific_listing_and_shows_reason(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::REJECTED, 'title' => 'Sunny 2BR Loft']);
        $log = AuditLog::factory()->create([
            'action' => 'listing_rejected',
            'subject_type' => Listing::class,
            'subject_id' => $listing->id,
            'metadata' => ['reason' => 'Photos do not match the address on file.'],
        ]);

        $data = $this->show($log);

        $this->assertSame('Listing Rejected', $data['event_title']);
        $listingRecord = collect($data['related_records'])->firstWhere('type', 'Listing');
        $this->assertSame("/app/listing-review/{$listing->id}", $listingRecord['href']);

        $recommendedTo = array_column($data['recommended_steps'], 'to');
        $this->assertContains("/app/listing-review/{$listing->id}", $recommendedTo);

        $reasonFact = collect($data['key_facts'])->firstWhere('label', 'Reason');
        $this->assertSame('Photos do not match the address on file.', $reasonFact['value']);
    }

    // -------------------------------------------------------------------------
    // Contract lifecycle — classification escalation
    // -------------------------------------------------------------------------

    public function test_contract_terminated_is_classified_important_even_at_info_severity(): void
    {
        $contract = Contract::factory()->terminated()->create();
        $log = AuditLog::factory()->create([
            'action' => 'contract_terminated',
            'subject_type' => Contract::class,
            'subject_id' => $contract->id,
            'severity' => 'warning',
        ]);

        $data = $this->show($log);

        $this->assertSame('Contract Terminated', $data['event_title']);
        $this->assertSame('Important', $data['classification']['label']);

        $recommendedTo = array_column($data['recommended_steps'], 'to');
        $this->assertContains("/app/contracts/{$contract->id}", $recommendedTo);
    }

    // -------------------------------------------------------------------------
    // Identity verification — must NOT invent the reviewing admin
    // -------------------------------------------------------------------------

    public function test_identity_verified_does_not_fabricate_a_reviewing_admin(): void
    {
        $user = User::factory()->landlord()->create();
        $log = AuditLog::factory()->create([
            'action' => 'identity_verified',
            'actor_type' => User::class,
            'actor_id' => $user->id,
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);

        $data = $this->show($log);

        $this->assertSame('Identity Verified', $data['event_title']);
        $this->assertStringContainsString('not captured', $data['data_gap_note']);
        $this->assertStringNotContainsString('approved by', strtolower($data['plain_summary']));
    }

    public function test_verification_approved_resolves_applicant_from_user_subject(): void
    {
        $user = User::factory()->tenant()->create();
        $log = AuditLog::factory()->create([
            'action' => 'verification_approved',
            'actor_type' => Admin::class,
            'actor_id' => $this->admin->id,
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);

        $data = $this->show($log);

        $applicantFact = collect($data['key_facts'])->firstWhere('label', 'Applicant');
        $this->assertSame($user->full_name, $applicantFact['value']);
        // No linked VerificationRequest was created for this event — honest gap note.
        $this->assertNotNull($data['data_gap_note']);
    }

    public function test_verification_submitted_links_to_the_specific_case(): void
    {
        $user = User::factory()->tenant()->create();
        $request = VerificationRequest::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        $log = AuditLog::factory()->create([
            'action' => 'verification_submitted',
            'actor_type' => User::class,
            'actor_id' => $user->id,
            'subject_type' => VerificationRequest::class,
            'subject_id' => $request->id,
        ]);

        $data = $this->show($log);

        $recordTypes = array_column($data['related_records'], 'type');
        $this->assertContains('Verification request', $recordTypes);
        $recommendedTo = array_column($data['recommended_steps'], 'to');
        $this->assertContains("/app/verifications/{$request->id}", $recommendedTo);
        $this->assertNull($data['data_gap_note']);
    }

    // -------------------------------------------------------------------------
    // Fallback behaviour — unknown actions and missing related records
    // -------------------------------------------------------------------------

    public function test_unknown_action_falls_back_to_title_cased_label_without_crashing(): void
    {
        $log = AuditLog::factory()->create(['action' => 'totally_unmapped_thing', 'subject_type' => null, 'subject_id' => null, 'severity' => 'info']);

        $data = $this->show($log);

        $this->assertSame('Totally Unmapped Thing', $data['event_title']);
        $this->assertSame('Routine', $data['classification']['label']);
        $this->assertNotEmpty($data['data_gap_note']);
        $this->assertSame([], $data['related_records']);
    }

    public function test_missing_related_record_shows_honest_fallback_without_crashing(): void
    {
        $log = AuditLog::factory()->create([
            'action' => 'unit_updated',
            'subject_type' => \App\Models\Unit::class,
            'subject_id' => 999999,
        ]);

        $data = $this->show($log);

        $this->assertSame('Unit Updated', $data['event_title']);
        $record = $data['related_records'][0];
        $this->assertSame('Record no longer exists', $record['label']);
    }

    public function test_deleted_ledger_entry_subject_does_not_crash_the_presenter(): void
    {
        $log = AuditLog::factory()->create([
            'action' => 'rent_entry_created',
            'actor_type' => null,
            'actor_id' => null,
            'subject_type' => LedgerEntry::class,
            'subject_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $data = $this->show($log);

        $this->assertSame('Monthly Rent Generated', $data['event_title']);
        $this->assertSame('Record no longer exists', $data['related_records'][0]['label']);
        $this->assertNull($data['financial_context']);
    }

    // -------------------------------------------------------------------------
    // Field changes still verbatim (existing contract preserved)
    // -------------------------------------------------------------------------

    public function test_admin_capabilities_updated_still_exposes_raw_old_and_new_values(): void
    {
        $target = Admin::factory()->create();
        $log = AuditLog::factory()->create([
            'action' => 'admin_capabilities_updated',
            'actor_type' => Admin::class,
            'actor_id' => $this->admin->id,
            'subject_type' => Admin::class,
            'subject_id' => $target->id,
            'old_values' => ['capabilities' => ['view_audit']],
            'new_values' => ['capabilities' => ['view_audit', 'moderate_listings']],
            'metadata' => ['reason' => 'Expanded to cover listing review coverage.'],
        ]);

        $data = $this->show($log);

        $this->assertSame(['capabilities' => ['view_audit']], $data['old_values']);
        $this->assertSame(['capabilities' => ['view_audit', 'moderate_listings']], $data['new_values']);
        $this->assertSame('Security sensitive', $data['classification']['sensitivity']);
        $reasonFact = collect($data['key_facts'])->firstWhere('label', 'Reason');
        $this->assertSame('Expanded to cover listing review coverage.', $reasonFact['value']);
    }
}
