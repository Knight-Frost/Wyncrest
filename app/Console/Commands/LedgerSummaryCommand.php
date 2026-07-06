<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerComputationEngine;
use Illuminate\Console\Command;

/**
 * LedgerSummaryCommand
 *
 * Prints the platform-wide financial summary computed by
 * LedgerComputationEngine, for a quick manual sanity check without opening
 * the admin UI. Read-only.
 */
class LedgerSummaryCommand extends Command
{
    protected $signature = 'ledger:summary';

    protected $description = 'Print the platform-wide ledger financial summary';

    public function handle(LedgerComputationEngine $engine): int
    {
        $summary = $engine->computePlatformFinancialSummary();

        $this->info('Wyncrest Ledger Summary');
        $this->info('========================');
        $this->newLine();
        $this->table(['Metric', 'Amount'], [
            ['Rent charged', $this->formatCents($summary['rent_charged_cents'])],
            ['Fees charged', $this->formatCents($summary['fees_charged_cents'])],
            ['Collected', $this->formatCents($summary['collected_cents'])],
            ['Outstanding', $this->formatCents($summary['outstanding_cents'])],
            ['Overdue', $this->formatCents($summary['overdue_cents'])],
            ['Due soon', $this->formatCents($summary['due_soon_cents'])],
            ['Total entries', (string) $summary['entry_count']],
        ]);

        return Command::SUCCESS;
    }

    protected function formatCents(int $cents): string
    {
        return 'GH₵ '.number_format($cents / 100, 2);
    }
}
