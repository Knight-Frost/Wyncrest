<?php

namespace App\Services\Ledger;

use App\Models\Contract;
use App\Models\RentDueScheduleChange;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * RentDueDayResolver
 *
 * Answers "on which day of the month is rent due for the billing period that
 * starts on date P" for one lease.
 *
 * Base rule: the lease's signed payment_day (1 for every lease created under
 * the first-of-month policy). Admin schedule changes are an append-only
 * timeline of (effective_from, due_day) rows. A later row supersedes every
 * EARLIER row whose effective_from is on or after its own, i.e. "from month X
 * onward, day N" replaces whatever was planned from X onward, so the
 * timeline can be replayed from the rows alone, with no row ever edited.
 */
class RentDueDayResolver
{
    /**
     * The effective timeline, oldest effective month first. Each element is
     * ['effective_from' => Carbon, 'due_day' => int, 'change' => RentDueScheduleChange].
     *
     * @param  Collection<int, RentDueScheduleChange>|null  $extra  Hypothetical rows (preview).
     * @return list<array{effective_from: Carbon, due_day: int, change: RentDueScheduleChange}>
     */
    public function timeline(Contract $contract, ?Collection $extra = null): array
    {
        $rows = $this->changesFor($contract);
        if ($extra) {
            $rows = $rows->concat($extra);
        }

        /** @var array<string, array{effective_from: Carbon, due_day: int, change: RentDueScheduleChange}> $byMonth */
        $byMonth = [];
        foreach ($rows as $row) {
            $from = Carbon::parse($row->effective_from)->startOfMonth();
            $key = $from->format('Y-m-d');
            foreach (array_keys($byMonth) as $existing) {
                if ($existing >= $key) {
                    unset($byMonth[$existing]);
                }
            }
            $byMonth[$key] = ['effective_from' => $from, 'due_day' => (int) $row->due_day, 'change' => $row];
        }

        ksort($byMonth);

        return array_values($byMonth);
    }

    /**
     * @param  Collection<int, RentDueScheduleChange>|null  $extra  Hypothetical rows (preview).
     */
    public function dueDayFor(Contract $contract, \DateTimeInterface $periodStart, ?Collection $extra = null): int
    {
        $day = (int) ($contract->payment_day ?: config('billing.default_due_day', 1));
        $start = Carbon::parse($periodStart->format('Y-m-d'));

        foreach ($this->timeline($contract, $extra) as $step) {
            if ($step['effective_from']->lte($start)) {
                $day = $step['due_day'];
            }
        }

        return $day;
    }

    /**
     * @return Collection<int, RentDueScheduleChange>
     */
    private function changesFor(Contract $contract): Collection
    {
        if (! $contract->exists) {
            return collect();
        }

        // Always read the persisted rows (ordered by id = creation order); a
        // cached relation could miss a change made earlier in this request.
        return RentDueScheduleChange::where('contract_id', $contract->getKey())->orderBy('id')->get();
    }
}
