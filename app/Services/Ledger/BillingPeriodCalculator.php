<?php

namespace App\Services\Ledger;

use App\Enums\BillingAnchor;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Support\Billing\BillingClock;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * BillingPeriodCalculator
 *
 * The single home for "what is the billing period, its amount and its due
 * date". Every rent generator (first charge on activation, the scheduled
 * job, the sequential back-fill), the reconciler and every preview call this
 * class, so a period is defined in exactly one place.
 *
 * ── Policy (owner-approved, November 2026) ──────────────────────────────────
 *
 * CALENDAR leases (every lease created under the policy):
 *   • Periods are calendar months, 1st → last day.
 *   • A mid-month START yields an opening stub (start → month end); a
 *     mid-month END yields a closing stub (1st → end). Stubs are PRORATED by
 *     day: rent × days ÷ days-in-that-month, rounded half-up to the pesewa.
 *   • Due on the resolved due day (default 1) of the period's month, clamped
 *     to that month's last day (day 31 → Feb 28/29, Apr 30), and never before
 *     the period starts (an opening stub is due no earlier than move-in) nor
 *     after it ends.
 *
 * ANNIVERSARY leases (legacy, signed before the policy):
 *   • Periods run start → start + 1 month − 1 day, always billed in full;
 *     this arithmetic is unchanged so every future period continues the
 *     identity sequence already on the ledger.
 *   • Due on the first occurrence of the due day on/after the period start,
 *     clamped to month length, and never after the period end. (Forensic
 *     audit WYN-015: the scheduled generator used to anchor on the period END
 *     month, so a Jan-10/day-15 lease was due Mar 15 for Feb 10 – Mar 9.)
 *
 * The due day comes from RentDueDayResolver (signed payment_day + admin
 * schedule changes). The due date is a PROPERTY of a charge, never part of
 * its identity: identity is (contract_id, billing_period_start).
 *
 * @phpstan-type Period array{start: Carbon, end: Carbon, due_date: Carbon, amount_cents: int, prorated: bool}
 */
class BillingPeriodCalculator
{
    public function __construct(
        protected ?RentDueDayResolver $dueDays = null,
    ) {
        $this->dueDays ??= new RentDueDayResolver;
    }

    /**
     * The opening billing period of a lease (what activation charges).
     *
     * @return Period
     */
    public function firstPeriod(Contract $contract, ?Collection $extraSchedule = null): array
    {
        $start = $this->date($contract->start_date);

        return $this->periodStartingOn($contract, $start, $extraSchedule);
    }

    /**
     * The period that begins the day after $previousEnd, or null when that
     * would start after the lease has ended.
     *
     * @return Period|null
     */
    public function periodAfter(Contract $contract, \DateTimeInterface $previousEnd, ?Collection $extraSchedule = null): ?array
    {
        $start = $this->date($previousEnd)->addDay();

        if ($contract->end_date && $start->gt($this->date($contract->end_date))) {
            return null;
        }

        return $this->periodStartingOn($contract, $start, $extraSchedule);
    }

    /**
     * The period containing "today" (billing calendar) for an ACTIVE lease,
     * or null when the lease is not active, has not started or has ended.
     *
     * @return Period|null
     */
    public function currentPeriod(Contract $contract, ?Carbon $today = null): ?array
    {
        if ($contract->status !== ContractStatus::ACTIVE) {
            return null;
        }

        return $this->periodContaining($contract, $today ?? BillingClock::today());
    }

    /**
     * The period containing $date, or null outside the lease term.
     *
     * @return Period|null
     */
    public function periodContaining(Contract $contract, \DateTimeInterface $date, ?Collection $extraSchedule = null): ?array
    {
        $day = $this->date($date);
        $leaseStart = $this->date($contract->start_date);

        if ($day->lt($leaseStart)) {
            return null;
        }
        if ($contract->end_date && $day->gt($this->date($contract->end_date))) {
            return null;
        }

        if ($this->anchor($contract) === BillingAnchor::CALENDAR) {
            $start = $day->copy()->startOfMonth()->max($leaseStart);

            return $this->periodStartingOn($contract, $start, $extraSchedule);
        }

        // Anniversary: walk forward exactly as the legacy generator did
        // (cumulative addMonth from the lease start) so identities match.
        $start = $leaseStart->copy();
        for ($i = 0; $i <= 1200; $i++) {
            $end = $this->anniversaryEnd($start);
            if ($day->between($start, $end)) {
                return $this->periodStartingOn($contract, $start->copy(), $extraSchedule);
            }
            $start->addMonth();
        }

        return null;
    }

