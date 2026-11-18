<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\Ledger\LedgerReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ops:restore-check — verify a restored copy (forensic audit WYN-011).
 *
 * 1. Ledger totals match the backup manifest (rows and signed sum).
 * 2. ledger:reconcile rules pass.
 * 3. Every contract balance recomputed in raw SQL from the documented rule
 *    (sum of amount_cents; waived obligations count 0) equals the engine's.
 * 4. Every live document row has its file on disk.
 * Exit 0 only if all hold.
 */
class RestoreCheckCommand extends Command
{
    protected $signature = 'ops:restore-check {--manifest= : manifest.json written by wyncrest-backup.sh}';

    protected $description = 'Verify a restored database + files copy (balances, reconciliation, documents)';

    public function handle(LedgerComputationEngine $engine, LedgerReconciliationService $reconciliation): int
    {
        $ok = true;
        $rows = (int) DB::table('ledger_entries')->count();
        $sum = (int) DB::table('ledger_entries')->sum('amount_cents');

        if ($path = $this->option('manifest')) {
            $m = json_decode((string) @file_get_contents($path), true) ?: [];
            $match = ($m['ledger_rows'] ?? null) === $rows && ($m['ledger_sum_cents'] ?? null) === $sum;
            $ok = $ok && $match;
            $this->line(sprintf('Manifest ledger: %s rows / %s cents; restored: %d / %d  %s',
                $m['ledger_rows'] ?? '?', $m['ledger_sum_cents'] ?? '?', $rows, $sum, $match ? 'MATCH' : 'MISMATCH'));
        }

        $report = $reconciliation->run();
        $this->line('Reconciliation: '.strtoupper($report['status']));
        $ok = $ok && $report['status'] !== 'fail';

        $sql = DB::select("select contract_id, sum(case when status = 'waived' and type in ('rent','late_fee') then 0 else amount_cents end) as balance from ledger_entries group by contract_id");
        $mismatch = array_filter($sql, fn ($r) => (int) $r->balance !== $engine->computeContractBalance($r->contract_id));
        $this->line(sprintf('Independent balances: %d contracts, %d mismatches', count($sql), count($mismatch)));
        $ok = $ok && $mismatch === [];

        $missing = Document::query()->get()->filter(fn (Document $d) => ! Storage::disk($d->disk)->exists($d->stored_path));
        $this->line(sprintf('Documents: %d live, %d missing files%s', Document::count(), $missing->count(),
            $missing->isEmpty() ? '' : ' (ids: '.$missing->pluck('id')->implode(', ').')'));
        $ok = $ok && $missing->isEmpty();

        $ok ? $this->info('Restore check PASSED.') : $this->error('Restore check FAILED.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
