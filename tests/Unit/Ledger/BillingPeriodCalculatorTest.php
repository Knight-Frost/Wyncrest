<?php

namespace Tests\Unit\Ledger;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Services\Ledger\BillingPeriodCalculator;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Pins the billing-period + due-date math that three rent generators used to
 * duplicate. These assertions are the guard rail against a future "unify the
 * due-date rule" refactor silently moving real rent due dates — including the
 * DELIBERATE start-anchored vs end-anchored divergence the class documents.
 */
class BillingPeriodCalculatorTest extends TestCase
{
    private BillingPeriodCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new BillingPeriodCalculator;
    }

    private function contract(array $attrs = []): Contract
    {
        // Unsaved model — the calculator only reads cast attributes.
        return new Contract(array_merge([
            'status' => ContractStatus::ACTIVE,
            'start_date' => '2025-01-10',
            'payment_day' => 15,
        ], $attrs));
    }

    public function test_period_end_is_one_month_minus_one_day(): void
    {
        $end = $this->calc->periodEnd(Carbon::parse('2025-01-10'));

        $this->assertSame('2025-02-09', $end->format('Y-m-d'));
    }

    public function test_start_anchored_due_date_stays_in_start_month_when_day_not_yet_passed(): void
    {
        // payment_day 15, start day 10 -> due the 15th of the start month.
        $due = $this->calc->startAnchoredDueDate(Carbon::parse('2025-01-10'), 15);

        $this->assertSame('2025-01-15', $due->format('Y-m-d'));
    }

    public function test_start_anchored_due_date_bumps_a_month_when_day_already_passed(): void
    {
        // payment_day 5, start day 10 -> the 5th already passed, bump to next month.
        $due = $this->calc->startAnchoredDueDate(Carbon::parse('2025-01-10'), 5);

        $this->assertSame('2025-02-05', $due->format('Y-m-d'));
    }

    public function test_end_anchored_due_date_uses_month_of_period_end(): void
    {
        // Mirrors LedgerAutomationTest: period end Mar 14, payment_day 1 -> Mar 1.
        $due = $this->calc->endAnchoredDueDate(Carbon::parse('2025-03-14'), 1);

        $this->assertSame('2025-03-01', $due->format('Y-m-d'));
    }

    public function test_end_anchored_due_date_guards_invalid_day_with_month_end(): void
    {
        // payment_day 30 with a February period end -> clamp to last day of Feb.
        $due = $this->calc->endAnchoredDueDate(Carbon::parse('2025-02-14'), 30);

        $this->assertSame('2025-02-28', $due->format('Y-m-d'));
    }

    public function test_first_period_is_start_anchored(): void
    {
        $period = $this->calc->firstPeriod($this->contract([
            'start_date' => '2025-01-10',
            'payment_day' => 15,
        ]));

        $this->assertSame('2025-01-10', $period['start']->format('Y-m-d'));
        $this->assertSame('2025-02-09', $period['end']->format('Y-m-d'));
        $this->assertSame('2025-01-15', $period['due_date']->format('Y-m-d'));
    }

    public function test_period_after_starts_the_day_after_previous_end(): void
    {
        $period = $this->calc->periodAfter(Carbon::parse('2025-02-09'), 15);

        $this->assertSame('2025-02-10', $period['start']->format('Y-m-d'));
        $this->assertSame('2025-03-09', $period['end']->format('Y-m-d'));
        $this->assertSame('2025-02-15', $period['due_date']->format('Y-m-d'));
    }

    public function test_current_period_matches_automation_expectations(): void
    {
        Carbon::setTestNow('2025-03-01');

        $period = $this->calc->currentPeriod($this->contract([
            'start_date' => '2025-02-15',
            'payment_day' => 1,
        ]));

        $this->assertNotNull($period);
        $this->assertSame('2025-02-15', $period['start']->format('Y-m-d'));
        $this->assertSame('2025-03-14', $period['end']->format('Y-m-d'));
        $this->assertSame('2025-03-01', $period['due_date']->format('Y-m-d'));
    }

    public function test_current_period_is_null_for_non_active_contract(): void
    {
        Carbon::setTestNow('2025-03-01');

        $period = $this->calc->currentPeriod($this->contract([
            'status' => ContractStatus::DRAFT,
            'start_date' => '2025-02-15',
        ]));

        $this->assertNull($period);
    }

    public function test_current_period_is_null_after_contract_end(): void
    {
        Carbon::setTestNow('2025-03-01');

        $period = $this->calc->currentPeriod($this->contract([
            'start_date' => '2025-01-01',
            'end_date' => '2025-02-01',
        ]));

        $this->assertNull($period);
    }

    /**
     * Documents the deliberate divergence: for a Jan-10 start with payment_day
     * 15, the first period is start-anchored (Jan 15) while the automated
     * current period is end-anchored (Feb 15). If a future change makes these
     * agree, that is a real financial-behavior decision — update this test on
     * purpose, do not "fix" it silently.
     */
    public function test_start_and_end_anchors_deliberately_diverge(): void
    {
        Carbon::setTestNow('2025-01-20'); // inside the first period

        $contract = $this->contract(['start_date' => '2025-01-10', 'payment_day' => 15]);

        $first = $this->calc->firstPeriod($contract);
        $current = $this->calc->currentPeriod($contract);

        $this->assertSame('2025-01-15', $first['due_date']->format('Y-m-d'));
        $this->assertSame('2025-02-15', $current['due_date']->format('Y-m-d'));
        $this->assertNotEquals(
            $first['due_date']->format('Y-m-d'),
            $current['due_date']->format('Y-m-d'),
            'Start-anchored and end-anchored due dates are expected to differ here.'
        );
    }
}
