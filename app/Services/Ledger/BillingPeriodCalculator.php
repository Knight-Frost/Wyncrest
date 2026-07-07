<?php

namespace App\Services\Ledger;

use App\Enums\ContractStatus;
use App\Models\Contract;
use Carbon\Carbon;

/**
 * BillingPeriodCalculator
 *
 * The single home for "what is the billing period and due date" math. Before
 * this class the period arithmetic (`start + 1 month - 1 day`) and the due-date
 * rules lived, copy-pasted, in three rent generators (LedgerService's first /
 * sequential generators and LedgerAutomationService's today-based generator).
 * This class centralises that math so the shape of a billing period is defined
 * in exactly one place.
 *
 * ── KNOWN, DELIBERATE DIVERGENCE (do not "unify" without an explicit decision) ──
 *
 * Wyncrest historically computes the DUE DATE two different ways depending on
 * which generator runs, and this class preserves BOTH exactly rather than
 * silently changing real, legally-meaningful rent due dates:
 *
 *   • startAnchoredDueDate() — used by the first-period and sequential
 *     generators. The due date is `payment_day` within the month of the
 *     billing-period START, pushed forward a month only if it would land
 *     before the period start. NO month-overflow guard (Carbon overflows
 *     day 31 of a short month into the next month).
 *
 *   • endAnchoredDueDate() — used by the automated (today-based) generator.
 *     The due date is `payment_day` within the month of the billing-period
 *     END, WITH an overflow guard (an invalid day such as Feb 30 falls back
 *     to that month's last day).
 *
 * For most contracts these agree; for some (e.g. start Jan 10, payment_day 15)
 * they do not — the first period is start-anchored (Jan 15) while later
 * automated periods are end-anchored (Feb 15). Reconciling the two is a
 * separate, deliberate financial decision, tracked as a follow-up in
 * docs/ENGINES.md. This calculator's contract is: reproduce today's behavior
 * byte-for-byte.
 */
class BillingPeriodCalculator
{
    /**
     * A monthly billing period runs from $start through the day before the
     * same day-of-month one month later (start + 1 month - 1 day).
     */
    public function periodEnd(Carbon $start): Carbon
    {
        return $start->copy()->addMonth()->subDay();
    }

    /**
     * Start-anchored due date: `payment_day` in the month of the period start,
     * bumped a month if it precedes the start. Preserves the exact (guard-less)
     * behavior of the first/sequential generators.
     */
    public function startAnchoredDueDate(Carbon $start, int $paymentDay): Carbon
    {
        $dueDate = $start->copy()->day($paymentDay);

        if ($dueDate->lt($start)) {
            $dueDate->addMonth();
        }

        return $dueDate;
    }

    /**
     * End-anchored due date: `payment_day` in the month containing the period
     * end, with an invalid-day guard (e.g. Feb 30 -> Feb 28/29). Preserves the
     * exact behavior of the automated generator.
     */
    public function endAnchoredDueDate(Carbon $periodEnd, int $paymentDay): Carbon
    {
        $dueDate = $periodEnd->copy()->startOfMonth()->day($paymentDay);

        // Handle invalid days (e.g. Feb 30 -> last day of that month).
        if ($dueDate->day !== $paymentDay) {
            $dueDate = $periodEnd->copy()->endOfMonth();
        }

        return $dueDate;
    }

    /**
     * The first billing period for a contract, from its start_date.
     * Start-anchored due date.
     *
     * @return array{start: Carbon, end: Carbon, due_date: Carbon}
     */
    public function firstPeriod(Contract $contract): array
    {
        $start = Carbon::parse($contract->start_date);
        $end = $this->periodEnd($start);

        return [
            'start' => $start,
            'end' => $end,
            'due_date' => $this->startAnchoredDueDate($start, $contract->payment_day),
        ];
    }

    /**
     * The billing period immediately following a previous period's end (the day
     * after $previousEnd). Start-anchored due date. Used to back-fill historical
     * runs sequentially.
     *
     * @return array{start: Carbon, end: Carbon, due_date: Carbon}
     */
    public function periodAfter(Carbon $previousEnd, int $paymentDay): array
    {
        $start = $previousEnd->copy()->addDay();
        $end = $this->periodEnd($start);

        return [
            'start' => $start,
            'end' => $end,
            'due_date' => $this->startAnchoredDueDate($start, $paymentDay),
        ];
    }

    /**
     * The billing period that contains "today" for an active contract, or null
     * if the contract is not eligible to generate rent (not active, or ended).
     * End-anchored due date. This is the today-based automation path.
     *
     * @return array{start: Carbon, end: Carbon, due_date: Carbon}|null
     */
    public function currentPeriod(Contract $contract): ?array
    {
        // Only active contracts generate rent.
        if ($contract->status !== ContractStatus::ACTIVE) {
            return null;
        }

        $today = Carbon::today();
        $startDate = $contract->start_date->copy();

        // If the contract has ended, don't generate rent.
        if ($contract->end_date && $today->isAfter($contract->end_date)) {
            return null;
        }

        $periodsSinceStart = 0;
        $currentPeriodStart = $startDate->copy();

        // Walk forward one period at a time until we find the one containing today.
        while ($currentPeriodStart->lte($today)) {
            $currentPeriodEnd = $this->periodEnd($currentPeriodStart);

            if ($today->between($currentPeriodStart, $currentPeriodEnd)) {
                return [
                    'start' => $currentPeriodStart,
                    'end' => $currentPeriodEnd,
                    'due_date' => $this->endAnchoredDueDate($currentPeriodEnd, $contract->payment_day),
                ];
            }

            $currentPeriodStart->addMonth();
            $periodsSinceStart++;

            // Safety: never loop forever (100 years of monthly periods).
            if ($periodsSinceStart > 1200) {
                return null;
            }
        }

        return null;
    }
}
