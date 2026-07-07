<?php

namespace App\Services\Ledger;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\LedgerEntry;

/**
 * PaymentEntryFactory
 *
 * The single definition of how a PAYMENT ledger entry mirrors the obligation it
 * settles. Both settlement paths — Stripe (PaymentService::recordSuccessfulPayment)
 * and manual/offline (LedgerService::recordManualPayment) — build a PAYMENT entry
 * whose base shape is identical (negative amount = money received, same
 * contract/tenant/landlord/currency/billing period, status PAID, linked back to
 * the obligation via related_rent_entry_id). They differ ONLY in the settlement
 * identity: a Stripe path stamps `stripe_payment_intent_id`, a manual path stamps
 * `payment_method` + `payment_reference`.
 *
 * This factory owns the shared base so the two paths can never drift on how a
 * payment represents the obligation it clears. It deliberately owns ONLY the
 * attribute shape — the transaction, row locking, duplicate/idempotency handling,
 * and event dispatch stay in each caller, because those legitimately differ
 * (a redelivered Stripe webhook must resolve to the existing entry; a manual
 * payment relies on the obligation's compare-and-swap transition instead).
 *
 * The amount rule (payment = -obligation) is enforced here and is consistent with
 * LedgerComputationEngine's canonical sign convention (payments stored negative).
 */
class PaymentEntryFactory
{
    /**
     * Build the attributes for a PAYMENT entry that settles $obligation in full.
     *
     * @param  array<string, mixed>  $settlementIdentity  Either
     *                                                    ['stripe_payment_intent_id' => string] or
     *                                                    ['payment_method' => string, 'payment_reference' => ?string]
     * @return array<string, mixed>
     */
    public function forObligation(LedgerEntry $obligation, array $settlementIdentity): array
    {
        return array_merge([
            'contract_id' => $obligation->contract_id,
            'tenant_id' => $obligation->tenant_id,
            'landlord_id' => $obligation->landlord_id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => -$obligation->amount_cents, // Negative = reduces balance owed
            'currency' => $obligation->currency,
            'billing_period_start' => $obligation->billing_period_start,
            'billing_period_end' => $obligation->billing_period_end,
            'due_date' => now(),
            'status' => LedgerStatus::PAID,
            'related_rent_entry_id' => $obligation->id,
        ], $settlementIdentity);
    }
}
