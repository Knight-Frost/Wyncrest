<?php

namespace App\Console\Commands;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Services\AuditService;
use App\Services\Ledger\BillingPeriodCalculator;
use App\Services\Ledger\RentChargeWriter;
use App\Support\Billing\BillingClock;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * ledger:backfill-rent — bill billing periods that were never charged
 * (reported by ledger:reconcile as rent_period_gap). Dry run by default;
 * --apply writes each missing charge once (WYN-001 index), with an audit row
 * naming the operator's reason. Never touches existing charges.
 */
class BackfillRentCommand extends Command
{
    protected $signature = 'ledger:backfill-rent {contract : Contract UUID} {--apply : Write the charges} {--reason= : Required with --apply; kept in the audit log}';

    protected $description = 'List (or bill, with --apply) rent periods of a lease that were never charged';

    public function handle(BillingPeriodCalculator $calculator, RentChargeWriter $writer, AuditService $audit): int
    {
        $contract = Contract::findOrFail($this->argument('contract'));
        $apply = (bool) $this->option('apply');
        if ($apply && mb_strlen(trim((string) $this->option('reason'))) < 5) {
            $this->error('--reason is required with --apply (it is kept in the audit log).');

            return self::FAILURE;
        }

        $billed = LedgerEntry::where('contract_id', $contract->id)->where('type', LedgerType::RENT)
            ->pluck('billing_period_start')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $today = BillingClock::today();
        $missing = [];
        $period = $calculator->firstPeriod($contract);
        for ($i = 0; $period && $i < 1200 && $period['start']->lte($today); $i++) {
            if (! $billed->has($period['start']->toDateString())) {
                $missing[] = $period;
            }
            $period = $calculator->periodAfter($contract, $period['end']);
        }

        if (! $missing) {
            $this->info('No missing billing periods.');

            return self::SUCCESS;
        }

        $this->table(['Period', 'Due', 'Amount (cents)'], array_map(fn ($p) => [
            $p['start']->toDateString().' – '.$p['end']->toDateString(), $p['due_date']->toDateString(), $p['amount_cents'],
        ], $missing));

        if (! $apply) {
            $this->comment('Dry run. Re-run with --apply --reason="..." to post these charges.');

            return self::SUCCESS;
        }

        foreach ($missing as $p) {
            $entry = $writer->createOnce([
                'contract_id' => $contract->id, 'tenant_id' => $contract->tenant_id, 'landlord_id' => $contract->landlord_id,
                'type' => LedgerType::RENT, 'amount_cents' => $p['amount_cents'], 'currency' => $contract->currency,
                'billing_period_start' => $p['start'], 'billing_period_end' => $p['end'], 'due_date' => $p['due_date'],
                'status' => LedgerStatus::PENDING,
            ]);
            if ($entry) {
                $audit->log(actor: null, action: 'rent_entry_backfilled', subject: $entry,
                    description: "Missed rent period {$p['start']->toDateString()} billed by operator backfill. Reason: {$this->option('reason')}",
                    metadata: ['contract_id' => $contract->id, 'billing_period_start' => $p['start']->toDateString(), 'amount_cents' => $p['amount_cents'], 'reason' => $this->option('reason')],
                    severity: 'warning');
            }
        }
        $this->info(count($missing).' missing period(s) billed.');

        return self::SUCCESS;
    }
}
