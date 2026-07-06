<?php

namespace App\Services;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Services\Ledger\LedgerComputationEngine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * LandlordLedgerService
 *
 * Assembles the read-only projections the landlord Rent Ledger console needs
 * that go beyond a flat list of entries: the KPI summary (this-month collected
 * / charged plus all-time outstanding / overdue), the per-contract "Balances"
 * rollup, and the tenant/property "Statement" documents.
 *
 * Every money figure is delegated to LedgerComputationEngine — this service
 * never sums amount_cents itself, so the landlord page can never disagree with
 * the admin ledger or the tenant balance. The ledger stays immutable; nothing
 * here mutates a row.
 */
class LandlordLedgerService
{
    public function __construct(
        protected LedgerComputationEngine $engine
    ) {}

    /**
     * KPI cards for the ledger header. `tenants_overdue` is supplied by the
     * caller (derived from the balances rollup) so we don't re-scan the
     * ledger for a count we already computed there.
     *
     * @return array<string,mixed>
     */
    public function summary(int $landlordId, ?int $tenantsOverdue = null): array
    {
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $monthFilters = [
            'landlord_id' => $landlordId,
            'date_from' => $monthStart->toDateTimeString(),
            'date_to' => $monthEnd->toDateTimeString(),
        ];

        return [
            'outstanding_cents' => $this->engine->computeOutstanding(['landlord_id' => $landlordId]),
            'overdue_cents' => $this->engine->computeOverdue(['landlord_id' => $landlordId]),
            'collected_month_cents' => $this->engine->computeCollected($monthFilters),
            'charged_month_cents' => $this->engine->computeRentCharged($monthFilters)
                + $this->engine->computeFeesCharged($monthFilters),
            'tenants_overdue' => $tenantsOverdue ?? 0,
            'month_label' => $monthStart->format('F Y'),
        ];
    }

    /**
     * Per-contract balance rollup for the "Balances" tab. Scoped to contracts
     * that actually carry ledger activity for this landlord, so every row maps
     * to real money.
     *
     * @return array<int,array<string,mixed>>
     */
    public function balances(int $landlordId): array
    {
        $contractIds = LedgerEntry::byLandlord($landlordId)
            ->distinct()
            ->pluck('contract_id')
            ->all();

        if (empty($contractIds)) {
            return [];
        }

        $contracts = Contract::whereIn('id', $contractIds)
            ->with(['tenant', 'listing.unit.property'])
            ->get();

        return $contracts
            ->map(fn (Contract $contract) => $this->balanceRow($contract))
            ->sortBy(fn (array $row) => [$this->statusRank($row['status']), -$row['balance_cents']])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function balanceRow(Contract $contract): array
    {
        $unit = $contract->listing?->unit;
        $property = $unit?->property;
        $tenant = $contract->tenant;

        $nextDue = LedgerEntry::where('contract_id', $contract->id)
            ->unpaid()
            ->orderBy('due_date')
            ->value('due_date');

        $lastPayment = LedgerEntry::where('contract_id', $contract->id)
            ->where('type', LedgerType::PAYMENT)
            ->where('status', LedgerStatus::PAID)
            ->orderByDesc('created_at')
            ->value('created_at');

        return [
            'contract_id' => $contract->id,
            'contract_status' => $contract->status->value,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'full_name' => $tenant->full_name,
                'email' => $tenant->email,
            ] : null,
            'property' => $property ? [
                'id' => $property->id,
                'name' => $property->name,
                'city' => $property->city,
                'state' => $property->state,
            ] : null,
            'unit_number' => $unit?->unit_number,
            'rent_cents' => (int) $contract->rent_amount,
            'payment_day' => $contract->payment_day,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'balance_cents' => $this->engine->computeContractBalance($contract->id),
            'outstanding_cents' => $this->engine->computeOutstandingByContract($contract->id),
            'overdue_cents' => $this->engine->computeOverdueByContract($contract->id),
            // 'paid' | 'overdue' | 'open' | 'no_history'
            'status' => $this->engine->deriveContractPaymentStatus($contract->id),
            'next_due' => $nextDue?->toDateString(),
            'last_payment_at' => $lastPayment?->toIso8601String(),
        ];
    }

    /**
     * Sort weight: overdue first, then still-owing, then settled.
     */
    protected function statusRank(string $status): int
    {
        return match ($status) {
            'overdue' => 0,
            'open' => 1,
            'paid' => 2,
            default => 3,
        };
    }

