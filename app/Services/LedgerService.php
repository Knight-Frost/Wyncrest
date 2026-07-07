<?php

namespace App\Services;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\PaymentMethod;
use App\Events\PaymentSucceeded;
use App\Events\RentGenerated;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger\BillingPeriodCalculator;
use App\Services\Ledger\PaymentEntryFactory;
use Illuminate\Support\Facades\DB;

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
        protected AuditService $auditService,
        protected BillingPeriodCalculator $billingPeriods,
        protected PaymentEntryFactory $paymentEntries,
    ) {}

    /**
     * Generate first rent ledger entry when contract becomes active.
     */
    public function generateFirstRentEntry(Contract $contract): LedgerEntry
    {
        // Billing period + start-anchored due date (see BillingPeriodCalculator).
        $period = $this->billingPeriods->firstPeriod($contract);
        $billingStart = $period['start'];
        $billingEnd = $period['end'];
        $dueDate = $period['due_date'];

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

        // Notify the tenant about their first rent obligation, exactly like
        // the scheduled generator does for every subsequent month.
        if ($contract->tenant) {
            event(new RentGenerated($entry, $contract->tenant));
        }

        return $entry;
    }

    /**
     * Generate the rent entry for the period immediately after the last one.
     *
     * Live monthly billing is driven by LedgerAutomationService (today-based,
     * idempotent per period); this sequential generator exists for building
     * historical runs — its only production consumer is the dev
     * LedgerSeeder, which back-fills past months a today-based generator
     * cannot produce. Deliberately fires no RentGenerated event: seeded
     * history must not spam notifications.
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

        // Next billing period starts the day after the last one ended;
        // start-anchored due date (see BillingPeriodCalculator).
        $period = $this->billingPeriods->periodAfter($lastEntry->billing_period_end, $contract->payment_day);
        $billingStart = $period['start'];
        $billingEnd = $period['end'];
        $dueDate = $period['due_date'];

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
    public function generateLateFee(LedgerEntry $rentEntry, int $lateFeeAmountCents, ?Admin $actor = null): LedgerEntry
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
        $amount = number_format($lateFeeAmountCents / 100, 2);
        $this->auditService->log(
            actor: $actor,
            action: 'late_fee_applied',
            subject: $lateFeeEntry,
            description: "Late fee applied to rent entry {$rentEntry->id}: GH₵{$amount}",
            severity: 'warning'
        );

        return $lateFeeEntry;
    }

    /**
     * Record a landlord-entered manual/offline payment (cash, mobile money,
     * bank transfer) against one open rent/late-fee entry. Mirrors
     * PaymentService::recordSuccessfulPayment()'s PAYMENT-entry shape, but
     * stamps payment_method/payment_reference instead of a Stripe payment
     * intent id, since no Stripe transaction is involved.
     *
     * Always settles the entry's FULL display amount — Wyncrest does not
     * support partial payments (see LedgerComputationEngine's docblock).
     *
     * @throws \InvalidArgumentException If the entry is not an open rent/late-fee obligation
     */
    public function recordManualPayment(LedgerEntry $entry, PaymentMethod $method, ?string $reference, User $actor): LedgerEntry
    {
        if (! $entry->type->isObligation() || ! $entry->status->isDue()) {
            throw new \InvalidArgumentException('Only a pending or overdue rent/late fee entry can have a payment recorded against it.');
        }

        $paymentEntry = DB::transaction(function () use ($entry, $method, $reference) {
            // Settle the obligation first: transitionStatus() is a
            // compare-and-swap, so a concurrent webhook/manual payment that
            // already settled it makes this throw instead of double-crediting,
            // and the transaction rolls the payment entry back with it.
            if (! $entry->transitionStatus(LedgerStatus::PAID)) {
                throw new \InvalidArgumentException('This entry was settled by another payment while recording. No payment was recorded.');
            }

            // Shared PAYMENT-entry shape lives in PaymentEntryFactory; the
            // manual identity is the offline method + reference.
            return LedgerEntry::create(
                $this->paymentEntries->forObligation($entry, [
                    'payment_method' => $method->value,
                    'payment_reference' => $reference,
                ])
            );
        });

        $amount = number_format($entry->amount_cents / 100, 2);
        $this->auditService->log(
            actor: $actor,
            action: 'payment_recorded',
            subject: $entry,
            description: "Landlord recorded a {$method->label()} payment of GH₵{$amount} for ledger entry {$entry->id}",
            metadata: [
                'payment_entry_id' => $paymentEntry->id,
                'payment_method' => $method->value,
                'payment_reference' => $reference,
            ],
            severity: 'info'
        );

        // Offline payers deserve the same receipt as Stripe payers.
        if ($entry->tenant) {
            event(new PaymentSucceeded($paymentEntry, $entry, $entry->tenant));
        }

        return $paymentEntry;
    }

    /**
     * Waive an entry (admin action).
     */
    public function waiveEntry(LedgerEntry $entry, string $reason, ?Admin $actor = null): bool
    {
        $result = $entry->transitionStatus(LedgerStatus::WAIVED);

        if ($result) {
            $this->auditService->log(
                actor: $actor,
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
