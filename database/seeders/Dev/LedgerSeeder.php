<?php

namespace Database\Seeders\Dev;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Services\LedgerService;

/**
 * LedgerSeeder — the immutable financial ledger.
 *
 * Builds a mathematically consistent ledger for every active lease using the REAL
 * money paths, so every balance the UI shows is derivable and true:
 *   - rent charges  (LedgerService::generateFirstRentEntry / generateNextRentEntry)
 *   - payments      (PAYMENT entry with NEGATIVE amount_cents, mirroring PaymentService)
 *   - overdue       (transitionStatus → OVERDUE, the one unpaid month)
 *
 * Immutability is honoured throughout: entries are created once and status is only
 * ever changed via transitionStatus(). Payments are stored as negative amounts so
 * PaymentService::getTenantBalance() (Σ obligations + Σ payments) stays correct.
 *
 * Two scenarios, taken from the catalog's per-unit `standing`:
 *   - 'good'  : every month (including the current one) is PAID → balance is 0.
 *   - 'owing' : every prior month is PAID, the latest month is left OVERDUE and
 *               unpaid → balance equals EXACTLY one month of rent.
 *
 * No late fee is invented for the owing tenant: late fees are produced by the real
 * overdue-processing rules (LedgerService::generateLateFee, run by the scheduled
 * mark-overdue command), not by the seeder. So the owing balance is one clean month.
 */
class LedgerSeeder extends DevSeeder
{
    protected LedgerService $ledger;

    public function run(): void
    {
        $this->ledger = app(LedgerService::class);
        $entriesBefore = LedgerEntry::count();
        $paid = 0;
        $owing = 0;

        foreach (SeedCatalog::leasedUnits() as $u) {
            $unit = $this->unitFromCatalog($u);
            $contract = $unit ? Contract::where('listing_id', $this->listingForUnit($unit)?->id)->first() : null;

            if (! $contract || $contract->status !== ContractStatus::ACTIVE) {
                continue;
            }

            if ($u['standing'] === 'owing') {
                $this->seedOwing($contract, (int) $u['months']);
                $owing++;
            } else {
                $this->seedGoodStanding($contract, (int) $u['months']);
                $paid++;
            }
        }

        $total = LedgerEntry::count() - $entriesBefore;
        $this->command?->info("  ✓ Ledger: {$total} immutable entries — {$paid} fully-paid leases, {$owing} owing one month.");
    }

    /**
     * Good standing: generate one rent entry per month from lease start to now and
     * settle every one of them. The tenant owes nothing (balance = 0).
     */
    protected function seedGoodStanding(Contract $contract, int $months): void
    {
        foreach ($this->generateMonthlyRent($contract, $months) as $entry) {
            $this->settlePaid($entry);
        }
    }

    /**
     * Owing one month: settle every month except the most recent, then mark the
     * latest (genuinely past-due) month OVERDUE and leave it unpaid. Balance ends
     * up equal to exactly one month's rent — traceable to a single ledger entry.
     */
    protected function seedOwing(Contract $contract, int $months): void
    {
        $entries = $this->generateMonthlyRent($contract, $months);
        $last = count($entries) - 1;

        foreach ($entries as $i => $entry) {
            if ($i === $last) {
                $entry->transitionStatus(LedgerStatus::OVERDUE); // unpaid, no late fee invented
            } else {
                $this->settlePaid($entry);
            }
        }
    }

    /**
     * Generate $months+1 sequential monthly rent entries via the real service.
     *
     * @return array<int,LedgerEntry>
     */
    protected function generateMonthlyRent(Contract $contract, int $months): array
    {
        $entries = [$this->ledger->generateFirstRentEntry($contract)];

        for ($i = 0; $i < $months; $i++) {
            $entries[] = $this->ledger->generateNextRentEntry($contract);
        }

        return $entries;
    }

    /** Record a full payment: negative PAYMENT entry + transition rent → PAID. */
    protected function settlePaid(LedgerEntry $rent): void
    {
        $paymentIntentId = $this->demoIntentId($rent);

        $this->recordPayment($rent, $rent->amount_cents, $paymentIntentId);
        $rent->transitionStatus(LedgerStatus::PAID, $paymentIntentId);
    }

    /**
     * Create a PAYMENT ledger entry mirroring PaymentService::recordSuccessfulPayment:
     * negative amount_cents (money received), status PAID, linked to the obligation.
     */
    protected function recordPayment(LedgerEntry $rent, int $amountCents, string $paymentIntentId): void
    {
        LedgerEntry::create([
            'contract_id' => $rent->contract_id,
            'tenant_id' => $rent->tenant_id,
            'landlord_id' => $rent->landlord_id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => -abs($amountCents), // negative reduces the balance
            'currency' => $rent->currency,
            'billing_period_start' => $rent->billing_period_start,
            'billing_period_end' => $rent->billing_period_end,
            'due_date' => now(),
            'status' => LedgerStatus::PAID,
            'related_rent_entry_id' => $rent->id,
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);
    }

    /** A clearly-fake, deterministic Stripe intent id (never a real charge). */
    protected function demoIntentId(LedgerEntry $rent): string
    {
        return 'pi_demo_seed_'.substr(str_replace('-', '', (string) $rent->id), 0, 16);
    }
}
