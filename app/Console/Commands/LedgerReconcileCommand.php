<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerReconciliationService;
use Illuminate\Console\Command;

/**
 * LedgerReconcileCommand
 *
 * Runs LedgerReconciliationService and reports pass/warning/fail. Intended
 * to be run manually before a release and safe to wire into CI — exits
 * non-zero on 'fail' so a pipeline can gate on it. Never mutates data.
 */
class LedgerReconcileCommand extends Command
{
    protected $signature = 'ledger:reconcile {--json : Output raw JSON instead of a formatted report}';

    protected $description = 'Run ledger integrity/reconciliation checks and report pass/warning/fail';

    public function handle(LedgerReconciliationService $reconciliation): int
    {
        $report = $reconciliation->run();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));

            return $report['status'] === 'fail' ? Command::FAILURE : Command::SUCCESS;
        }

        $this->info('Ledger Reconciliation');
        $this->info('======================');
        $this->newLine();

        $summary = $report['summary'];
        $this->line('Rent charged:  '.$this->formatCents($summary['rent_charged_cents']));
        $this->line('Fees charged:  '.$this->formatCents($summary['fees_charged_cents']));
        $this->line('Collected:     '.$this->formatCents($summary['collected_cents']));
        $this->line('Outstanding:   '.$this->formatCents($summary['outstanding_cents']));
        $this->line('Overdue:       '.$this->formatCents($summary['overdue_cents']));
        $this->line('Due soon:      '.$this->formatCents($summary['due_soon_cents']));
        $this->line('Entries:       '.$summary['entry_count']);
        $this->newLine();

        if (empty($report['issues'])) {
            $this->info('✅ Status: PASS — no issues found.');

            return Command::SUCCESS;
        }

        foreach ($report['issues'] as $issue) {
            $icon = $issue['severity'] === 'fail' ? '❌' : '⚠️ ';
            $this->line("{$icon} [{$issue['severity']}] {$issue['code']}: {$issue['message']}");

            if (! empty($issue['entry_ids'])) {
                $this->line('     entries: '.implode(', ', array_slice($issue['entry_ids'], 0, 10)).
                    (count($issue['entry_ids']) > 10 ? ' …' : ''));
            }
            if (! empty($issue['contract_ids'])) {
                $this->line('     contracts: '.implode(', ', array_slice($issue['contract_ids'], 0, 10)));
            }
            if ($issue['expected'] !== null || $issue['actual'] !== null) {
                $this->line("     expected={$issue['expected']} actual={$issue['actual']}");
            }
        }

        $this->newLine();

        $status = $report['status'];
        if ($status === 'fail') {
            $this->error('❌ Status: FAIL');

            return Command::FAILURE;
        }

        $this->warn('⚠️  Status: WARNING');

        return Command::SUCCESS;
    }

    protected function formatCents(int $cents): string
    {
        return 'GH₵ '.number_format($cents / 100, 2);
    }
}
