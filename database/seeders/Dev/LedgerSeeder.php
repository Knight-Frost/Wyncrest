<?php

namespace Database\Seeders\Dev;

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
 * Scenarios, taken from the catalog's per-unit `standing`:
 *   - 'good'    : every month (including the current one) is PAID → balance is 0.
 *   - 'owing'   : every prior month is PAID, the latest month is left OVERDUE and
 *                 unpaid → balance equals EXACTLY one month of rent (no late fee).
 *   - 'latefee' : like 'owing', PLUS a REAL late fee raised through
 *                 LedgerService::generateLateFee on the overdue month → balance
 *                 equals one month of rent + the late fee.
 *   - 'former'  : a terminated/expired lease whose every month was PAID before it
 *                 ended → balance is 0 (a settled, closed lease history).
 *
 * The owing tenant gets NO late fee — that scenario is owned by 'latefee' so the
 * two owing profiles stay distinct and each balance is traceable to its entries.
 * Every late fee is created only through the real service (which enforces "must be
 * overdue" and "no duplicate late fee per rent entry"), never hand-inserted.
 */
class LedgerSeeder extends DevSeeder
{
    protected LedgerService $ledger;

    public function run(): void
    {
        $this->ledger = app(LedgerService::class);
        $entriesBefore = LedgerEntry::count();
        $counts = ['good' => 0, 'owing' => 0, 'latefee' => 0, 'former' => 0];

        foreach (SeedCatalog::contractedUnits() as $u) {
            $unit = $this->unitFromCatalog($u);
            $contract = $unit ? Contract::where('listing_id', $this->listingForUnit($unit)?->id)->first() : null;
            if (! $contract) {
                continue;
            }

            match ($u['standing']) {
                'owing' => $this->seedOwing($contract, (int) $u['months']),
                'latefee' => $this->seedLateFee($contract, (int) $u['months']),
                'former' => $this->seedFormerSettled($contract, (int) $u['months']),
                default => $this->seedGoodStanding($contract, (int) $u['months']),
            };
            $counts[$u['standing']]++;
        }

        $total = LedgerEntry::count() - $entriesBefore;
        $this->command?->info(
            "  ✓ Ledger: {$total} immutable entries — {$counts['good']} paid-to-zero, "
            ."{$counts['owing']} owing one month, {$counts['latefee']} owing rent + late fee, "
            ."{$counts['former']} settled former leases."
        );
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
     * Owing one month PLUS a real late fee. Identical to seedOwing, then a late
     * fee is raised on the overdue month through LedgerService::generateLateFee
     * (which requires the entry to be overdue and refuses duplicates). The late
     * fee is 10% of the monthly rent. Final balance = one month rent + late fee.
     */
    protected function seedLateFee(Contract $contract, int $months): void
    {
        $entries = $this->generateMonthlyRent($contract, $months);
        $last = count($entries) - 1;
        $overdueEntry = null;

        foreach ($entries as $i => $entry) {
            if ($i === $last) {
                $entry->transitionStatus(LedgerStatus::OVERDUE);
                $overdueEntry = $entry;
            } else {
                $this->settlePaid($entry);
            }
        }

        if ($overdueEntry) {
            $lateFeeCents = (int) round($contract->rent_amount * 0.10);
            $this->ledger->generateLateFee($overdueEntry, $lateFeeCents);
        }
    }

    /**
     * A closed former lease (terminated/expired): every month the lease ran was
     * paid in full before it ended, so the balance is 0. The lease ran exactly
     * $months whole months, so we bill EXACTLY $months periods (no obligation
     * beyond the lease end) and settle every one.
     */
    protected function seedFormerSettled(Contract $contract, int $months): void
    {
        // generateMonthlyRent($n) yields $n+1 entries, so ($months - 1) gives the
        // $months periods the lease actually ran.
        foreach ($this->generateMonthlyRent($contract, max($months - 1, 0)) as $entry) {
            $this->settlePaid($entry);
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