    /**
     * Up to $count consecutive periods beginning with the one that starts on
     * $from (which must be a real period start). Used for previews.
     *
     * @return list<Period>
     */
    public function project(Contract $contract, \DateTimeInterface $from, int $count, ?Collection $extraSchedule = null): array
    {
        $periods = [];
        $period = $this->periodStartingOn($contract, $this->date($from), $extraSchedule);

        while ($period && count($periods) < $count) {
            $periods[] = $period;
            $period = $this->periodAfter($contract, $period['end'], $extraSchedule);
        }

        return $periods;
    }

    /**
     * Build the period that starts on $start (a lease start or a period
     * boundary) with its end, amount and due date.
     *
     * @return Period
     */
    public function periodStartingOn(Contract $contract, Carbon $start, ?Collection $extraSchedule = null): array
    {
        $start = $start->copy()->startOfDay();
        $leaseEnd = $contract->end_date ? $this->date($contract->end_date) : null;
        $rent = (int) $contract->rent_amount;

        if ($this->anchor($contract) === BillingAnchor::CALENDAR) {
            $end = $start->copy()->endOfMonth()->startOfDay();
            if ($leaseEnd && $leaseEnd->lt($end)) {
                $end = $leaseEnd->copy();
            }
            $fullMonth = $start->day === 1 && $end->isSameDay($start->copy()->endOfMonth());
            $amount = $fullMonth ? $rent : $this->prorate($rent, $start, $end);
        } else {
            // why: legacy anniversary periods are billed in full and never
            // clipped, exactly as before, so their amounts do not change.
            $end = $this->anniversaryEnd($start);
            $fullMonth = true;
            $amount = $rent;
        }

        $dueDay = $this->dueDays->dueDayFor($contract, $start, $extraSchedule);

        return [
            'start' => $start,
            'end' => $end,
            'due_date' => $this->dueDate($contract, $start, $end, $dueDay),
            'amount_cents' => $amount,
            'prorated' => ! $fullMonth,
        ];
    }

    /**
     * The due date for a period given a due day of the month.
     */
    public function dueDate(Contract $contract, Carbon $start, Carbon $end, int $dueDay): Carbon
    {
        $due = $this->clampedDay($start, $dueDay);

        if ($this->anchor($contract) === BillingAnchor::ANNIVERSARY && $due->lt($start)) {
            $due = $this->clampedDay($start->copy()->startOfMonth()->addMonthNoOverflow(), $dueDay);
        }

        if ($due->lt($start)) {
            $due = $start->copy();
        }
        if ($due->gt($end)) {
            $due = $end->copy();
        }

        return $due;
    }

    /**
     * Day $day of $month's month, or that month's last day if shorter.
     */
    public function clampedDay(Carbon $month, int $day): Carbon
    {
        $first = $month->copy()->startOfMonth()->startOfDay();

        return $first->addDays(min(max(1, $day), $first->daysInMonth) - 1);
    }

    /**
     * Daily proration within one calendar month, rounded half-up to the
     * minor unit, in integer arithmetic (no floats touch money).
     */
    public function prorate(int $monthlyCents, Carbon $start, Carbon $end): int
    {
        $days = (int) $start->diffInDays($end) + 1;
        $daysInMonth = $start->daysInMonth;

        return intdiv(2 * $monthlyCents * $days + $daysInMonth, 2 * $daysInMonth);
    }

    /** Legacy anniversary period end (start + 1 month − 1 day, unchanged). */
    public function anniversaryEnd(Carbon $start): Carbon
    {
        return $start->copy()->addMonth()->subDay();
    }

    public function anchor(Contract $contract): BillingAnchor
    {
        return $contract->billing_anchor instanceof BillingAnchor
            ? $contract->billing_anchor
            : (BillingAnchor::tryFrom((string) $contract->billing_anchor) ?? BillingAnchor::CALENDAR);
    }

    private function date(\DateTimeInterface|string $value): Carbon
    {
        return Carbon::parse($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)->startOfDay();
    }
}
