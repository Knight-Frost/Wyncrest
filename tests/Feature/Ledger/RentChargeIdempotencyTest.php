<?php

namespace Tests\Feature\Ledger;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Contracts\ContractLifecycleService;
use App\Services\Contracts\InvalidContractTransitionException;
use App\Services\Ledger\BillingPeriodCalculator;
use App\Services\Ledger\RentChargeWriter;
use App\Services\LedgerAutomationService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RentChargeIdempotencyTest (forensic audit WYN-001 / WYN-002 / WYN-004)
 *
 * Rent duplicate-charge protection used to be an application-level
 * check-then-insert only, with no database constraint behind it, and the
 * contract transitions guarded on the caller's (possibly stale) in-memory
 * status. A second accept of the same contract (double-click, two tabs, a
 * retried request) therefore re-fired the activation observer and created a
 * SECOND first-month rent charge. These tests pin the repaired behavior:
 *   - a stale accept/terminate is refused against the persisted status,
 *   - the database itself refuses a second live rent row for a period,
 *   - every rent writer absorbs that refusal as "already generated",
 *   - the overdue sweep never reports a transition it did not make.
 *
 * All amounts are synthetic fixtures (GH₵2,500.00 = 250000 cents).
 */
class RentChargeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $this->listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);
    }

    protected function pendingContract(): Contract
    {
        return Contract::factory()->create([
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::PENDING_TENANT,
            'start_date' => '2026-03-01',
            'end_date' => '2027-02-28',
            'rent_amount' => 250000,
            'currency' => 'GHS',
            'payment_day' => 1,
        ]);
    }

    protected function rentCount(Contract $contract): int
    {
        return LedgerEntry::where('contract_id', $contract->id)
            ->where('type', LedgerType::RENT)
            ->count();
    }

    public function test_a_stale_second_accept_is_refused_and_creates_no_second_first_rent_charge(): void
    {
        $contract = $this->pendingContract();

        // Two requests each bound the contract while it was still pending.
        $firstRequestCopy = Contract::find($contract->id);
        $secondRequestCopy = Contract::find($contract->id);

        $lifecycle = app(ContractLifecycleService::class);
        $lifecycle->accept($firstRequestCopy, $this->tenant);

        $this->assertSame(1, $this->rentCount($contract));

        try {
            $lifecycle->accept($secondRequestCopy, $this->tenant);
            $this->fail('A stale second accept must be refused.');
        } catch (InvalidContractTransitionException) {
            // expected
        }

        $this->assertSame(1, $this->rentCount($contract), 'Exactly one first-month rent charge.');
        $this->assertSame(250000, (int) LedgerEntry::where('contract_id', $contract->id)->sum('amount_cents'));
        $this->assertSame(1, AuditLog::where('action', 'contract_accepted')->count());
    }

    public function test_repeat_accept_through_the_api_is_rejected_and_leaves_one_charge(): void
    {
        $contract = $this->pendingContract();

        $this->actingAs($this->tenant, 'sanctum')
            ->postJson("/api/tenant/contracts/{$contract->id}/accept")
            ->assertOk();

        $this->actingAs($this->tenant, 'sanctum')
            ->postJson("/api/tenant/contracts/{$contract->id}/accept")
            ->assertStatus(403);

        $this->assertSame(1, $this->rentCount($contract));
    }

    public function test_a_stale_accept_cannot_resurrect_a_terminated_contract(): void
    {
        $contract = $this->pendingContract();
        $staleTenantCopy = Contract::find($contract->id);

        $lifecycle = app(ContractLifecycleService::class);
        $lifecycle->terminateByLandlord(Contract::find($contract->id), $this->landlord, 'Unit no longer available');

        try {
            $lifecycle->accept($staleTenantCopy, $this->tenant);
            $this->fail('Accepting a contract that was terminated meanwhile must be refused.');
        } catch (InvalidContractTransitionException) {
            // expected
        }

        $this->assertSame(ContractStatus::TERMINATED, $contract->fresh()->status);
        $this->assertSame(0, $this->rentCount($contract));
    }

    public function test_the_database_refuses_a_second_live_rent_row_for_the_same_period(): void
    {
        $contract = $this->pendingContract();
        app(ContractLifecycleService::class)->accept($contract, $this->tenant);

        $first = LedgerEntry::where('contract_id', $contract->id)->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        LedgerEntry::create([
            'contract_id' => $contract->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'type' => LedgerType::RENT,
            'amount_cents' => 250000,
            'currency' => 'GHS',
            'billing_period_start' => $first->billing_period_start,
            'billing_period_end' => $first->billing_period_end,
            'due_date' => $first->due_date,
            'status' => LedgerStatus::PENDING,
        ]);
    }

    public function test_scheduler_race_after_the_existence_check_is_absorbed_without_a_duplicate(): void
    {
        $contract = $this->pendingContract();
        app(ContractLifecycleService::class)->accept($contract, $this->tenant);
        $this->assertSame(1, $this->rentCount($contract));
        $auditRowsBefore = AuditLog::count();

        // Simulate a second worker whose existence check ran before the first
        // worker's insert became visible: the check says "no rent yet".
        $racingWorker = \Mockery::mock(LedgerAutomationService::class, [
            app(AuditService::class),
            app(BillingPeriodCalculator::class),
            app(RentChargeWriter::class),
        ])->makePartial();
        $racingWorker->shouldReceive('rentExistsForPeriod')->andReturn(false);

        $result = $racingWorker->generateRentForContract($contract->fresh());

        $this->assertNull($result, 'The racing worker must report "skipped", not a new charge.');
        $this->assertSame(1, $this->rentCount($contract));
        $this->assertSame($auditRowsBefore, AuditLog::count(), 'No audit row for a charge that was never created.');
    }

    public function test_repeated_scheduler_runs_leave_exactly_one_charge_per_period(): void
    {
        $contract = $this->pendingContract();
        app(ContractLifecycleService::class)->accept($contract, $this->tenant);

        $this->artisan('ledger:generate-rent')->assertSuccessful();
        $this->artisan('ledger:generate-rent')->assertSuccessful();

        // Next month: exactly one more charge, however often the job runs.
        Carbon::setTestNow(Carbon::parse('2026-04-02'));
        $this->artisan('ledger:generate-rent')->assertSuccessful();
        $this->artisan('ledger:generate-rent')->assertSuccessful();

        $this->assertSame(2, $this->rentCount($contract));
        $this->assertSame(
            ['2026-03-01', '2026-04-01'],
            LedgerEntry::where('contract_id', $contract->id)->where('type', LedgerType::RENT)
                ->orderBy('billing_period_start')->get()
                ->map(fn ($e) => $e->billing_period_start->toDateString())->all()
        );
        $this->assertSame(500000, (int) LedgerEntry::where('contract_id', $contract->id)->sum('amount_cents'));
    }

    public function test_overdue_sweep_does_not_report_an_entry_settled_by_a_concurrent_payment(): void
    {
        $contract = $this->pendingContract();
        app(ContractLifecycleService::class)->accept($contract, $this->tenant);
        $rent = LedgerEntry::where('contract_id', $contract->id)->firstOrFail();

        // Due date has passed; the sweep will select this PENDING entry.
        Carbon::setTestNow(Carbon::parse('2026-03-20'));

        // Deterministic race: the moment the sweep loads the row, another
        // process settles it (query builder, as transitionStatus() does).
        LedgerEntry::retrieved(function (LedgerEntry $entry) use ($rent) {
            if ($entry->id === $rent->id) {
                LedgerEntry::whereKey($rent->id)->where('status', 'pending')->update(['status' => 'paid']);
            }
        });

        $marked = app(LedgerAutomationService::class)->markOverdueEntries();

        $this->assertSame(0, $marked);
        $this->assertSame(LedgerStatus::PAID, $rent->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'ledger_entry_marked_overdue')->count());
    }
}
