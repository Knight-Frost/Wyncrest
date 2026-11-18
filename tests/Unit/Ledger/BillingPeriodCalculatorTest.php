<?php

namespace Tests\Unit\Ledger;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Services\Ledger\BillingPeriodCalculator;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Pins the first-of-month rent policy with HARD-CODED expected dates and
 * amounts (never recomputed with the same formula), so a change to the
 * calculator cannot silently move a real due date or amount.
 *
 * Policy: calendar months, due day 1 by default, opening/closing stubs
 * prorated by day (rent × days ÷ days-in-month, half-up to the pesewa), due
 * never before the period starts nor after it ends; legacy anniversary leases
 * keep their period arithmetic and full amounts.
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
        // Unsaved model: the calculator only reads cast attributes and finds
        // no schedule changes for a contract that does not exist.
        return new Contract(array_merge([
            'status' => ContractStatus::ACTIVE,
            'start_date' => '2026-01-01',
            'end_date' => null,
            'payment_day' => 1,
            'rent_amount' => 250000,
            'billing_anchor' => 'calendar',
        ], $attrs));
    }

    /** @param array{start: Carbon, end: Carbon, due_date: Carbon, amount_cents: int} $p */
    private function assertPeriod(array $p, string $start, string $end, string $due, int $amount): void
    {
        $this->assertSame(
            [$start, $end, $due, $amount],
            [$p['start']->toDateString(), $p['end']->toDateString(), $p['due_date']->toDateString(), $p['amount_cents']]
        );
    }

    public function test_first_of_month_lease_is_due_on_the_first_in_full(): void
    {
        $this->assertPeriod($this->calc->firstPeriod($this->contract()), '2026-01-01', '2026-01-31', '2026-01-01', 250000);
    }

    public function test_mid_month_start_is_a_prorated_opening_stub_due_on_move_in(): void
    {
        // Jan 10–31 = 22 of 31 days: 250000 × 22 / 31 = 177419.35 → 177419.
        $c = $this->contract(['start_date' => '2026-01-10']);
        $first = $this->calc->firstPeriod($c);
        $this->assertPeriod($first, '2026-01-10', '2026-01-31', '2026-01-10', 177419);
        $this->assertTrue($first['prorated']);

        // The first recurring charge is the full February, due Feb 1.
        $this->assertPeriod($this->calc->periodAfter($c, $first['end']), '2026-02-01', '2026-02-28', '2026-02-01', 250000);
    }

    public function test_february_in_leap_and_non_leap_years(): void
    {
        // 2028 is a leap year: Feb 15–29 = 15 of 29 days → 250000×15/29 = 129310.34 → 129310.
        $leap = $this->contract(['start_date' => '2028-02-15']);
        $this->assertPeriod($this->calc->firstPeriod($leap), '2028-02-15', '2028-02-29', '2028-02-15', 129310);

        // 2027: Feb 15–28 = 14 of 28 days → exactly half.
        $plain = $this->contract(['start_date' => '2027-02-15']);
        $this->assertPeriod($this->calc->firstPeriod($plain), '2027-02-15', '2027-02-28', '2027-02-15', 125000);
    }

    public function test_due_day_31_clamps_to_short_months_and_returns(): void
    {
        $c = $this->contract(['start_date' => '2027-01-01', 'payment_day' => 31]);
        $periods = $this->calc->project($c, Carbon::parse('2027-01-01'), 5);

        $this->assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'],
            array_map(fn ($p) => $p['due_date']->toDateString(), $periods)
        );
    }

    public function test_december_to_january_rollover(): void
    {
        $c = $this->contract(['start_date' => '2026-12-01']);
        $dec = $this->calc->firstPeriod($c);
        $this->assertPeriod($dec, '2026-12-01', '2026-12-31', '2026-12-01', 250000);
        $this->assertPeriod($this->calc->periodAfter($c, $dec['end']), '2027-01-01', '2027-01-31', '2027-01-01', 250000);
    }

    public function test_mid_month_end_is_a_prorated_closing_stub_and_nothing_after(): void
    {
        // Ends Jun 15: Jun 1–15 = 15 of 30 days → 125000.
        $c = $this->contract(['start_date' => '2026-05-01', 'end_date' => '2026-06-15']);
        $may = $this->calc->firstPeriod($c);
        $jun = $this->calc->periodAfter($c, $may['end']);
        $this->assertPeriod($jun, '2026-06-01', '2026-06-15', '2026-06-01', 125000);
        $this->assertNull($this->calc->periodAfter($c, $jun['end']));
    }

    public function test_opening_stub_due_day_after_start_is_kept(): void
    {
        // Start Jan 10, due day 15 → Jan 15 (inside the stub).
        $c = $this->contract(['start_date' => '2026-01-10', 'payment_day' => 15]);
        $this->assertSame('2026-01-15', $this->calc->firstPeriod($c)['due_date']->toDateString());
    }

    public function test_current_period_uses_the_billing_calendar_month(): void
    {
        $c = $this->contract(['start_date' => '2026-01-10']);

        $this->assertPeriod($this->calc->currentPeriod($c, Carbon::parse('2026-01-20')), '2026-01-10', '2026-01-31', '2026-01-10', 177419);
        $this->assertPeriod($this->calc->currentPeriod($c, Carbon::parse('2026-03-01')), '2026-03-01', '2026-03-31', '2026-03-01', 250000);
        $this->assertNull($this->calc->currentPeriod($c, Carbon::parse('2026-01-09')));
        $this->assertNull($this->calc->currentPeriod($this->contract(['status' => ContractStatus::DRAFT]), Carbon::parse('2026-03-01')));
    }

    public function test_legacy_anniversary_lease_keeps_its_periods_and_full_amount(): void
    {
        $c = $this->contract(['start_date' => '2026-01-10', 'payment_day' => 15, 'billing_anchor' => 'anniversary']);

        $first = $this->calc->firstPeriod($c);
        $this->assertPeriod($first, '2026-01-10', '2026-02-09', '2026-01-15', 250000);
        $this->assertPeriod($this->calc->periodAfter($c, $first['end']), '2026-02-10', '2026-03-09', '2026-02-15', 250000);
        // The scheduled (today-based) path gives the same identity and due
        // date. WYN-015: it used to give Mar 15, after the period had ended.
        $this->assertPeriod($this->calc->currentPeriod($c, Carbon::parse('2026-02-20')), '2026-02-10', '2026-03-09', '2026-02-15', 250000);
    }

    public function test_anniversary_due_day_before_start_day_lands_next_month(): void
    {
        $c = $this->contract(['start_date' => '2026-01-10', 'payment_day' => 5, 'billing_anchor' => 'anniversary']);
        $this->assertSame('2026-02-05', $this->calc->firstPeriod($c)['due_date']->toDateString());
    }

    public function test_proration_rounds_half_up_in_integer_cents(): void
    {
        // 100 × 1/31 = 3.23 → 3; 100 × 16/31 = 51.61 → 52; and an exact
        // half: 250001 × 14/28 = 125000.5 → 125001 (half rounds up).
        $this->assertSame(3, $this->calc->prorate(100, Carbon::parse('2026-01-31'), Carbon::parse('2026-01-31')));
        $this->assertSame(52, $this->calc->prorate(100, Carbon::parse('2026-01-16'), Carbon::parse('2026-01-31')));
        $this->assertSame(125001, $this->calc->prorate(250001, Carbon::parse('2027-02-15'), Carbon::parse('2027-02-28')));
    }
}
