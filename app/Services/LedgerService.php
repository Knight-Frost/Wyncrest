<?php

namespace App\Services;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Events\LedgerEntryMarkedOverdue;
use App\Models\Contract;
use App\Models\LedgerEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * LedgerService
 *
 * Handles ledger entry creation and financial calculations.
 * All money amounts in cents.
 * SECURITY: Respects LedgerEntry immutability - uses transitionStatus() for status changes.
 */
class LedgerService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Generate first rent ledger entry when contract becomes active.
     */
    public function generateFirstRentEntry(Contract $contract): LedgerEntry
    {
        // Calculate billing period (monthly)
        $billingStart = Carbon::parse($contract->start_date);
        $billingEnd = $billingStart->copy()->addMonth()->subDay();

        // Due date is based on payment_day of the month
        $dueDate = $billingStart->copy()->day($contract->payment_day);

        // If due date is before billing start, push to next month
        if ($dueDate->lt($billingStart)) {
            $dueDate->addMonth();
        }

        $entry = LedgerEntry::create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => $contract->rent_amount,
            'currency' => $contract->currency,
            'billing_period_start' => $billingStart,
            'billing_period_end' => $billingEnd,
            'due_date' => $dueDate,
            'status' => LedgerStatus::PENDING,
        ]);

        // Audit log
        $this->auditService->log(
            actor: null, // System-generated
            action: 'rent_entry_created',
            subject: $entry,
            description: "Rent entry created for contract {$contract->id}: {$billingStart->format('M Y')}",
            severity: 'info'
        );

        return $entry;
    }

    /**
     * Generate next rent entry for a contract.
     */
    public function generateNextRentEntry(Contract $contract): LedgerEntry
    {
        // Find the last rent entry
        $lastEntry = LedgerEntry::where('contract_id', $contract->id)
            ->where('type', LedgerType::RENT)
            ->orderBy('billing_period_end', 'desc')
            ->first();

        if (! $lastEntry) {
            return $this->generateFirstRentEntry($contract);
        }

        // Next billing period starts the day after the last one ended
        $billingStart = $lastEntry->billing_period_end->copy()->addDay();
        $billingEnd = $billingStart->copy()->addMonth()->subDay();

        // Due date
        $dueDate = $billingStart->copy()->day($contract->payment_day);
        if ($dueDate->lt($billingStart)) {
            $dueDate->addMonth();
        }

        $entry = LedgerEntry::create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => $contract->rent_amount,
            'currency' => $contract->currency,
            'billing_period_start' => $billingStart,
            'billing_period_end' => $billingEnd,
            'due_date' => $dueDate,
            'status' => LedgerStatus::PENDING,
        ]);

        // Audit log
        $this->auditService->log(
            actor: null,
            action: 'rent_entry_created',
            subject: $entry,
            description: "Rent entry created for contract {$contract->id}: {$billingStart->format('M Y')}",
            severity: 'info'
        );

        return $entry;
    }

    /**
     * Generate late fee for an overdue rent entry.
     *
     * @throws \InvalidArgumentException If entry cannot have late fee applied
     */
    public function generateLateFee(LedgerEntry $rentEntry, int $lateFeeAmountCents): LedgerEntry
    {
        if (! $rentEntry->type->isRent()) {
            throw new \InvalidArgumentException('Late fees can only be applied to rent entries');
        }

        if (! $rentEntry->isOverdue()) {
            throw new \InvalidArgumentException('Late fees can only be applied to overdue entries');
        }

        // Check if late fee already exists for this rent entry
        $existingLateFee = LedgerEntry::where('related_rent_entry_id', $rentEntry->id)
            ->where('type', LedgerType::LATE_FEE)
            ->first();

        if ($existingLateFee) {
            throw new \InvalidArgumentException('Late fee already exists for this rent entry');
        }

        $lateFeeEntry = LedgerEntry::create([
            'contract_id' => $rentEntry->contract_id,
            'tenant_id' => $rentEntry->tenant_id,
            'landlord_id' => $rentEntry->landlord_id,
            'type' => LedgerType::LATE_FEE,
            'amount_cents' => $lateFeeAmountCents,
            'currency' => $rentEntry->currency,
            'billing_period_start' => $rentEntry->billing_period_start,
            'billing_period_end' => $rentEntry->billing_period_end,
            'due_date' => now(), // Late fee is due immediately
            'status' => LedgerStatus::PENDING,
            'related_rent_entry_id' => $rentEntry->id,
        ]);

        // Audit log (warning severity - financial penalty)
        $amountDollars = $lateFeeAmountCents / 100;
        $this->auditService->log(
            actor: null,
            action: 'late_fee_applied',
            subject: $lateFeeEntry,
            description: "Late fee applied to rent entry {$rentEntry->id}: \${$amountDollars}",
            severity: 'warning'
        );

        return $lateFeeEntry;
    }

    /**
     * Mark entries as overdue.
     * Uses the proper transitionStatus() method to respect immutability.
     *
     * @return int Number of entries marked overdue
     */
    public function markOverdueEntries(): int
    {
        $entries = LedgerEntry::where('status', LedgerStatus::PENDING)
            ->where('due_date', '<', now())
            ->get();

        $count = 0;
        foreach ($entries as $entry) {
            try {
                // Use proper status transition method
                $entry->transitionStatus(LedgerStatus::OVERDUE);
                $count++;

                // Fire event for notification system
                $tenant = $entry->tenant;
                if ($tenant) {
                    event(new LedgerEntryMarkedOverdue($entry, $tenant));
                }

                // Audit log
                $this->auditService->log(
                    actor: null,
                    action: 'entry_marked_overdue',
                    subject: $entry,
                    description: "Ledger entry {$entry->id} marked overdue",
                    severity: 'warning'
                );
            } catch (\Exception $e) {
                Log::error("Failed to mark entry {$entry->id} as overdue", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Mark entry as paid.
     *
     * @param  string  $paymentIntentId  Stripe payment intent ID
     */
    public function markEntryPaid(LedgerEntry $entry, string $paymentIntentId): bool
    {
        $result = $entry->transitionStatus(LedgerStatus::PAID, $paymentIntentId);

        if ($result) {
            $this->auditService->log(
                actor: null,
                action: 'entry_paid',
                subject: $entry,
                description: "Ledger entry {$entry->id} marked paid via {$paymentIntentId}",
                severity: 'info'
            );
        }

        return $result;
    }

    /**
     * Waive an entry (admin action).
     */
    public function waiveEntry(LedgerEntry $entry, string $reason): bool
    {
        $result = $entry->transitionStatus(LedgerStatus::WAIVED);

        if ($result) {
            $this->auditService->log(
                actor: null,
                action: 'entry_waived',
                subject: $entry,
                description: "Ledger entry {$entry->id} waived. Reason: {$reason}",
                severity: 'warning',
                metadata: ['reason' => $reason]
            );
        }

        return $result;
    }
}
