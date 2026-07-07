<?php

namespace App\Services\Analytics;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerComputationEngine;
use Illuminate\Support\Facades\DB;

/**
 * FinancialAnalyticsService
 *
 * Phase 4.0b: Audit-grade, read-only financial analytics.
 *
 * ACTUAL SCHEMA:
 * - Ledger-only system (no separate payments table)
 * - Status-based: pending, paid, overdue, waived
 * - Types: rent, late_fee
 * - Amount stored in cents
 * - Contracts use listing_id (not unit_id)
 *
 * Financial truth (outstanding balance, collected, sign convention) is delegated
 * to LedgerComputationEngine so this module can never disagree with the ledger
 * page/dashboards. The revenue metrics remain accrual-style and intentionally
 * separate (see getRevenueMetrics()).
 */
class FinancialAnalyticsService
{
    public function __construct(
        protected LedgerComputationEngine $engine
    ) {}

    /**
     * Translate this module's filter keys into the shape
     * LedgerComputationEngine::applyFilters() expects.
     */
    protected function engineFilters(array $filters): array
    {
        $mapped = [];

        if (isset($filters['start_date'])) {
            $mapped['date_from'] = $filters['start_date'];
        }
        if (isset($filters['end_date'])) {
            $mapped['date_to'] = $filters['end_date'];
        }
        if (isset($filters['user_id'])) {
            $mapped['tenant_id'] = $filters['user_id'];
        }
        if (isset($filters['property_id'])) {
            $mapped['property_id'] = $filters['property_id'];
        }

        return $mapped;
    }

    /**
     * Get complete financial analytics
     */
    public function getAnalytics(array $filters = []): array
    {
        return [
            'revenue' => $this->getRevenueMetrics($filters),
            'outstanding' => $this->getOutstandingMetrics($filters),
            'ledger_integrity' => $this->getLedgerIntegrityMetrics($filters),
        ];
    }

    /**
     * A. Revenue Metrics
     */
    public function getRevenueMetrics(array $filters = []): array
    {
        $query = LedgerEntry::query();
        $this->applyFilters($query, $filters);

        // Total rent generated (all rent entries)
        $totalRentGenerated = (clone $query)
            ->where('type', LedgerType::RENT)
            ->sum('amount_cents') / 100;

        // NOTE on naming: despite the field name, this is rent RECOGNIZED as
        // paid (RENT entries with status=PAID), an accrual-style figure —
        // not a sum of PAYMENT-type ledger entries. It intentionally does
        // NOT use LedgerComputationEngine::computeCollected(), which is the
        // cash-basis "money actually received" figure shown on the ledger
        // page/dashboards. Left as its own long-standing, separately-tested
        // metric rather than renamed/merged, to avoid an unrelated behavior
        // change to this analytics module during the ledger-page fix.
        $totalPaymentsReceived = (clone $query)
            ->where('type', LedgerType::RENT)
            ->where('ledger_entries.status', LedgerStatus::PAID)
            ->sum('amount_cents') / 100;

        // Total waived (waived entries - acts like refunds)
        $totalWaived = (clone $query)
            ->where('status', LedgerStatus::WAIVED)
            ->sum('amount_cents') / 100;

        // Net revenue
        $netRevenue = $totalPaymentsReceived - $totalWaived;

        // Revenue by month (paid entries)
        $revenueByMonth = $this->getRevenueByMonth($filters);

        // Revenue by property (paid entries)
        $revenueByProperty = $this->getRevenueByProperty($filters);

        return [
            'total_rent_generated' => (float) $totalRentGenerated,
            'total_payments_received' => (float) $totalPaymentsReceived,
            'total_waived' => (float) $totalWaived,
            'net_revenue' => (float) $netRevenue,
            'revenue_by_month' => $revenueByMonth,
            'revenue_by_property' => $revenueByProperty,
        ];
    }

    /**
     * B. Outstanding & Overdue Metrics
     */
    public function getOutstandingMetrics(array $filters = []): array
    {
        $query = LedgerEntry::query();
        $this->applyFilters($query, $filters);

        // Total outstanding: ALL still-unpaid obligations (pending + overdue,
        // net of any linked payments). Delegated to the engine so this figure
        // agrees with the ledger page — the old "pending only" sum understated
        // the balance by excluding overdue obligations.
        $totalOutstanding = $this->engine->computeOutstanding($this->engineFilters($filters)) / 100;

        // Total overdue: obligations explicitly in OVERDUE status. Kept as a
        // status-based figure (a subset of the engine's overdue definition,
        // which also treats pending-past-due as overdue) so it stays
        // deterministic; documented as a minor, intentional divergence.
        $totalOverdue = (clone $query)
            ->where('status', LedgerStatus::OVERDUE)
            ->sum('amount_cents') / 100;

        // Total rent for rate calculation
        $totalRent = (clone $query)
            ->where('type', LedgerType::RENT)
            ->sum('amount_cents') / 100;

        $overdueRate = $totalRent > 0 ? ($totalOverdue / $totalRent) * 100 : 0;

        // Tenants with overdue balance
        $tenantsWithOverdue = (clone $query)
            ->where('status', LedgerStatus::OVERDUE)
            ->distinct('tenant_id')
            ->count('tenant_id');

        // Average days overdue (for currently overdue entries)
        $averageDaysOverdue = $this->getAverageDaysOverdue($filters);

        return [
            'total_outstanding_balance' => (float) $totalOutstanding,
            'total_overdue_amount' => (float) $totalOverdue,
            'overdue_rate_percentage' => (float) round($overdueRate, 2),
            'tenants_with_overdue_balance' => (int) $tenantsWithOverdue,
            'average_days_overdue' => (float) $averageDaysOverdue,
        ];
    }

