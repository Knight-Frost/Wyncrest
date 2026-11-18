<?php

namespace App\Services\Ledger;

use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * RentChargeWriter
 *
 * The single insert point for RENT ledger entries (forensic audit WYN-001).
 *
 * Every rent generator (first period on activation, the sequential back-fill,
 * and the scheduled today-based job) does a cheap existence check first, but a
 * check-then-insert cannot be made race-free on its own. The authoritative guard
 * is the partial unique index `ledger_entries_unique_live_rent_period`
 * (contract_id, billing_period_start WHERE type='rent' AND status<>'waived').
 * This writer turns that index's refusal into the ordinary "already generated"
 * outcome instead of a 500, so callers stay idempotent under retries and
 * concurrent workers.
 *
 * The insert runs in its own (nested) transaction so that, on Postgres, a
 * refused insert rolls back to a savepoint and does not poison an enclosing
 * transaction such as a contract activation.
 */
class RentChargeWriter
{
    /**
     * Create the rent entry unless a live one already exists for the period.
     *
     * @param  array<string, mixed>  $attributes  Full RENT entry attributes.
     * @return LedgerEntry|null The new entry, or null if the period was already billed.
     */
    public function createOnce(array $attributes): ?LedgerEntry
    {
        try {
            return DB::transaction(fn () => LedgerEntry::create($attributes));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * The rent entry already billed for a contract period, if any (any status,
     * matching the generators' existence rule).
     */
    public function existingFor(string $contractId, \DateTimeInterface $periodStart): ?LedgerEntry
    {
        return LedgerEntry::where('contract_id', $contractId)
            ->where('type', LedgerType::RENT)
            ->whereDate('billing_period_start', $periodStart->format('Y-m-d'))
            ->orderBy('created_at')
            ->first();
    }
}
