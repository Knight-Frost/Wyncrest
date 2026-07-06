<?php

namespace App\Services\Ledger;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Services\LedgerAutomationService;
use Carbon\Carbon;

/**
 * LedgerReconciliationService
 *
 * Independent integrity checks over the immutable ledger, built on top of
 * LedgerComputationEngine. This is what catches a math regression (bad
 * sign, duplicate charge, orphaned entry) before it reaches an admin's
 * screen — run it via `php artisan ledger:reconcile`, or call run()
 * directly from a test or the admin ledger integrity endpoint.
 *
 * Each check is independent and additive: a failure in one does not skip
 * the others, so a single run surfaces every issue at once.
 */
class LedgerReconciliationService
{
    public function __construct(
        protected LedgerComputationEngine $engine,
        protected LedgerAutomationService $automation,
    ) {}

    /**
     * Run every check and return a structured report.
     *
     * @return array{status: string, issues: array, summary: array}
     */
    public function run(array $filters = []): array
    {
        $issues = [];

        $issues = array_merge($issues, $this->checkSignRules($filters));
        $issues = array_merge($issues, $this->checkCollectedNeverNegative($filters));
        $issues = array_merge($issues, $this->checkOverdueBounds($filters));
        $issues = array_merge($issues, $this->checkDuplicateRentPeriods($filters));
        $issues = array_merge($issues, $this->checkDuplicatePayments($filters));
        $issues = array_merge($issues, $this->checkDuplicateLateFees($filters));
        $issues = array_merge($issues, $this->checkOrphanEntries());
        $issues = array_merge($issues, $this->checkPaidWithoutPayment($filters));
        $issues = array_merge($issues, $this->checkOutstandingMatchesSignedSum($filters));
        $issues = array_merge($issues, $this->checkMissingRentGeneration());

        $hasFail = collect($issues)->contains(fn ($i) => $i['severity'] === 'fail');
        $hasWarning = collect($issues)->contains(fn ($i) => $i['severity'] === 'warning');

        $status = $hasFail ? 'fail' : ($hasWarning ? 'warning' : 'pass');

        return [
            'status' => $status,
            'issues' => $issues,
            'summary' => $this->engine->computePlatformFinancialSummary($filters),
        ];
    }

