<?php

namespace App\Console\Commands;

use App\Services\LedgerAutomationService;
use Illuminate\Console\Command;

/**
 * MarkOverdueCommand
 *
 * Marks ledger entries as overdue when past their due date.
 * Safe to run multiple times (idempotent).
 *
 * Usage:
 *   php artisan ledger:mark-overdue
 */
class MarkOverdueCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ledger:mark-overdue';

    /**
     * The console command description.
     */
    protected $description = 'Mark pending ledger entries as overdue when past their due date';

    /**
     * Execute the console command.
     */
    public function handle(LedgerAutomationService $automationService): int
    {
        $this->info('⏰ Nexus - Overdue Detection');
        $this->info('============================');
        $this->newLine();

        $this->info('🔍 Scanning for overdue entries...');
        $this->newLine();

        $count = $automationService->markOverdueEntries();

        if ($count > 0) {
            $this->warn("⚠️  Marked {$count} entries as OVERDUE");
            $this->newLine();
            $this->comment('💡 Tip: Check audit logs for details');
        } else {
            $this->info('✅ No overdue entries found');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
