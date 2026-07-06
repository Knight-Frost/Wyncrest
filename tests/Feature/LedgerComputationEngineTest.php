<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\Ledger\LedgerReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LedgerComputationEngineTest
 *
 * Proves the financial math the admin ledger page, dashboards, and tenant
 * balances all now share. Each scenario mirrors the acceptance criteria for
 * the "ledger accounting correctness" fix: negative sign bugs, ambiguous
 * cards, and scattered competing calculations must not recur.
 */
class LedgerComputationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected LedgerComputationEngine $engine;

    protected LedgerReconciliationService $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(LedgerComputationEngine::class);
        $this->reconciliation = app(LedgerReconciliationService::class);
    }

    private function contract(): Contract
    {
        // start_date pinned safely in the future so getCurrentBillingPeriod()
        // never resolves a current period for these contracts — this file's
        // tests build ledger entries directly and don't exercise the
        // missing-rent-generation reconciliation check (covered separately
        // in AdminAnalyticsOverviewTest), so a stray current-period match
        // would just be incidental flakiness from the factory's random
        // start_date.
        return Contract::factory()->active()->create(['start_date' => now()->addMonths(2)]);
    }

    private function rent(Contract $contract, int $amountCents, array $overrides = []): LedgerEntry
    {
        return LedgerEntry::factory()->create(array_merge([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'amount_cents' => $amountCents,
            'status' => LedgerStatus::PENDING,
        ], $overrides));
    }

    private function payment(Contract $contract, LedgerEntry $obligation, int $amountCents, array $overrides = []): LedgerEntry
    {
        return LedgerEntry::factory()->create(array_merge([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => -abs($amountCents),
            'status' => LedgerStatus::PAID,
            'related_rent_entry_id' => $obligation->id,
        ], $overrides));
    }

    // ── 1. Simple paid rent ────────────────────────────────────────────

    public function test_simple_paid_rent(): void
    {
        $contract = $this->contract();
        $rent = $this->rent($contract, 600_000, ['status' => LedgerStatus::PAID, 'due_date' => now()->subDays(3)]);
        $payment = $this->payment($contract, $rent, 600_000);
        // Real flow: the RENT entry flips to PAID once the payment lands.
        $rent = LedgerEntry::find($rent->id);

        $this->assertSame(0, $this->engine->computeContractBalance($contract->id));
        $this->assertSame(600_000, $this->engine->computeCollected(['contract_id' => $contract->id]));
        $this->assertSame(0, $this->engine->computeOutstandingByContract($contract->id));
        $this->assertSame(0, $this->engine->computeOverdueByContract($contract->id));

        // Payment display amount is positive; balance impact is negative.
        $this->assertSame(600_000, $this->engine->displayAmountCents($payment));
        $this->assertSame(-600_000, $this->engine->balanceImpactCents($payment));
        $this->assertSame(-600_000, $this->engine->signedAmountCents($payment));
    }

    // ── 2. Unpaid overdue rent ──────────────────────────────────────────

    public function test_unpaid_overdue_rent(): void
    {
        $contract = $this->contract();
        $this->rent($contract, 250_000, ['status' => LedgerStatus::OVERDUE, 'due_date' => now()->subDays(10)]);

        $this->assertSame(250_000, $this->engine->computeOutstandingByContract($contract->id));
        $this->assertSame(250_000, $this->engine->computeOverdueByContract($contract->id));
        $this->assertSame(0, $this->engine->computeDueSoonByContract($contract->id));
        $this->assertSame(0, $this->engine->computeCollected(['contract_id' => $contract->id]));
    }

    // ── 3. Future unpaid rent ───────────────────────────────────────────

    public function test_future_unpaid_rent(): void
    {
        $contract = $this->contract();
        $this->rent($contract, 300_000, ['status' => LedgerStatus::PENDING, 'due_date' => now()->addDays(10)]);

        $this->assertSame(300_000, $this->engine->computeOutstandingByContract($contract->id));
        $this->assertSame(0, $this->engine->computeOverdueByContract($contract->id));
        $this->assertSame(300_000, $this->engine->computeDueSoonByContract($contract->id));
    }

    // ── 4. Partial payment (payment linked to a still-open obligation) ──

    public function test_partial_payment_nets_against_outstanding_and_overdue(): void
    {
        $contract = $this->contract();
        $rent = $this->rent($contract, 600_000, ['status' => LedgerStatus::PENDING, 'due_date' => now()->addDays(5)]);
        $this->payment($contract, $rent, 250_000);

        $this->assertSame(350_000, $this->engine->computeOutstandingByContract($contract->id));
        $this->assertSame(250_000, $this->engine->computeCollected(['contract_id' => $contract->id]));
        $this->assertSame(0, $this->engine->computeOverdueByContract($contract->id), 'not yet due');

        // Same obligation, but now overdue — the partial payment must still net out.
        $rent2 = $this->rent($contract, 600_000, ['status' => LedgerStatus::OVERDUE, 'due_date' => now()->subDays(2)]);
        $this->payment($contract, $rent2, 250_000);

        $this->assertSame(700_000, $this->engine->computeOutstandingByContract($contract->id), '350k + 350k across both obligations');
        $this->assertSame(350_000, $this->engine->computeOverdueByContract($contract->id));
    }

    // ── 5. Multiple contracts — platform totals equal sum of contract totals ──

    public function test_multiple_contracts_platform_totals_equal_sum_of_contracts(): void
    {
        $a = $this->contract();
        $b = $this->contract();

        $this->rent($a, 100_000, ['status' => LedgerStatus::OVERDUE, 'due_date' => now()->subDay()]);
        $this->rent($b, 200_000, ['status' => LedgerStatus::PENDING, 'due_date' => now()->addDay()]);

        $expectedOutstanding = $this->engine->computeOutstandingByContract($a->id) + $this->engine->computeOutstandingByContract($b->id);
        $this->assertSame($expectedOutstanding, $this->engine->computeOutstanding());
        $this->assertSame(300_000, $this->engine->computeOutstanding());
    }

    // ── 6. Multiple payments — collected totals are positive and correct ──

    public function test_multiple_payments_collected_is_positive_and_summed(): void
    {
        $contract = $this->contract();
        $r1 = $this->rent($contract, 100_000, ['status' => LedgerStatus::PAID]);
        $r2 = $this->rent($contract, 150_000, ['status' => LedgerStatus::PAID]);
        $this->payment($contract, $r1, 100_000);
        $this->payment($contract, $r2, 150_000);

        $this->assertSame(250_000, $this->engine->computeCollected(['contract_id' => $contract->id]));
    }

    // ── 7 & 8. Payment sign display / no negative collected card ────────

    public function test_payment_sign_display_and_collected_card_is_never_negative(): void
    {
        $contract = $this->contract();
        $rent = $this->rent($contract, 600_000, ['status' => LedgerStatus::PAID]);
        $payment = $this->payment($contract, $rent, 600_000);

        $this->assertLessThan(0, $payment->fresh()->amount_cents, 'raw signed amount is negative');
        $this->assertSame(600_000, $this->engine->displayAmountCents($payment->fresh()));
        $this->assertGreaterThanOrEqual(0, $this->engine->computeCollected());
        $this->assertGreaterThanOrEqual(0, $this->engine->computePlatformFinancialSummary()['collected_cents']);
    }

    // ── 9. Duplicate rent generation is blocked (service-level, re-verified here) ──

    public function test_duplicate_rent_period_is_flagged_by_reconciliation(): void
    {
        $contract = $this->contract();
        $start = now()->startOfMonth();
        $end = $start->copy()->addMonth()->subDay();

        $this->rent($contract, 100_000, ['billing_period_start' => $start, 'billing_period_end' => $end]);
        $this->rent($contract, 100_000, ['billing_period_start' => $start, 'billing_period_end' => $end]);

        $report = $this->reconciliation->run();
        $codes = collect($report['issues'])->pluck('code');

        $this->assertSame('fail', $report['status']);
        $this->assertTrue($codes->contains('duplicate_rent_period'));
    }

    // ── 9b. Missing rent generation for the current billing period ──────

    public function test_missing_rent_generation_is_flagged_by_reconciliation(): void
    {
        $contract = Contract::factory()->active()->create(['start_date' => now()->subMonths(2)]);

        $report = $this->reconciliation->run();
        $codes = collect($report['issues'])->pluck('code');

        $this->assertTrue($codes->contains('missing_rent_generation'));
    }

    public function test_missing_rent_generation_not_flagged_when_current_period_rent_exists(): void
    {
        $contract = Contract::factory()->active()->create(['start_date' => now()->subMonths(2)]);

        app(\App\Services\LedgerAutomationService::class)->generateRentForContract($contract);

        $report = $this->reconciliation->run();
        $codes = collect($report['issues'])->pluck('code');

        $this->assertFalse($codes->contains('missing_rent_generation'));
    }

    // ── 10. Duplicate payment (same Stripe intent) is blocked ───────────

    public function test_duplicate_payment_intent_is_flagged_by_reconciliation(): void
    {
        // A `ledger_entries_payment_intent_unique` partial unique index (added
        // after this reconciliation check existed) now prevents this exact
        // duplicate at the DB level on sqlite/pgsql. It has NO equivalent on
        // MySQL (no partial index support there — see that index's own
        // migration), so this reconciliation check remains real defense for
        // that driver. Drop the index for this one test only (it lives inside
        // RefreshDatabase's per-test transaction, so it is restored
        // automatically afterwards) to exercise the app-level detection
        // independent of the DB constraint.
        DB::statement('DROP INDEX IF EXISTS ledger_entries_payment_intent_unique');

        $contract = $this->contract();
        $rent = $this->rent($contract, 100_000, ['status' => LedgerStatus::PAID]);
        $this->payment($contract, $rent, 100_000, ['stripe_payment_intent_id' => 'pi_dup_123']);
        $this->payment($contract, $rent, 100_000, ['stripe_payment_intent_id' => 'pi_dup_123']);

        $report = $this->reconciliation->run();
        $codes = collect($report['issues'])->pluck('code');

        $this->assertTrue($codes->contains('duplicate_payment_intent'));
    }

    // ── 11. Late fee math ────────────────────────────────────────────────

    public function test_late_fee_increases_balance_and_paying_it_reduces_balance(): void
    {
        $contract = $this->contract();
        $lateFee = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::LATE_FEE,
            'amount_cents' => 20_000,
            'status' => LedgerStatus::PENDING,
        ]);

        $this->assertSame(20_000, $this->engine->computeContractBalance($contract->id));

        $lateFee->transitionStatus(LedgerStatus::PAID);
        $this->payment($contract, $lateFee, 20_000);

        $this->assertSame(0, $this->engine->computeContractBalance($contract->id));
    }

    // ── 12. Corrections: original entries stay immutable, compensating entries net out ──

    public function test_compensating_entry_corrects_balance_without_mutating_the_original(): void
    {
        $contract = $this->contract();
        $rent = $this->rent($contract, 500_000, ['status' => LedgerStatus::PENDING]);

        // A billing mistake is corrected via a compensating refund-style
        // entry rather than editing the original (which is impossible —
        // LedgerEntry::update() throws).
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::REFUND,
            'amount_cents' => -100_000,
            'status' => LedgerStatus::PAID,
            'related_rent_entry_id' => $rent->id,
        ]);

        $this->assertSame(500_000, $rent->fresh()->amount_cents, 'original entry is untouched');
        $this->assertSame(400_000, $this->engine->computeContractBalance($contract->id));
    }

    // ── 13. Running balance is correct and deterministic ─────────────────

    public function test_running_balance_is_correct_and_deterministic(): void
    {
        $contract = $this->contract();
        $rent = $this->rent($contract, 300_000, ['created_at' => now()->subDays(5)]);
        $fee = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::LATE_FEE,
            'amount_cents' => 15_000,
            'status' => LedgerStatus::PENDING,
            'created_at' => now()->subDays(3),
        ]);
        $payment = $this->payment($contract, $rent, 300_000, ['created_at' => now()->subDay()]);

        $entries = LedgerEntry::where('contract_id', $contract->id)->get();
        $balances = $this->engine->computeRunningBalances($entries);

        $this->assertSame(300_000, $balances[$rent->id]);
        $this->assertSame(315_000, $balances[$fee->id]);
        $this->assertSame(15_000, $balances[$payment->id]);
    }

    // ── 14. Status derivation ────────────────────────────────────────────

    public function test_contract_payment_status_derivation(): void
    {
        $noHistory = $this->contract();
        $this->assertSame('no_history', $this->engine->deriveContractPaymentStatus($noHistory->id));

        $overdue = $this->contract();
        $this->rent($overdue, 100_000, ['status' => LedgerStatus::OVERDUE, 'due_date' => now()->subDay()]);
        $this->assertSame('overdue', $this->engine->deriveContractPaymentStatus($overdue->id));

        $open = $this->contract();
        $this->rent($open, 100_000, ['status' => LedgerStatus::PENDING, 'due_date' => now()->addDay()]);
        $this->assertSame('open', $this->engine->deriveContractPaymentStatus($open->id));

        $paid = $this->contract();
        $rent = $this->rent($paid, 100_000, ['status' => LedgerStatus::PAID]);
        $this->payment($paid, $rent, 100_000);
        $this->assertSame('paid', $this->engine->deriveContractPaymentStatus($paid->id));
    }

    // ── 15. Reconciliation pass on a clean ledger ────────────────────────

    public function test_reconciliation_passes_on_a_clean_ledger(): void
    {
        $contract = $this->contract();
        $rent = $this->rent($contract, 100_000, ['status' => LedgerStatus::PAID]);
        $this->payment($contract, $rent, 100_000);

        $report = $this->reconciliation->run();

        $this->assertSame('pass', $report['status']);
        $this->assertSame([], $report['issues']);
    }

    // ── 16. Reconciliation fails on a bad sign ───────────────────────────

    public function test_reconciliation_fails_on_bad_sign(): void
    {
        $contract = $this->contract();
        // A payment stored with the wrong (positive) sign — exactly the bug
        // this whole system was built to catch.
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => 50_000,
            'status' => LedgerStatus::PAID,
        ]);

        $report = $this->reconciliation->run();
        $codes = collect($report['issues'])->pluck('code');

        $this->assertSame('fail', $report['status']);
        $this->assertTrue($codes->contains('sign_rule_payment_non_negative'));
    }

    // ── 17. Date boundary tests ──────────────────────────────────────────

    public function test_due_today_is_not_overdue_due_yesterday_is(): void
    {
        $contract = $this->contract();
        $dueToday = $this->rent($contract, 100_000, ['status' => LedgerStatus::PENDING, 'due_date' => now()->startOfDay()]);
        $dueYesterday = $this->rent($contract, 200_000, ['status' => LedgerStatus::PENDING, 'due_date' => now()->subDay()]);

        // Due today (not yet past) must not be counted as overdue.
        $overdueOnly = $this->engine->computeOverdueByContract($contract->id);
        $this->assertSame(200_000, $overdueOnly, 'only the entry due yesterday is overdue');
    }

    // ── 18. Currency precision: integer cents throughout ─────────────────

    public function test_all_amounts_are_integer_cents(): void
    {
        $contract = $this->contract();
        $rent = $this->rent($contract, 333_333);

        $this->assertIsInt($this->engine->displayAmountCents($rent));
        $this->assertIsInt($this->engine->balanceImpactCents($rent));
        $this->assertIsInt($this->engine->computeOutstandingByContract($contract->id));
        $this->assertIsInt($this->engine->computePlatformFinancialSummary()['outstanding_cents']);
    }

    // ── Regression: the exact reported bug ───────────────────────────────

    /**
     * The reported bug: "Total Collected" displayed as a negative number on
     * the admin ledger page. Root cause was twofold — (1) the frontend
     * summed raw signed amount_cents across mixed entry types over only the
     * current paginated page, and (2) LedgerPresentationService double-
     * negated PAYMENT entries. This proves the platform-wide summary is
     * correct and non-negative regardless of how entries are distributed
     * across pages.
     */
    public function test_collected_is_never_negative_even_with_many_payment_heavy_pages(): void
    {
        $contract = $this->contract();

        // Simulate a page that, under the old client-side summation bug,
        // would have gone deeply negative: lots of PAID payments and only a
        // couple of RENT charges.
        for ($i = 0; $i < 10; $i++) {
            $rent = $this->rent($contract, 50_000, ['status' => LedgerStatus::PAID]);
            $this->payment($contract, $rent, 50_000);
        }

        $summary = $this->engine->computePlatformFinancialSummary();

        $this->assertSame(500_000, $summary['collected_cents']);
        $this->assertGreaterThanOrEqual(0, $summary['collected_cents']);
    }
}
