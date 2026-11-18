<?php

namespace Tests\Feature\Ledger;

use App\Enums\ContractStatus;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger\LedgerReconciliationService;
use App\Services\LedgerService;
use App\Support\Database\Contention;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WYN-013 (financial history cannot be hard-deleted), WYN-006 at the DB level
 * (posted terms immutable even to raw SQL), WYN-017 (contention → 409) and
 * WYN-020 (first-rent failure is durable and reported).
 */
class LedgerIntegrityTriggersTest extends TestCase
{
    use RefreshDatabase;

    private function entry(): LedgerEntry
    {
        return LedgerEntry::factory()->rent()->pending()->create(['amount_cents' => 250000]);
    }

    public function test_raw_delete_of_a_ledger_row_is_refused(): void
    {
        $e = $this->entry();
        $this->expectException(QueryException::class);
        DB::table('ledger_entries')->where('id', $e->id)->delete();
    }

    public function test_raw_update_of_posted_terms_is_refused_but_status_and_due_date_are_allowed(): void
    {
        $e = $this->entry();

        foreach (['amount_cents' => 1, 'billing_period_start' => '2001-01-01', 'original_due_date' => '2001-01-01', 'type' => 'refund'] as $col => $val) {
            try {
                DB::table('ledger_entries')->where('id', $e->id)->update([$col => $val]);
                $this->fail("update of {$col} was allowed");
            } catch (QueryException $ex) {
                $this->assertStringContainsString('immutable', $ex->getMessage());
            }
        }

        // The sanctioned compare-and-swap columns still work.
        $this->assertTrue($e->transitionStatus(\App\Enums\LedgerStatus::OVERDUE));
        $this->assertSame(250000, $e->fresh()->amount_cents);
    }

    public function test_contract_and_user_with_ledger_history_cannot_be_hard_deleted(): void
    {
        $e = $this->entry();

        try {
            DB::table('contracts')->where('id', $e->contract_id)->delete();
            $this->fail('contract delete allowed');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('ledger history', $ex->getMessage());
        }

        try {
            User::findOrFail($e->tenant_id)->forceDelete();
            $this->fail('user force delete allowed');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('ledger history', $ex->getMessage());
        }

        $this->assertSame(1, LedgerEntry::count());

        // A user with no financial history can still be removed.
        $clean = User::factory()->tenant()->create();
        $clean->forceDelete();
        $this->assertNull(User::withTrashed()->find($clean->id));
    }

    public function test_due_date_history_tables_are_append_only(): void
    {
        $e = $this->entry();
        DB::table('ledger_due_date_revisions')->insert([
            'ledger_entry_id' => $e->id, 'contract_id' => $e->contract_id,
            'previous_due_date' => '2026-12-01', 'new_due_date' => '2026-12-10',
            'previous_status' => 'pending', 'new_status' => 'pending', 'reason' => 'x',
        ]);

        $this->expectException(QueryException::class);
        DB::table('ledger_due_date_revisions')->update(['reason' => 'rewritten']);
    }

    public function test_write_contention_is_a_409_not_a_500(): void
    {
        Route::post('/api/_test/contention', function () {
            throw new QueryException('sqlite', 'update contracts set status = ?', ['active'], new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
        });

        $this->postJson('/api/_test/contention')->assertStatus(409)->assertJsonPath('code', 'write_conflict');

        $pg = new QueryException('pgsql', 'x', [], new \PDOException('serialization'));
        $pg->errorInfo = ['40001', 7, 'could not serialize access'];
        $this->assertTrue(Contention::isRetryable($pg));
        $other = new QueryException('sqlite', 'x', [], new \PDOException('SQLSTATE[23000]: UNIQUE constraint failed'));
        $this->assertFalse(Contention::isRetryable($other));
    }

    public function test_first_rent_failure_is_audited_and_reported_by_reconciliation(): void
    {
        $this->mock(LedgerService::class, function ($m) {
            $m->shouldReceive('generateFirstRentEntry')->andThrow(new \RuntimeException('synthetic failure'));
        });
        $contract = Contract::factory()->create(['status' => ContractStatus::PENDING_TENANT, 'start_date' => now()->addMonth()->startOfMonth(), 'end_date' => null]);

        $contract->update(['status' => ContractStatus::ACTIVE]);

        $this->assertSame(ContractStatus::ACTIVE, $contract->fresh()->status, 'activation still succeeds');
        $log = AuditLog::where('action', 'first_rent_generation_failed')->sole();
        $this->assertSame('critical', $log->severity);
        $this->assertSame('synthetic failure', $log->metadata['error']);

        $report = app(LedgerReconciliationService::class)->run();
        $issue = collect($report['issues'])->firstWhere('code', 'missing_first_rent');
        $this->assertSame([$contract->id], $issue['contract_ids']);
        $this->assertSame('fail', $report['status']);
    }
}
