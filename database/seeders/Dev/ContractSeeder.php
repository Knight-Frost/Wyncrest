<?php

namespace Database\Seeders\Dev;

use App\Enums\ContractStatus;
use App\Models\Contract;

/**
 * ContractSeeder — the active leases behind the occupied units.
 *
 * This development world uses ACTIVE leases only (4 in good standing + 1 owing).
 * Each occupied unit in the catalog gets one active contract on its listing.
 *
 * Active contracts are created ALREADY active on purpose: the ContractObserver
 * only auto-generates rent on the pending→active *transition*, not on insert, so
 * LedgerSeeder can own the entire (immutable, consistent) ledger with no
 * double-generation. rent_amount is converted from the unit's major-unit rent to
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
     * Status + dated period for an active lease that started $months ago.
     *
     * Starting the lease N whole months in the past gives LedgerSeeder N+1 monthly
     * rent periods to bill (the oldest through the current month), which is what
     * makes the paid history / single overdue month land on real, past-due dates.
     *
     * @return array<string,mixed>
     */
    protected function lifecycleFields(string $scenario, int $months): array
    {
        $start = now()->subMonthsNoOverflow(max($months, 1))->startOfMonth();

        return [
            'status' => ContractStatus::ACTIVE->value,
            'start_date' => $start,
            'end_date' => $start->copy()->addYear(),
        ];
    }
}