    /**
     * Rule 1: sign rules.
     * rent/late_fee must be > 0. payment must be < 0. amount_cents must
     * never be zero (a zero-amount entry is meaningless and almost
     * certainly a bug).
     */
    protected function checkSignRules(array $filters): array
    {
        $issues = [];

        $badCharges = $this->engine->applyFilters(
            LedgerEntry::whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value]),
            $filters
        )->where('amount_cents', '<=', 0)->pluck('id');

        if ($badCharges->isNotEmpty()) {
            $issues[] = $this->issue(
                'fail',
                'sign_rule_charge_non_positive',
                'Rent or late fee entries have a non-positive amount_cents (should always be > 0).',
                entryIds: $badCharges->all(),
            );
        }

        $badPayments = $this->engine->applyFilters(
            LedgerEntry::where('type', LedgerType::PAYMENT),
            $filters
        )->where('amount_cents', '>=', 0)->pluck('id');

        if ($badPayments->isNotEmpty()) {
            $issues[] = $this->issue(
                'fail',
                'sign_rule_payment_non_negative',
                'Payment entries have a non-negative amount_cents (should always be < 0, the canonical convention for money received).',
                entryIds: $badPayments->all(),
            );
        }

        return $issues;
    }

    /**
     * Rule 3: collected amount must be positive in reporting. Structurally
     * guaranteed by computeCollected()'s abs(), but re-verified here as a
     * defense-in-depth check in case that invariant is ever broken.
     */
    protected function checkCollectedNeverNegative(array $filters): array
    {
        $collected = $this->engine->computeCollected($filters);

        if ($collected < 0) {
            return [$this->issue(
                'fail',
                'collected_negative',
                'Collected amount computed as negative — this must never happen.',
                expected: '>= 0',
                actual: (string) $collected,
            )];
        }

        return [];
    }

    /**
     * Rule 4/5: overdue math must only include unpaid, past-due entries;
     * an OVERDUE-status entry with a future due_date is a contradiction
     * (the mark-overdue job should never do this, but a manual factory/seed
     * mistake could).
     */
    protected function checkOverdueBounds(array $filters): array
    {
        $today = Carbon::today()->toDateString();

        $badOverdue = $this->engine->applyFilters(
            LedgerEntry::where('status', LedgerStatus::OVERDUE),
            $filters
        )->whereDate('due_date', '>=', $today)->pluck('id');

        if ($badOverdue->isNotEmpty()) {
            return [$this->issue(
                'fail',
                'overdue_not_past_due',
                'Entries are marked OVERDUE but their due date has not passed.',
                entryIds: $badOverdue->all(),
            )];
        }

        return [];
    }

    /**
     * Rule 6a: no two RENT entries for the same contract + billing period.
     */
    protected function checkDuplicateRentPeriods(array $filters): array
    {
        $dupes = $this->engine->applyFilters(LedgerEntry::where('type', LedgerType::RENT), $filters)
            ->selectRaw('contract_id, billing_period_start, billing_period_end, COUNT(*) as c')
            ->groupBy('contract_id', 'billing_period_start', 'billing_period_end')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($dupes->isEmpty()) {
            return [];
        }

        return [$this->issue(
            'fail',
            'duplicate_rent_period',
            'Multiple rent charges exist for the same contract and billing period.',
            contractIds: $dupes->pluck('contract_id')->unique()->values()->all(),
        )];
    }

    /**
     * Rule 6b: no two PAYMENT entries share the same Stripe payment intent
     * id (idempotency of webhook processing).
     */
    protected function checkDuplicatePayments(array $filters): array
    {
        $dupes = $this->engine->applyFilters(LedgerEntry::where('type', LedgerType::PAYMENT), $filters)
            ->whereNotNull('stripe_payment_intent_id')
            ->selectRaw('stripe_payment_intent_id, COUNT(*) as c')
            ->groupBy('stripe_payment_intent_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('stripe_payment_intent_id');

        if ($dupes->isEmpty()) {
            return [];
        }

        return [$this->issue(
            'fail',
            'duplicate_payment_intent',
            'Multiple payment entries share the same Stripe payment intent id (double-credit risk).',
            metadata: ['stripe_payment_intent_ids' => $dupes->all()],
        )];
    }

    /**
     * Rule 6c: at most one LATE_FEE per rent entry (also enforced at
     * creation time by LedgerService::generateLateFee, this re-verifies).
     */
    protected function checkDuplicateLateFees(array $filters): array
    {
        $dupes = $this->engine->applyFilters(LedgerEntry::where('type', LedgerType::LATE_FEE), $filters)
            ->whereNotNull('related_rent_entry_id')
            ->selectRaw('related_rent_entry_id, COUNT(*) as c')
            ->groupBy('related_rent_entry_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('related_rent_entry_id');

        if ($dupes->isEmpty()) {
            return [];
        }

        return [$this->issue(
            'fail',
            'duplicate_late_fee',
            'Multiple late fee entries reference the same rent entry.',
            entryIds: $dupes->all(),
        )];
    }

    /**
     * Rule 9 (partial): entries pointing at a contract that no longer
     * exists. Ledger entries cascade-delete with their contract in the
     * schema, so this should be structurally impossible — flagged as a
     * warning (not fail) since it indicates schema drift, not live money
     * being miscounted.
     */
    protected function checkOrphanEntries(): array
    {
        $orphans = LedgerEntry::whereDoesntHave('contract')->pluck('id');

        if ($orphans->isEmpty()) {
            return [];
        }

        return [$this->issue(
            'warning',
            'orphan_entries',
            'Ledger entries exist without a valid contract.',
            entryIds: $orphans->all(),
        )];
    }

    /**
     * Rule 7: a PAID rent/late_fee obligation should have at least one
     * linked PAYMENT entry, unless it was WAIVED. A PAID obligation with no
     * payment behind it means status was set without money actually moving.
     */
    protected function checkPaidWithoutPayment(array $filters): array
    {
        $paidObligations = $this->engine->applyFilters(
            LedgerEntry::whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
                ->where('status', LedgerStatus::PAID),
            $filters
        )->get();

        $missing = $paidObligations->filter(function (LedgerEntry $entry) {
            return ! LedgerEntry::where('related_rent_entry_id', $entry->id)
                ->where('type', LedgerType::PAYMENT)
                ->exists();
        })->pluck('id');

        if ($missing->isEmpty()) {
            return [];
        }

        return [$this->issue(
            'warning',
            'paid_without_payment_entry',
            'Entries are marked PAID but have no linked PAYMENT entry.',
            entryIds: $missing->all(),
        )];
    }

    /**
     * Cross-check: outstanding (from the fast aggregate query) must match
     * the sum of balance impacts for currently-unpaid obligations (from the
     * per-entry engine method). These are two independently-written code
     * paths over the same data — if they ever disagree, something in the
     * engine has drifted.
     */
    protected function checkOutstandingMatchesSignedSum(array $filters): array
    {
        $fast = $this->engine->computeOutstanding($filters);

        $unpaid = $this->engine->applyFilters(LedgerEntry::query()->unpaid(), $filters)->get();
        $recomputed = (int) $unpaid->sum(fn (LedgerEntry $e) => $this->engine->balanceImpactCents($e));

        if ($fast !== $recomputed) {
            return [$this->issue(
                'fail',
                'outstanding_mismatch',
                'Aggregate outstanding total does not match the sum of individually-recomputed balance impacts.',
                expected: (string) $recomputed,
                actual: (string) $fast,
            )];
        }

        return [];
    }

    /**
     * Rule 10: every active contract should have a RENT entry for its
     * current billing period (per LedgerAutomationService::getCurrentBillingPeriod,
     * the same logic the scheduled rent-generation job uses). A contract with
     * no rent entry for the period it's currently in means generation was
     * skipped — a `warning`, since it's a gap rather than bad money already
     * counted.
     */
    protected function checkMissingRentGeneration(): array
    {
        $missing = [];

        Contract::where('status', ContractStatus::ACTIVE)->each(function (Contract $contract) use (&$missing) {
            $period = $this->automation->getCurrentBillingPeriod($contract);

            if ($period && ! $this->automation->rentExistsForPeriod($contract, $period['start'], $period['end'])) {
                $missing[] = $contract->id;
            }
        });

        if (empty($missing)) {
            return [];
        }

        return [$this->issue(
            'warning',
            'missing_rent_generation',
            'Active contracts have no rent charge generated for their current billing period.',
            contractIds: $missing,
        )];
    }

    protected function issue(
        string $severity,
        string $code,
        string $message,
        array $entryIds = [],
        array $contractIds = [],
        ?string $expected = null,
        ?string $actual = null,
        array $metadata = [],
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'entry_ids' => $entryIds,
            'contract_ids' => $contractIds,
            'expected' => $expected,
            'actual' => $actual,
            'metadata' => $metadata,
        ];
    }
}
