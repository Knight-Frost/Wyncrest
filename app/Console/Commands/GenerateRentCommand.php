<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\LedgerAutomationService;
use Illuminate\Console\Command;

/**
 * GenerateRentCommand
 * 
 * Automatically generates rent entries for active contracts.
 * Safe to run multiple times (idempotent).
 * 
 * Usage:
 *   php artisan ledger:generate-rent
 *   php artisan ledger:generate-rent --contract=uuid
 */
class GenerateRentCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ledger:generate-rent 
                            {--contract= : Generate rent for a specific contract ID}';

    /**
     * The console command description.
     */
    protected $description = 'Generate rent entries for active contracts based on billing periods';

    /**
     * Execute the console command.
     */
    public function handle(LedgerAutomationService $automationService): int
    {
        $this->info('🏠 Nexus - Automated Rent Generation');
        $this->info('=====================================');
        $this->newLine();
        
        $contractId = $this->option('contract');
        
        if ($contractId) {
            // Generate for specific contract
            return $this->generateForContract($contractId, $automationService);
        } else {
            // Generate for all active contracts
            return $this->generateForAllContracts($automationService);
        }
    }

    /**
     * Generate rent for a specific contract
     */
    protected function generateForContract(string $contractId, LedgerAutomationService $automationService): int
    {
        $this->info("🔍 Generating rent for contract: {$contractId}");
        $this->newLine();
        
        $contract = Contract::find($contractId);
        
        if (!$contract) {
            $this->error("❌ Contract not found: {$contractId}");
            return self::FAILURE;
        }
        
        $entry = $automationService->generateRentForContract($contract);
        
        if ($entry) {
            $this->info("✅ Rent entry created:");
            $this->line("   ID: {$entry->id}");
            $this->line("   Period: {$entry->billing_period_start->format('Y-m-d')} to {$entry->billing_period_end->format('Y-m-d')}");
            $this->line("   Amount: \${$entry->amount_in_dollars}");
            $this->line("   Due: {$entry->due_date->format('Y-m-d')}");
        } else {
            $this->warn("⏭️  Rent already exists or contract not eligible");
        }
        
        $this->newLine();
        return self::SUCCESS;
    }

    /**
     * Generate rent for all active contracts
     */
    protected function generateForAllContracts(LedgerAutomationService $automationService): int
    {
        $this->info('🔄 Processing all active contracts...');
        $this->newLine();
        
        $result = $automationService->generateRentForAllContracts();
        
        $this->info("📊 Summary:");
        $this->line("   ✅ Created: {$result['created']} rent entries");
        $this->line("   ⏭️  Skipped: {$result['skipped']} contracts (already exists or not eligible)");
        
        $this->newLine();
        
        if ($result['created'] > 0) {
            $this->info("✨ Rent generation complete!");
        } else {
            $this->comment("ℹ️  No new rent entries needed at this time.");
        }
        
        return self::SUCCESS;
    }
}
