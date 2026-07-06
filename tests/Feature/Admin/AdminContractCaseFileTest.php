<?php

namespace Tests\Feature\Admin;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\ContractNote;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Ledger\LedgerComputationEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the admin Contracts case-file command centre: queue with truthful
 * segment counts, full case-file detail, contract-scoped ledger/payments/
 * billing-schedule/timeline, internal notes, and authorization gating.
 *
 * Every financial assertion is checked against LedgerComputationEngine
 * output directly rather than a hand-computed number, so the test can never
 * silently diverge from the single source of financial truth.
 */
class AdminContractCaseFileTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private LedgerComputationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
        $this->engine = app(LedgerComputationEngine::class);
    }

    /**
     * Build a fully real active contract: landlord → property → unit →
     * listing → tenant → contract.
     */
    private function makeActiveContract(array $overrides = [], array $tenantOverrides = [], array $landlordOverrides = []): Contract
    {
        $landlord = User::factory()->landlord()->create(array_merge(['identity_verified' => true], $landlordOverrides));
        $tenant = User::factory()->tenant()->create(array_merge(['identity_verified' => true], $tenantOverrides));
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id, 'availability_status' => 'occupied']);
        $listing = Listing::factory()->create(['unit_id' => $unit->id, 'landlord_id' => $landlord->id, 'status' => 'inactive']);

        return Contract::factory()->active()->create(array_merge([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'start_date' => Carbon::today()->subMonths(2),
            'end_date' => Carbon::today()->addYear(),
        ], $overrides));
    }

    // ── Authorization ──────────────────────────────────────────────────────

    public function test_tenant_cannot_access_admin_contracts(): void
    {
        Sanctum::actingAs(User::factory()->tenant()->create(), [], 'sanctum');
        // A tenant bearer identity is unauthenticated on the admin session guard.
        $this->getJson('/api/admin/contracts')->assertUnauthorized();
    }

    public function test_landlord_cannot_access_admin_contracts(): void
    {
        Sanctum::actingAs(User::factory()->landlord()->create(), [], 'sanctum');
        $this->getJson('/api/admin/contracts')->assertUnauthorized();
    }

    public function test_scoped_admin_without_capability_can_still_view(): void
    {
        // Viewing contracts is a baseline admin privilege — only the write
        // actions (notes, termination) are gated behind manage_contracts.
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $this->actingAs($scoped, 'admin');
        $this->getJson('/api/admin/contracts')->assertOk();
    }

    public function test_scoped_admin_with_capability_can_access(): void
    {
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_contracts']]);
        $this->actingAs($scoped, 'admin');
        $this->getJson('/api/admin/contracts')->assertOk();
    }

    public function test_scoped_admin_without_capability_cannot_add_note(): void
    {
        $contract = $this->makeActiveContract();
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);

        $this->actingAs($scoped, 'admin');
        $this->postJson("/api/admin/contracts/{$contract->id}/notes", [
            'body' => 'Should not be allowed.',
        ])->assertForbidden();

        $this->assertDatabaseCount('contract_notes', 0);
    }

    public function test_scoped_admin_without_capability_cannot_terminate(): void
    {
        $contract = $this->makeActiveContract();
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);

        $this->actingAs($scoped, 'admin');
        $this->postJson("/api/admin/contracts/{$contract->id}/terminate", [
            'reason' => 'Should not be allowed.',
        ])->assertForbidden();

        $this->assertSame(ContractStatus::ACTIVE, $contract->fresh()->status);
    }

    public function test_scoped_admin_with_capability_can_terminate(): void
    {
        $contract = $this->makeActiveContract();
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_contracts']]);

        $this->actingAs($scoped, 'admin');
        $this->postJson("/api/admin/contracts/{$contract->id}/terminate", [
            'reason' => 'Confirmed lease violation reported by the property manager.',
        ])->assertOk();

        $this->assertSame(ContractStatus::TERMINATED, $contract->fresh()->status);
    }

    // ── Summary / queue ─────────────────────────────────────────────────────

    public function test_summary_counts_are_truthful(): void
    {
        $this->makeActiveContract(); // active, good standing
        $this->makeActiveContract(['start_date' => Carbon::today(), 'end_date' => Carbon::today()->addDays(30)]); // expiring soon
        $awaiting = $this->makeActiveContract();
        $awaiting->update(['status' => ContractStatus::PENDING_TENANT]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson('/api/admin/contracts/summary')->assertOk();

        $res->assertJsonPath('total', 3)
            ->assertJsonPath('active', 2)
            ->assertJsonPath('awaiting_signatures', 1)
            ->assertJsonPath('expiring_soon', 1);
    }

    public function test_queue_search_matches_tenant_and_property(): void
    {
        $this->makeActiveContract([], [], []);
        $target = $this->makeActiveContract();
        $target->tenant->update(['first_name' => 'Zanele', 'last_name' => 'Osei']);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson('/api/admin/contracts?search=Zanele')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($target->id, $res->json('data.0.id'));
    }

    public function test_queue_status_filter_active_includes_overdue(): void
    {
        $overdue = $this->makeActiveContract();
        LedgerEntry::factory()->create([
            'contract_id' => $overdue->id,
            'tenant_id' => $overdue->tenant_id,
            'landlord_id' => $overdue->landlord_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::OVERDUE,
            'amount_cents' => $overdue->rent_amount,
            'due_date' => Carbon::today()->subDays(10),
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson('/api/admin/contracts?status=active')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('overdue', $res->json('data.0.segment'));
    }

    // ── Detail ───────────────────────────────────────────────────────────────

    public function test_detail_returns_real_tenant_landlord_property_unit_terms(): void
    {
        $contract = $this->makeActiveContract();

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}")->assertOk();

        $res->assertJsonPath('parties.tenant.name', $contract->tenant->full_name)
            ->assertJsonPath('parties.landlord.name', $contract->landlord->full_name)
            ->assertJsonPath('property.name', $contract->listing->unit->property->name)
            ->assertJsonPath('unit.unit_number', $contract->listing->unit->unit_number)
            ->assertJsonPath('terms.rent_amount_cents', $contract->rent_amount)
            ->assertJsonPath('terms.grace_period', 'Not specified')
            ->assertJsonPath('terms.pets_policy', 'Not specified');

        $res->assertJsonStructure([
            'checklist', 'warnings', 'completeness' => ['passed', 'total', 'percent'],
            'financials', 'parties' => ['tenant', 'landlord'], 'property', 'unit', 'terms', 'renewal', 'notes',
        ]);
    }

    public function test_financial_summary_matches_ledger_engine(): void
    {
        $contract = $this->makeActiveContract();
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::OVERDUE,
            'amount_cents' => $contract->rent_amount,
            'due_date' => Carbon::today()->subDays(5),
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}")->assertOk();

        $res->assertJsonPath('financials.current_balance_cents', $this->engine->computeContractBalance($contract->id))
            ->assertJsonPath('financials.overdue_cents', $this->engine->computeOverdueByContract($contract->id))
            ->assertJsonPath('financials.overdue_cents', $contract->rent_amount)
            ->assertJsonPath('health', 'overdue');
    }

    public function test_clean_contract_has_zero_warnings(): void
    {
        // Started 2 months ago: 3 elapsed billing periods must all be
        // generated and paid for the reconciliation checklist to pass clean.
        $contract = $this->makeActiveContract();

        $cursor = Carbon::parse($contract->start_date);
        for ($i = 0; $i < 3; $i++) {
            $periodStart = $cursor->copy();
            $periodEnd = $periodStart->copy()->addMonth()->subDay();
            $rent = LedgerEntry::factory()->create([
                'contract_id' => $contract->id,
                'tenant_id' => $contract->tenant_id,
                'landlord_id' => $contract->landlord_id,
                'type' => LedgerType::RENT,
                'status' => LedgerStatus::PAID,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'due_date' => $periodStart,
                'amount_cents' => $contract->rent_amount,
            ]);
            LedgerEntry::factory()->create([
                'contract_id' => $contract->id,
                'tenant_id' => $contract->tenant_id,
                'landlord_id' => $contract->landlord_id,
                'type' => LedgerType::PAYMENT,
                'status' => LedgerStatus::PAID,
                'amount_cents' => -$contract->rent_amount,
                'related_rent_entry_id' => $rent->id,
            ]);
            $cursor->addMonth();
        }

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}")->assertOk();

        $this->assertSame([], $res->json('warnings'));
        $this->assertSame(100, $res->json('completeness.percent'));
    }

    public function test_overlapping_active_contract_warning_fires(): void
    {
        $contract = $this->makeActiveContract();
        // A second active contract on the SAME unit, overlapping dates.
        Contract::factory()->active()->create([
            'listing_id' => Listing::factory()->create([
                'unit_id' => $contract->listing->unit_id,
                'landlord_id' => $contract->landlord_id,
            ]),
            'landlord_id' => $contract->landlord_id,
            'tenant_id' => User::factory()->tenant()->create()->id,
            'start_date' => $contract->start_date,
            'end_date' => $contract->end_date,
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}")->assertOk();

        $overlap = collect($res->json('checklist'))->firstWhere('key', 'no_overlap');
        $this->assertSame('fail', $overlap['status']);
    }

    public function test_untracked_lease_term_fields_show_not_specified(): void
    {
        $contract = $this->makeActiveContract();

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}")->assertOk();

        foreach (['grace_period', 'late_fee_rule', 'utilities_responsibility', 'pets_policy', 'renewal_type', 'special_clauses'] as $field) {
            $this->assertSame('Not specified', $res->json("terms.{$field}"));
        }
    }

    // ── Ledger / payments / billing schedule ────────────────────────────────

    public function test_ledger_endpoint_returns_positive_payment_display_amounts(): void
    {
        $contract = $this->makeActiveContract();
        $rent = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => $contract->rent_amount,
        ]);
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::PAYMENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => -$contract->rent_amount,
            'related_rent_entry_id' => $rent->id,
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}/ledger")->assertOk();

        $payment = collect($res->json('entries'))->firstWhere('type', 'payment');
        $this->assertSame($contract->rent_amount, $payment['display_amount_cents']);
        $this->assertSame(-$contract->rent_amount, $payment['signed_amount_cents']);

        $paymentsRes = $this->getJson("/api/admin/contracts/{$contract->id}/payments")->assertOk();
        $this->assertSame($contract->rent_amount, $paymentsRes->json('data.0.display_amount_cents'));
    }

    public function test_billing_schedule_reflects_generated_entries(): void
    {
        $contract = $this->makeActiveContract();
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'billing_period_start' => $contract->start_date,
            'billing_period_end' => Carbon::parse($contract->start_date)->addMonth()->subDay(),
            'due_date' => $contract->start_date,
            'amount_cents' => $contract->rent_amount,
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}/billing-schedule")->assertOk();

        $generated = collect($res->json('data'))->firstWhere('generated', true);
        $this->assertSame('paid', $generated['status']);
    }

    // ── Timeline ─────────────────────────────────────────────────────────────

    public function test_timeline_only_contains_real_events(): void
    {
        $contract = $this->makeActiveContract();

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}/timeline")->assertOk();

        $keys = collect($res->json('data'))->pluck('key');
        // No audit log rows exist for this factory-created contract, so only
        // the synthetic "created" seed event may appear.
        $this->assertSame(['created'], $keys->all());
    }

    public function test_admin_termination_appears_in_timeline(): void
    {
        $contract = $this->makeActiveContract();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/contracts/{$contract->id}/terminate", [
            'reason' => 'Confirmed lease violation reported by neighboring tenants.',
        ])->assertOk();

        $res = $this->getJson("/api/admin/contracts/{$contract->id}/timeline")->assertOk();
        $keys = collect($res->json('data'))->pluck('key');
        $this->assertTrue($keys->contains('contract_force_terminated'));
    }

    // ── Notes ────────────────────────────────────────────────────────────────

    public function test_admin_can_add_internal_note(): void
    {
        $contract = $this->makeActiveContract();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/contracts/{$contract->id}/notes", [
            'body' => 'Confirmed tenant identity via phone call.',
        ])->assertCreated()->assertJsonPath('note.admin_name', $this->admin->name);

        $this->assertDatabaseCount('contract_notes', 1);
    }

    public function test_note_body_is_required(): void
    {
        $contract = $this->makeActiveContract();

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/contracts/{$contract->id}/notes", ['body' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_notes_appear_in_detail(): void
    {
        $contract = $this->makeActiveContract();
        ContractNote::factory()->create([
            'contract_id' => $contract->id,
            'admin_id' => $this->admin->id,
            'body' => 'Reviewed lease terms with landlord.',
        ]);

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}")->assertOk();

        $this->assertCount(1, $res->json('notes'));
        $this->assertSame('Reviewed lease terms with landlord.', $res->json('notes.0.body'));
    }

    // ── Documents ────────────────────────────────────────────────────────────

    public function test_documents_endpoint_is_truthfully_empty(): void
    {
        $contract = $this->makeActiveContract();

        $this->actingAs($this->admin, 'admin');
        $res = $this->getJson("/api/admin/contracts/{$contract->id}/documents")->assertOk();

        $this->assertSame([], $res->json('data'));
    }
}
