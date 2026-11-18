<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Events\LedgerEntryMarkedOverdue;
use App\Events\RentGenerated;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger\BillingPeriodCalculator;
use App\Services\Ledger\RentChargeWriter;
use App\Support\Billing\BillingClock;
use Carbon\Carbon;

/**
 * LedgerAutomationService
 *
 * Handles time-based ledger automation:
 * - Automatic rent generation
 * - Overdue detection
 *
 * All operations are idempotent and auditable.
 *
 * Phase 3.5: Fires domain events for notifications
 */
class LedgerAutomationService
{
    public function __construct(
        protected AuditService $auditService,
        protected BillingPeriodCalculator $billingPeriods,
        protected RentChargeWriter $rentWriter,
    ) {}

    /**
     * Calculate the current (today-based) billing period for a contract.
     *
     * Delegates to BillingPeriodCalculator::currentPeriod(), which owns the
     * period math and the end-anchored due-date rule. Kept as a public method
     * because LedgerReconciliationService relies on it.
     *
     * @return array{start: Carbon, end: Carbon, due_date: Carbon}|null Null if the contract shouldn't generate rent
     */
    public function getCurrentBillingPeriod(Contract $contract): ?array
    {
        return $this->billingPeriods->currentPeriod($contract);
    }

    /**
     * Check if rent already exists for a specific billing period
     */
    public function rentExistsForPeriod(Contract $contract, Carbon $periodStart, ?Carbon $periodEnd = null): bool
    {
        // why: a period's identity is (contract, billing_period_start), the
        // same key as the partial unique index. Matching on the end date too
        // meant a lease whose end date was renewed mid-month looked unbilled.
        return LedgerEntry::where('contract_id', $contract->id)
            ->where('type', LedgerType::RENT)
            ->whereDate('billing_period_start', $periodStart->toDateString())
            ->exists();
    }

    /**
     * Bill every period that is due for a lease and not yet billed: normally
     * just the current one, plus any periods missed while the scheduler was
     * down (bounded catch-up). Idempotent per period (WYN-001 index).
     *
     * Phase 3.5: Fires RentGenerated event per created entry.
     *
     * @return LedgerEntry|null The latest entry created, or null if none was.
     */
    public function generateRentForContract(Contract $contract): ?LedgerEntry
    {
        $created = $this->generateDueRentForContract($contract);

        return $created === [] ? null : end($created)['entry'];
    }

    /**
     * @return list<array{entry: LedgerEntry, period: array<string, mixed>}>
     */
    public function generateDueRentForContract(Contract $contract): array
    {
        // Read the periods (and so the due day) and insert in one transaction
        // that takes the contract's write lock first, so an admin schedule
        // change cannot commit in between and SQLite queues instead of failing
        // with "database is locked" (see WriteLock). RentDueDateService takes
        // the same lock.
        $created = \Illuminate\Support\Facades\DB::transaction(function () use ($contract) {
            \App\Support\Database\WriteLock::row('contracts', $contract->getKey());

            $rows = [];
            foreach ($this->duePeriods($contract) as $period) {
                if ($entry = $this->createPeriodRent($contract, $period)) {
                    $rows[] = ['entry' => $entry, 'period' => $period];
                }
            }

            return $rows;
        });

        foreach ($created as $row) {
            $this->recordGenerated($contract, $row['entry'], $row['period']);
        }

        return $created;
    }