    /**
     * Tenant / contract statement for one billing month — the account
     * movement (opening → charges/fees/payments → ending) plus the entries
     * that produced it. Adjustments are always 0: Wyncrest has no landlord
     * adjustment entry type.
     *
     * @return array<string,mixed>
     */
    public function contractStatement(Contract $contract, int $year, int $month): array
    {
        $history = LedgerEntry::where('contract_id', $contract->id)
            ->with('relatedRentEntry')
            ->get();

        $balances = $this->engine->computeRunningBalances($history);

        $sorted = $history->sort(function (LedgerEntry $a, LedgerEntry $b) {
            return [$a->created_at, (string) $a->id] <=> [$b->created_at, (string) $b->id];
        })->values();

        $inPeriod = $sorted->filter(
            fn (LedgerEntry $e) => $e->created_at?->year === $year && $e->created_at?->month === $month
        )->values();

        if ($inPeriod->isNotEmpty()) {
            $first = $inPeriod->first();
            $last = $inPeriod->last();
            $opening = ($balances[$first->id] ?? 0) - $this->engine->balanceImpactCents($first);
            $ending = $balances[$last->id] ?? 0;
        } else {
            $opening = $ending = $this->engine->computeContractBalance($contract->id);
        }

        $sumImpact = fn (Collection $rows) => (int) $rows->sum(fn (LedgerEntry $e) => $this->engine->balanceImpactCents($e));

        $unit = $contract->listing?->unit;
        $property = $unit?->property;
        $tenant = $contract->tenant;
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();

        return [
            'contract' => [
                'id' => $contract->id,
                'status' => $contract->status->value,
                'rent_cents' => (int) $contract->rent_amount,
                'payment_day' => $contract->payment_day,
                'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
                'currency' => $contract->currency,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'full_name' => $tenant->full_name,
                'email' => $tenant->email,
            ] : null,
            'property' => $property ? ['id' => $property->id, 'name' => $property->name, 'city' => $property->city] : null,
            'unit_number' => $unit?->unit_number,
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $periodStart->format('F Y'),
                'start' => $periodStart->toDateString(),
                'end' => $periodStart->copy()->endOfMonth()->toDateString(),
            ],
            'opening_cents' => $opening,
            'charges_cents' => $sumImpact($inPeriod->filter(fn (LedgerEntry $e) => $e->type === LedgerType::RENT)),
            'fees_cents' => $sumImpact($inPeriod->filter(fn (LedgerEntry $e) => $e->type === LedgerType::LATE_FEE)),
            'payments_cents' => $sumImpact($inPeriod->filter(fn (LedgerEntry $e) => $e->type === LedgerType::PAYMENT)),
            'adjustments_cents' => 0,
            'ending_cents' => $ending,
            'entries' => $inPeriod
                ->map(fn (LedgerEntry $e) => $this->engine->decorateEntry($e, $balances[$e->id] ?? null))
                ->values()
                ->all(),
        ];
    }

    /**
     * Property statement — money by property, broken down by unit/contract,
     * for one billing month.
     *
     * @return array<string,mixed>
     */
    public function propertyStatement(Property $property, int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $monthRange = [
            'date_from' => $monthStart->toDateTimeString(),
            'date_to' => $monthEnd->toDateTimeString(),
        ];
        $propertyFilter = ['property_id' => $property->id];

        $contracts = Contract::where('landlord_id', $property->landlord_id)
            ->whereHas('ledgerEntries')
            ->whereHas('listing.unit', fn ($q) => $q->where('property_id', $property->id))
            ->with(['tenant', 'listing.unit'])
            ->get();

        $units = $contracts->map(function (Contract $contract) use ($monthRange) {
            $unit = $contract->listing?->unit;
            $tenant = $contract->tenant;

            return [
                'contract_id' => $contract->id,
                'unit_number' => $unit?->unit_number,
                'tenant' => $tenant ? ['id' => $tenant->id, 'full_name' => $tenant->full_name] : null,
                'rent_cents' => (int) $contract->rent_amount,
                'paid_month_cents' => $this->engine->computeCollected(array_merge(
                    ['contract_id' => $contract->id],
                    $monthRange
                )),
                'balance_cents' => $this->engine->computeContractBalance($contract->id),
                'status' => $this->engine->deriveContractPaymentStatus($contract->id),
            ];
        })->values()->all();

        return [
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'city' => $property->city,
                'state' => $property->state,
            ],
            'period' => [
                'year' => $year,
                'month' => $month,
                'label' => $monthStart->format('F Y'),
            ],
            'unit_count' => count($units),
            'charged_month_cents' => $this->engine->computeRentCharged(array_merge($propertyFilter, $monthRange))
                + $this->engine->computeFeesCharged(array_merge($propertyFilter, $monthRange)),
            'collected_month_cents' => $this->engine->computeCollected(array_merge($propertyFilter, $monthRange)),
            'outstanding_cents' => $this->engine->computeOutstanding($propertyFilter),
            'overdue_cents' => $this->engine->computeOverdue($propertyFilter),
            'units' => $units,
        ];
    }
}
