<?php

namespace App\Console\Commands;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * MarkExpiredContractsCommand
 *
 * Transitions ACTIVE contracts whose end_date has passed to EXPIRED.
 * Billing already stops at end_date (LedgerAutomationService yields no
 * period past it); this closes the status gap so analytics, portal views,
 * and the renew() gate reflect that the lease has actually ended.
 * Open-ended contracts (end_date null) never expire.
 */
class MarkExpiredContractsCommand extends Command
{
    protected $signature = 'contracts:mark-expired';

    protected $description = 'Mark active contracts past their end date as expired';

    public function handle(AuditService $auditService): int
    {
        $expired = Contract::where('status', ContractStatus::ACTIVE)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', Carbon::today())
            ->get();

        foreach ($expired as $contract) {
            $contract->update(['status' => ContractStatus::EXPIRED]);

            $auditService->log(
                actor: null, // Scheduled system action
                action: 'contract_expired',
                subject: $contract,
                description: "Contract {$contract->id} reached its end date ({$contract->end_date->format('Y-m-d')}) and was marked expired",
                severity: 'info'
            );
        }

        $this->info("Marked {$expired->count()} contract(s) as expired.");

        return self::SUCCESS;
    }
}
