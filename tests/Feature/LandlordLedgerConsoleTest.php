<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\AuditLog;
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
 * LandlordLedgerConsoleTest
 *
 * Covers the landlord Rent Ledger console payloads that go beyond the flat
 * entry list: the per-contract balances rollup + KPI summary (index), the
 * single-entry case file (audit trail + linked entries), the tenant/contract
 * and property statements, and scoped/audited CSV export. Every money figure
 * is produced by LedgerComputationEngine / LandlordLedgerService, so these
 * tests assert the wiring, scoping, and shape rather than re-deriving math.
 */
class LandlordLedgerConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Property $property;

    protected Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();

        $this->property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $this->property->id]);
        $listing = Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $this->contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'rent_amount' => 420000,
            'payment_day' => 1,
        ]);
    }

    private function entry(array $overrides): LedgerEntry
    {
        return LedgerEntry::factory()->create(array_merge([
            'contract_id' => $this->contract->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
        ], $overrides));
    }

    /** A settled month (rent + matching payment) this calendar month. */
    private function seedSettledMonth(): void
    {
        $this->entry([
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => 420000,
            'due_date' => now()->startOfMonth(),
            'created_at' => now()->startOfMonth(),
        ]);
        $this->entry([
            'type' => LedgerType::PAYMENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => -420000,
            'due_date' => now()->startOfMonth()->addHours(8),
            'created_at' => now()->startOfMonth()->addHours(8),
        ]);
    }

    public function test_index_returns_balances_and_month_summary(): void
    {
        $this->seedSettledMonth();
        // An overdue obligation from a past month → outstanding + overdue + tenants_overdue.
        $this->entry([
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::OVERDUE,
            'amount_cents' => 420000,
            'due_date' => now()->subMonth()->startOfMonth(),
            'created_at' => now()->subMonth()->startOfMonth(),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $res = $this->getJson('/api/landlord/ledger')->assertOk();

        // One balance row for the single contract.
        $res->assertJsonCount(1, 'balances');
        $row = $res->json('balances.0');
        $this->assertSame($this->contract->id, $row['contract_id']);
        $this->assertSame(420000, $row['balance_cents']);      // one unpaid month remains
        $this->assertSame('overdue', $row['status']);
        $this->assertSame($this->property->name, $row['property']['name']);

        // Summary: collected/charged THIS month reflect the settled month only;
        // outstanding/overdue reflect the past-due obligation.
        $summary = $res->json('summary');
        $this->assertSame(420000, $summary['collected_month_cents']);
        $this->assertSame(420000, $summary['charged_month_cents']);
        $this->assertSame(420000, $summary['outstanding_cents']);
        $this->assertSame(420000, $summary['overdue_cents']);
        $this->assertSame(1, $summary['tenants_overdue']);
    }

    public function test_show_case_file_includes_audit_trail_and_linked_entries(): void
    {
        $rent = $this->entry([
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::OVERDUE,
            'amount_cents' => 420000,
            'due_date' => now()->subDays(10),
            'created_at' => now()->subDays(10),
        ]);
        $fee = $this->entry([
            'type' => LedgerType::LATE_FEE,
            'status' => LedgerStatus::PENDING,
            'amount_cents' => 10000,
            'related_rent_entry_id' => $rent->id,
            'due_date' => now()->subDays(4),
            'created_at' => now()->subDays(4),
        ]);

        AuditLog::create([
            'actor_type' => User::class,
            'actor_id' => $this->landlord->id,
            'action' => 'late_fee_applied',
            'subject_type' => LedgerEntry::class,
            'subject_id' => $rent->id,
            'description' => 'Late fee applied',
            'severity' => 'warning',
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $res = $this->getJson("/api/landlord/ledger/{$rent->id}")->assertOk();

        $res->assertJsonCount(1, 'audit_trail');
        $this->assertSame('late_fee_applied', $res->json('audit_trail.0.action'));
        // The late fee references this rent entry → surfaced as a linked entry.
        $linkedIds = collect($res->json('linked_entries'))->pluck('id');
        $this->assertTrue($linkedIds->contains($fee->id));
    }

    public function test_contract_statement_computes_period_movement(): void
    {
        $this->seedSettledMonth();

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $res = $this->getJson("/api/landlord/ledger/statement/contract/{$this->contract->id}?year=".now()->year.'&month='.now()->month)
            ->assertOk();

        $this->assertSame(0, $res->json('opening_cents'));
        $this->assertSame(420000, $res->json('charges_cents'));
        $this->assertSame(-420000, $res->json('payments_cents'));
        $this->assertSame(0, $res->json('ending_cents'));
        $this->assertSame(0, $res->json('adjustments_cents'));
        $res->assertJsonCount(2, 'entries');
    }

    public function test_property_statement_breaks_down_by_unit(): void
    {
        $this->seedSettledMonth();

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $res = $this->getJson("/api/landlord/ledger/statement/property/{$this->property->id}")->assertOk();

        $this->assertSame(1, $res->json('unit_count'));
        $this->assertSame(420000, $res->json('collected_month_cents'));
        $this->assertSame(420000, $res->json('charged_month_cents'));
        $res->assertJsonCount(1, 'units');
        $this->assertSame($this->contract->id, $res->json('units.0.contract_id'));
    }

    public function test_statements_are_owner_scoped(): void
    {
        $other = User::factory()->landlord()->create();
        Sanctum::actingAs($other, [], 'sanctum');

        $this->getJson("/api/landlord/ledger/statement/contract/{$this->contract->id}")->assertForbidden();
        $this->getJson("/api/landlord/ledger/statement/property/{$this->property->id}")->assertForbidden();
    }

    public function test_export_is_scoped_and_audit_logged(): void
    {
        $this->seedSettledMonth();

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $response = $this->get("/api/landlord/ledger/export?contract_id={$this->contract->id}&reason=Monthly+accounting")
            ->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('content-type'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ledger_exported',
            'actor_id' => $this->landlord->id,
        ]);
        $log = AuditLog::where('action', 'ledger_exported')->latest()->first();
        $this->assertSame('Monthly accounting', $log->metadata['reason']);
        $this->assertSame($this->landlord->id, $log->metadata['filters']['landlord_id']);
    }
}
