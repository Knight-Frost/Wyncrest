<?php

namespace Database\Seeders\Dev;

use App\Enums\ContractStatus;
use App\Enums\TerminatedBy;
use App\Models\Contract;

/**
 * ContractSeeder — the leases behind the contracted units.
 *
 * Covers every contract lifecycle state the platform supports:
 *   - active     : 6 live leases (4 good standing + 1 owing + 1 late-fee)
 *   - terminated : 1 lease the tenant ended early (terminated_by + reason)
 *   - expired    : 1 lease that ran its full 12-month term and lapsed
 *
 * Contracts are created ALREADY in their final state on purpose: the
 * ContractObserver only auto-generates rent on the pending→active *transition*,
 * never on insert, so LedgerSeeder owns the entire (immutable, consistent) ledger
 * with no double-generation — and seeding a terminated/expired contract fires no
 * side effects. rent_amount is converted from the unit's major-unit rent to
 * integer cents, matching the contracts schema.
 */
class ContractSeeder extends DevSeeder
{
    public function run(): void
    {
        $counts = [];

        foreach (SeedCatalog::UNITS as $u) {
            if (! $u['contract'] || ! $u['tenant']) {
                continue;
            }

            $unit = $this->unitFromCatalog($u);
            $tenant = $this->user($u['tenant']);
            if (! $unit || ! $tenant || ! ($listing = $this->listingForUnit($unit))) {
                continue;
            }

            $attributes = array_merge(
                [
                    'landlord_id' => $listing->landlord_id,
                    'tenant_id' => $tenant->id,
                    'rent_amount' => (int) round($u['rent'] * 100), // major units → cents
                    'currency' => $this->currency(),
                    'billing_cycle' => 'monthly',
                    'payment_day' => 1,
                ],
                $this->lifecycleFields($u['contract'], (int) $u['months']),
            );

            Contract::updateOrCreate(['listing_id' => $listing->id], $attributes);
            $counts[$u['contract']] = ($counts[$u['contract']] ?? 0) + 1;
        }

        $summary = collect($counts)->map(fn ($n, $s) => "{$s}:{$n}")->implode(', ');
        $this->command?->info("  ✓ Contracts: {$summary}.");
    }

    /**
     * Status + dated period per contract lifecycle state.
     *
     * ACTIVE: starting the lease N whole months in the past gives LedgerSeeder N+1
     * monthly rent periods to bill (the oldest through the current month) — the
     * paid history / single overdue month land on real, past-due dates.
     *
     * TERMINATED / EXPIRED: the lease ran for exactly $months whole months in the
     * PAST and ended one month ago, so LedgerSeeder can bill exactly $months fully
     * paid periods (no obligation beyond the lease end). No terminated_at column
     * exists in the schema — the end_date carries the "when it ended" meaning.
     *
     * @return array<string,mixed>
     */
    protected function lifecycleFields(string $scenario, int $months): array
    {
        if ($scenario === 'active') {
            $start = now()->subMonthsNoOverflow(max($months, 1))->startOfMonth();

            return [
                'status' => ContractStatus::ACTIVE->value,
                'start_date' => $start,
                'end_date' => $start->copy()->addYear(),
            ];
        }

        // Former lease: ran $months months, ending one month ago.
        $end = now()->subMonthsNoOverflow(1)->startOfMonth();
        $start = $end->copy()->subMonthsNoOverflow(max($months, 1));

        if ($scenario === 'terminated') {
            return [
                'status' => ContractStatus::TERMINATED->value,
                'start_date' => $start,
                'end_date' => $end,
                'terminated_by' => TerminatedBy::TENANT->value,
                'termination_reason' => 'Tenant relocated for work and gave the required notice.',
            ];
        }

        // expired — a lease that simply ran its course; no termination metadata.
        return [
            'status' => ContractStatus::EXPIRED->value,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }
}
