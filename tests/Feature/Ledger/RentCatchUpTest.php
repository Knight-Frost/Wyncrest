<?php

namespace Tests\Feature\Ledger;

use App\Enums\ContractStatus;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Launch closeout rehearsal finding: the scheduler billed only "today's"
 * period, so months missed while it was down were never billed and the
 * reconciler said PASS. Hard-coded synthetic dates.
 */
class RentCatchUpTest extends TestCase
{
    use RefreshDatabase;

    private function lease(): Contract
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00', 'UTC'));
        $c = Contract::factory()->create([
            'status' => ContractStatus::PENDING_TENANT, 'rent_amount' => 250000, 'payment_day' => 1,
            'billing_anchor' => 'calendar', 'start_date' => '2026-12-01', 'end_date' => '2027-11-30',
        ]);
        $c->update(['status' => ContractStatus::ACTIVE]); // posts December

        return $c;
    }

    private function starts(Contract $c): array
    {
        return LedgerEntry::where('contract_id', $c->id)->where('type', 'rent')->orderBy('billing_period_start')
            ->get()->map(fn ($e) => $e->billing_period_start->toDateString())->all();
    }

    public function test_scheduler_outage_is_caught_up_once(): void
    {
        $c = $this->lease();

        // Scheduler down all of January and February; first run on Mar 3.
        Carbon::setTestNow(Carbon::parse('2027-03-03 01:00', 'UTC'));
        $this->artisan('ledger:generate-rent')->assertSuccessful();
        $this->artisan('ledger:generate-rent')->assertSuccessful();

        $this->assertSame(['2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01'], $this->starts($c));
        $this->assertSame(1000000, (int) LedgerEntry::where('contract_id', $c->id)->sum('amount_cents'));
        $this->assertSame(3, AuditLog::where('action', 'rent_entry_automated')->count());
        $this->assertSame('pass', app(LedgerReconciliationService::class)->run()['status']);
    }

    public function test_catch_up_is_bounded(): void
    {
        config(['billing.catch_up_max_periods' => 2]);
        $c = $this->lease();
        Carbon::setTestNow(Carbon::parse('2027-04-02 01:00', 'UTC'));

        $this->artisan('ledger:generate-rent')->assertSuccessful();
        $this->assertSame(['2026-12-01', '2027-01-01', '2027-02-01'], $this->starts($c));
        $this->artisan('ledger:generate-rent')->assertSuccessful();
        $this->assertSame(['2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01'], $this->starts($c));
    }

    public function test_interior_gap_is_reported_and_backfilled_by_operator(): void
    {
        $c = $this->lease();
        // Simulate legacy history (pre-catch-up) with January missing but
        // March billed: drop the no-delete trigger inside this test's
        // rolled-back transaction only, then remove January.
        Carbon::setTestNow(Carbon::parse('2027-03-02 01:00', 'UTC'));
        $this->artisan('ledger:generate-rent');
        \Illuminate\Support\Facades\DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
        \Illuminate\Support\Facades\DB::table('ledger_entries')->where('contract_id', $c->id)->whereDate('billing_period_start', '2027-01-01')->delete();

        $issue = collect(app(LedgerReconciliationService::class)->run()['issues'])->firstWhere('code', 'rent_period_gap');
        $this->assertSame(['2027-01-01'], $issue['metadata']['missing_period_starts'][$c->id]);

        $this->artisan('ledger:backfill-rent', ['contract' => $c->id])->assertSuccessful(); // dry run
        $this->assertNotContains('2027-01-01', $this->starts($c));
        $this->artisan('ledger:backfill-rent', ['contract' => $c->id, '--apply' => true])->assertFailed(); // no reason
        $this->artisan('ledger:backfill-rent', ['contract' => $c->id, '--apply' => true, '--reason' => 'Scheduler outage Jan 2027'])->assertSuccessful();
        $this->artisan('ledger:backfill-rent', ['contract' => $c->id, '--apply' => true, '--reason' => 'repeat'])->assertSuccessful();

        $this->assertSame(['2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01'], $this->starts($c));
        $this->assertSame(1, AuditLog::where('action', 'rent_entry_backfilled')->count());
        $this->assertNull(collect(app(LedgerReconciliationService::class)->run()['issues'])->firstWhere('code', 'rent_period_gap'));
    }
}