    /**
     * Periods that should exist by today and might not: every period after
     * the lease's latest rent charge up to and including the current one,
     * capped by billing.catch_up_max_periods. A lease with no charge yet (its
     * opening charge is posted at activation) falls back to the current period.
     *
     * why (launch closeout): the scheduler used to bill only the period
     * containing "today", so a month in which it was down or failed was never
     * billed and nothing reported it.
     *
     * @return list<array<string, mixed>>
     */
    private function duePeriods(Contract $contract): array
    {
        $current = $this->getCurrentBillingPeriod($contract);
        if (! $current) {
            return [];
        }

        $last = LedgerEntry::where('contract_id', $contract->id)
            ->where('type', LedgerType::RENT)
            ->orderByDesc('billing_period_start')
            ->first();

        if (! $last) {
            return [$current];
        }

        $periods = [];
        $cap = max(1, (int) config('billing.catch_up_max_periods', 12));
        $next = $this->billingPeriods->periodAfter($contract, $last->billing_period_end);
        while ($next && $next['start']->lte($current['start']) && count($periods) < $cap) {
            $periods[] = $next;
            $next = $this->billingPeriods->periodAfter($contract, $next['end']);
        }

        return $periods;
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function createPeriodRent(Contract $contract, array $period): ?LedgerEntry
    {
        // Existence check is a fast path; the partial unique index is the
        // authoritative guard and a refusal means "already generated" (WYN-001).
        if ($this->rentExistsForPeriod($contract, $period['start'])) {
            return null;
        }

        if ($contract->end_date && $period['start']->isAfter($contract->end_date)) {
            return null;
        }

        return $this->rentWriter->createOnce([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => $period['amount_cents'],
            'currency' => $contract->currency ?? 'USD',
            'billing_period_start' => $period['start'],
            'billing_period_end' => $period['end'],
            'due_date' => $period['due_date'],
            'status' => LedgerStatus::PENDING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function recordGenerated(Contract $contract, LedgerEntry $rentEntry, array $period): void
    {
        // Audit log
        $this->auditService->log(
            actor: null, // System-generated
            action: 'rent_entry_automated',
            subject: $rentEntry,
            description: "Automated rent entry generated for contract {$contract->id}: period {$period['start']->format('Y-m-d')} to {$period['end']->format('Y-m-d')}",
            metadata: [
                'contract_id' => $contract->id,
                'billing_period_start' => $period['start']->toDateString(),
                'billing_period_end' => $period['end']->toDateString(),
                'due_date' => $period['due_date']->toDateString(),
                'amount_cents' => $period['amount_cents'],
                'prorated' => $period['prorated'],
            ],
            severity: 'info'
        );

        // Phase 3.5: Fire domain event for notification
        $tenant = User::find($contract->tenant_id);
        event(new RentGenerated($rentEntry, $tenant));
    }

    /**
     * Mark overdue ledger entries
     *
     * Updates status from PENDING to OVERDUE for entries past due date
     *
     * Phase 3.5: Fires LedgerEntryMarkedOverdue event
     *
     * @return int Number of entries marked overdue
     */
    public function markOverdueEntries(): int
    {
        // Billing-calendar cutoff: due date + configured grace (0 by default).
        $dueBefore = BillingClock::overdueBeforeDate();
        $count = 0;

        $overdueEntries = LedgerEntry::where('status', LedgerStatus::PENDING)
            ->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
            ->whereDate('due_date', '<', $dueBefore)
            ->get();

        foreach ($overdueEntries as $entry) {
            try {
                // Compare-and-swap: false means a concurrent payment, waiver or
                // due-date extension changed the entry after it was selected,
                // so there is nothing to audit or notify (WYN-004).
                // The CAS also re-checks the due date, so an admin extension
                // committed after this SELECT is respected.
                if (! $entry->markOverdueIfPastDue($dueBefore)) {
                    continue;
                }

                // Audit log
                $this->auditService->log(
                    actor: null, // System-generated
                    action: 'ledger_entry_marked_overdue',
                    subject: $entry,
                    description: "Ledger entry {$entry->id} marked as overdue (due date: {$entry->due_date->format('Y-m-d')})",
                    metadata: [
                        'ledger_entry_id' => $entry->id,
                        'type' => $entry->type->value,
                        'due_date' => $entry->due_date->toDateString(),
                        'amount_cents' => $entry->amount_cents,
                    ],
                    severity: 'warning'
                );

                // Phase 3.5: Fire domain event for notification
                $tenant = User::find($entry->tenant_id);
                event(new LedgerEntryMarkedOverdue($entry, $tenant));

                $count++;
            } catch (\InvalidArgumentException $e) {
                // Log transition failure but continue processing other entries
                \Illuminate\Support\Facades\Log::warning('Failed to mark entry as overdue', [
                    'entry_id' => $entry->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Generate rent for all active contracts
     *
     * @return array ['created' => int, 'skipped' => int]
     */
    public function generateRentForAllContracts(): array
    {
        $created = 0;
        $skipped = 0;
        $failed = [];

        // Get all active contracts
        $activeContracts = Contract::where('status', ContractStatus::ACTIVE)->get();

        foreach ($activeContracts as $contract) {
            // One lease's failure (e.g. a lock lost to a concurrent admin
            // schedule change) must not stop billing for every lease after
            // it. The failed lease is retried by the next daily run, which
            // generates the same period exactly once (WYN-001 index).
            try {
                $entries = $this->generateDueRentForContract($contract);
            } catch (\Throwable $e) {
                $failed[] = $contract->id;
                \Illuminate\Support\Facades\Log::error('Rent generation failed for contract', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($entries) {
                $created += count($entries);
            } else {
                $skipped++;
            }
        }

        if ($failed) {
            $this->auditService->log(
                actor: null,
                action: 'rent_generation_failed',
                subject: null,
                description: count($failed).' contract(s) could not be billed in this rent run; they are retried on the next run.',
                metadata: ['contract_ids' => $failed],
                severity: 'critical'
            );
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => count($failed),
            'failed_contract_ids' => $failed,
        ];
    }
}