    /**
     * C. Ledger Integrity Metrics
     */
    public function getLedgerIntegrityMetrics(array $filters = []): array
    {
        $query = LedgerEntry::query();
        $this->applyFilters($query, $filters);

        // Ledger balance sum (raw signed sum = net balance; already sign-correct).
        $ledgerBalanceSum = (clone $query)->sum('amount_cents') / 100;

        // Sign-rule violations = genuinely corrupt entries. Under the canonical
        // convention (LedgerComputationEngine) obligations (rent/late_fee) are
        // stored POSITIVE and payments NEGATIVE, so a corrupt entry is a
        // rent/late_fee with a negative amount OR a payment with a positive
        // amount. The old check counted every amount_cents < 0, which flagged
        // every legitimate payment as an anomaly.
        $negativeBalancesCount = (clone $query)
            ->where(function ($q) {
                $q->where(function ($obligation) {
                    $obligation->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
                        ->where('amount_cents', '<', 0);
                })->orWhere(function ($payment) {
                    $payment->where('type', LedgerType::PAYMENT->value)
                        ->where('amount_cents', '>', 0);
                });
            })
            ->count();

        // Orphan entries (entries without valid contract)
        $orphanEntries = LedgerEntry::query()
            ->whereDoesntHave('contract')
            ->count();

        // Balance mismatch: total (non-waived) charges minus what was collected
        // must equal what is still outstanding. Computed in cents against the
        // engine's sign-aware collected/outstanding figures so legitimate
        // negative payment entries net correctly (the old check summed
        // status=PAID entries, mixing positive paid-rent with negative payments
        // and false-triggering once real payments existed).
        $chargesNonWaived = (int) (clone $query)
            ->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
            ->where('status', '!=', LedgerStatus::WAIVED->value)
            ->sum('amount_cents');
        $collected = $this->engine->computeCollected($this->engineFilters($filters));
        $outstanding = $this->engine->computeOutstanding($this->engineFilters($filters));

        $balanceMismatch = ($chargesNonWaived - $collected - $outstanding) !== 0;

        return [
            'ledger_balance_sum' => (float) $ledgerBalanceSum,
            'negative_balances_count' => (int) $negativeBalancesCount,
            'orphan_ledger_entries' => (int) $orphanEntries,
            'balance_mismatch_detected' => (bool) $balanceMismatch,
        ];
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['user_id'])) {
            $query->where('tenant_id', $filters['user_id']);
        }

        if (isset($filters['property_id'])) {
            $query->whereHas('contract.listing.unit', function ($q) use ($filters) {
                $q->where('property_id', $filters['property_id']);
            });
        }
    }

    /**
     * Helper: Revenue by month
     */
    protected function getRevenueByMonth(array $filters = []): array
    {
        $query = LedgerEntry::query()
            ->where('type', LedgerType::RENT)
            ->where('ledger_entries.status', LedgerStatus::PAID);

        $this->applyFilters($query, $filters);

        // SQLite-compatible date formatting
        $results = $query->select(
            DB::raw("strftime('%Y-%m', created_at) as month"),
            DB::raw('SUM(amount_cents) as total_cents')
        )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $output = [];
        foreach ($results as $result) {
            $output[$result->month] = (float) ($result->total_cents / 100);
        }

        return $output;
    }

    /**
     * Helper: Revenue by property
     */
    protected function getRevenueByProperty(array $filters = []): array
    {
        $query = LedgerEntry::query()
            ->where('type', LedgerType::RENT)
            ->where('ledger_entries.status', LedgerStatus::PAID)
            ->join('contracts', 'ledger_entries.contract_id', '=', 'contracts.id')
            ->join('listings', 'contracts.listing_id', '=', 'listings.id')
            ->join('units', 'listings.unit_id', '=', 'units.id')
            ->join('properties', 'units.property_id', '=', 'properties.id');

        if (isset($filters['start_date'])) {
            $query->where('ledger_entries.created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('ledger_entries.created_at', '<=', $filters['end_date']);
        }

        $results = $query->select(
            'properties.id',
            'properties.name',
            DB::raw('SUM(ledger_entries.amount_cents) as total_cents')
        )
            ->groupBy('properties.id', 'properties.name')
            ->get();

        $output = [];
        foreach ($results as $result) {
            $output[$result->name] = (float) ($result->total_cents / 100);
        }

        return $output;
    }

    /**
     * Helper: Average days overdue
     */
    protected function getAverageDaysOverdue(array $filters = []): float
    {
        $query = LedgerEntry::query()
            ->where('status', LedgerStatus::OVERDUE);

        $this->applyFilters($query, $filters);

        $entries = $query->get();

        if ($entries->isEmpty()) {
            return 0;
        }

        $totalDays = 0;
        $count = 0;

        foreach ($entries as $entry) {
            if ($entry->due_date) {
                $daysOverdue = $entry->due_date->diffInDays(now(), false);
                if ($daysOverdue > 0) {
                    $totalDays += $daysOverdue;
                    $count++;
                }
            }
        }

        return $count > 0 ? round($totalDays / $count, 2) : 0;
    }
}
