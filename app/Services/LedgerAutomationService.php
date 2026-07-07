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
    public function rentExistsForPeriod(Contract $contract, Carbon $periodStart, Carbon $periodEnd): bool
    {
        return LedgerEntry::where('contract_id', $contract->id)
            ->where('type', LedgerType::RENT)
            ->whereDate('billing_period_start', $periodStart->toDateString())
            ->whereDate('billing_period_end', $periodEnd->toDateString())
            ->exists();
    }

    /**
     * Generate rent entry for a contract's current billing period
     *
     * Idempotent: Only creates if rent doesn't already exist for period
     *
     * Phase 3.5: Fires RentGenerated event
     *
     * @return LedgerEntry|null Returns entry if created, null if skipped
     */
    public function generateRentForContract(Contract $contract): ?LedgerEntry
    {
        // Get current billing period
        $period = $this->getCurrentBillingPeriod($contract);

        if (! $period) {
            return null; // Contract not eligible for rent generation
        }

        // Check if rent already exists (idempotency)
        if ($this->rentExistsForPeriod($contract, $period['start'], $period['end'])) {
            return null; // Rent already exists, skip
        }

        // Don't generate rent if period starts after contract end date
        if ($contract->end_date && $period['start']->isAfter($contract->end_date)) {
            return null;
        }

        // Create rent entry
        $rentEntry = LedgerEntry::create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => $contract->rent_amount,
            'currency' => $contract->currency ?? 'USD',
            'billing_period_start' => $period['start'],
            'billing_period_end' => $period['end'],
            'due_date' => $period['due_date'],
            'status' => LedgerStatus::PENDING,
        ]);

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
                'amount_cents' => $contract->rent_amount,
            ],
            severity: 'info'
        );

        // Phase 3.5: Fire domain event for notification
        $tenant = User::find($contract->tenant_id);
        event(new RentGenerated($rentEntry, $tenant));

        return $rentEntry;
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
        $today = Carbon::today();
        $count = 0;

        // Get all PENDING entries where due_date < today
        $overdueEntries = LedgerEntry::where('status', LedgerStatus::PENDING)
            ->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
            ->whereDate('due_date', '<', $today->toDateString())
            ->get();

        foreach ($overdueEntries as $entry) {
            try {
                // Use proper status transition method to respect immutability
                $entry->transitionStatus(LedgerStatus::OVERDUE);

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

        // Get all active contracts
        $activeContracts = Contract::where('status', ContractStatus::ACTIVE)->get();

        foreach ($activeContracts as $contract) {
            $entry = $this->generateRentForContract($contract);

            if ($entry) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }
}
