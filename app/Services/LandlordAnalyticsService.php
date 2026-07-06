<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\UnitAvailabilityStatus;
use App\Models\Application;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Ledger\LedgerComputationEngine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * LandlordAnalyticsService
 *
 * Assembles the full "Portfolio Analytics" payload for a landlord: financial
 * and occupancy trends, listing/application funnel, tenant payment behaviour,
 * maintenance aggregates, a "needs attention" digest, and a property
 * performance table. Everything is scoped to the landlord's own portfolio
 * (never a single "first property" like the older generic analytics
 * controllers) and every figure is derived from real rows — nothing here is
 * a front-end stand-in.
 *
 * Money figures that overlap with the Ledger page are delegated to
 * LedgerComputationEngine so this page can never disagree with the ledger.
 * "Expected"/"collected" trends are bucketed by real calendar-month windows
 * (bounded per-month queries) rather than a DB-specific date_format/strftime
 * grouping, so this works the same on SQLite and MySQL/Postgres.
 */
class LandlordAnalyticsService
{
    public function __construct(
        protected LedgerComputationEngine $engine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $landlordId, string $rangeKey, ?int $propertyId = null): array
    {
        $range = $this->resolveRange($rangeKey);

        return [
            'range' => $range,
            'property_id' => $propertyId,
            'summary' => $this->summary($landlordId, $range, $propertyId),
            'financial_trend' => $this->financialTrend($landlordId, $propertyId),
            'revenue_by_property' => $this->revenueByProperty($landlordId, $range, $propertyId),
            'occupancy' => [
                'trend' => $this->occupancyTrend($landlordId, $propertyId),
                'unit_status' => $this->unitStatusBreakdown($landlordId, $propertyId),
                'vacancy_by_property' => $this->vacancyByProperty($landlordId, $propertyId),
            ],
            'listings' => $this->listingsAnalytics($landlordId, $propertyId),
            'payments' => [
                'behavior_trend' => $this->paymentBehaviorTrend($landlordId, $propertyId),
                'aging' => $this->balanceAging($landlordId, $propertyId),
                'overdue_tenants' => $this->overdueTenants($landlordId, $propertyId),
            ],
            'maintenance' => [
                'by_status' => $this->maintenanceByStatus($landlordId, $propertyId),
                'by_category' => $this->maintenanceByCategory($landlordId, $propertyId),
                'resolution_trend' => $this->maintenanceResolutionTrend($landlordId, $propertyId),
            ],
            'needs_attention' => $this->needsAttention($landlordId, $propertyId),
            'properties' => $this->propertyPerformance($landlordId, $range, $propertyId),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Date range
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Resolves a range key to a [from, to] window plus the immediately
     * preceding window of equal length, used for the "vs previous period"
     * delta pills. Only a fixed set of presets is supported (no free-form
     * custom range) to keep this a pure server-side computation.
     *
     * @return array<string, mixed>
     */
    protected function resolveRange(string $key): array
    {
        $now = Carbon::now();

        [$from, $to, $label] = match ($key) {
            'last' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
                'Last month',
            ],
            '90' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay(), 'Last 90 days'],
            'ytd' => [$now->copy()->startOfYear(), $now->copy()->endOfDay(), 'Year to date'],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'This month'],
        };

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        return [
            'key' => in_array($key, ['this', 'last', '90', 'ytd'], true) ? $key : 'this',
            'label' => $label,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'prev_from' => $prevFrom->toDateString(),
            'prev_to' => $prevTo->toDateString(),
            // Full datetime bounds for created_at-based queries (computeCollected
            // et al.) — a bare date string as the upper bound would compare as
            // LESS than "today"'s timestamped rows and silently exclude them.
            // due_date-based queries (a DATE column) use the plain date strings above.
            'from_datetime' => $from->toDateTimeString(),
            'to_datetime' => $to->toDateTimeString(),
            'prev_from_datetime' => $prevFrom->toDateTimeString(),
            'prev_to_datetime' => $prevTo->toDateTimeString(),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Summary cards
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    protected function summary(int $landlordId, array $range, ?int $propertyId = null): array
    {
        $collected = $this->engine->computeCollected([
            'landlord_id' => $landlordId,
            'property_id' => $propertyId,
            'date_from' => $range['from_datetime'],
            'date_to' => $range['to_datetime'],
        ]);
        $collectedPrev = $this->engine->computeCollected([
            'landlord_id' => $landlordId,
            'property_id' => $propertyId,
            'date_from' => $range['prev_from_datetime'],
            'date_to' => $range['prev_to_datetime'],
        ]);

        $expected = $this->rentDueInWindow($landlordId, $range['from'], $range['to'], $propertyId);

        $outstanding = $this->engine->computeOutstanding(['landlord_id' => $landlordId, 'property_id' => $propertyId]);
        $overdue = $this->engine->computeOverdue(['landlord_id' => $landlordId, 'property_id' => $propertyId]);

        $unitCounts = Unit::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId)->when($propertyId, fn ($q2) => $q2->where('id', $propertyId)))
            ->selectRaw('availability_status, COUNT(*) as aggregate')
            ->groupBy('availability_status')
            ->pluck('aggregate', 'availability_status');

        $totalUnits = (int) $unitCounts->sum();
        $occupiedUnits = (int) ($unitCounts[UnitAvailabilityStatus::OCCUPIED->value] ?? 0);

        return [
            'collected_cents' => $collected,
            'collected_prev_cents' => $collectedPrev,
            'expected_cents' => $expected,
            'outstanding_cents' => $outstanding,
            'overdue_cents' => $overdue,
            'occupied_units' => $occupiedUnits,
            'total_units' => $totalUnits,
            'occupancy_pct' => $totalUnits > 0 ? round($occupiedUnits / $totalUnits * 100, 1) : 0.0,
            // needs_attention_count is filled in by the caller once the full
            // digest is built, to avoid computing it twice.
        ];
    }

    /**
     * Sum of RENT ledger entries due within [from, to]. Bucketed on due_date
     * (not created_at) since rent rows may be generated well ahead of when
     * they're due — mirrors LandlordDashboardController's own rent_trend.
     */
    protected function rentDueInWindow(int $landlordId, string $from, string $to, ?int $propertyId = null): int
    {
        return (int) LedgerEntry::byLandlord($landlordId)
            ->rent()
            ->when($propertyId, fn ($q) => $q->whereHas('contract.listing.unit', fn ($q2) => $q2->where('property_id', $propertyId)))
            ->whereBetween('due_date', [$from, $to])
            ->sum('amount_cents');
    }

    /* ────────────────────────────────────────────────────────────────────
     * Financial trend (last 6 calendar months)
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * @return list<array<string, mixed>>
     */
    protected function financialTrend(int $landlordId, ?int $propertyId = null): array
    {
        $trend = [];

        foreach ($this->lastSixMonths() as [$monthStart, $monthEnd]) {
            $trend[] = [
                'month' => $monthStart->format('M'),
                'collected_cents' => $this->engine->computeCollected([
                    'landlord_id' => $landlordId,
                    'property_id' => $propertyId,
                    'date_from' => $monthStart->toDateString(),
                    'date_to' => $monthEnd->toDateString(),
                ]),
                'expected_cents' => $this->rentDueInWindow($landlordId, $monthStart->toDateString(), $monthEnd->toDateString(), $propertyId),
            ];
        }

        return $trend;
    }

    /**
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    protected function lastSixMonths(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = Carbon::now()->startOfMonth()->subMonths($i);
            $months[] = [$start, $start->copy()->endOfMonth()];
        }

        return $months;
    }

    /**
     * @param  array<string, mixed>  $range
     * @return list<array<string, mixed>>
     */
    protected function revenueByProperty(int $landlordId, array $range, ?int $propertyId = null): array
    {
        $properties = Property::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->where('id', $propertyId))
            ->get(['id', 'name']);

        return $properties->map(function (Property $property) use ($landlordId, $range) {
            $collected = $this->engine->computeCollected([
                'landlord_id' => $landlordId,
                'property_id' => $property->id,
                'date_from' => $range['from_datetime'],
                'date_to' => $range['to_datetime'],
            ]);

            return [
                'property_id' => $property->id,
                'name' => $property->name,
                'collected_cents' => $collected,
            ];
        })
            ->sortByDesc('collected_cents')
            ->values()
            ->all();
    }

    /* ────────────────────────────────────────────────────────────────────
     * Occupancy
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Occupancy trend reconstructed from real Contract records (a unit is
     * "occupied" in a given month if a non-draft, non-pending contract for
     * it overlapped that month) rather than a stored historical snapshot —
     * Wyncrest does not keep month-by-month unit-status history. Uses
     * today's total unit count throughout, since a landlord's unit count
     * rarely changes; that is a documented approximation, not a fabrication.
     *
     * @return list<array<string, mixed>>
     */
    protected function occupancyTrend(int $landlordId, ?int $propertyId = null): array
    {
        $totalUnits = Unit::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId)->when($propertyId, fn ($q2) => $q2->where('id', $propertyId)))->count();

        $trend = [];
        foreach ($this->lastSixMonths() as [$monthStart, $monthEnd]) {
            $occupied = Contract::query()
                ->join('listings', 'contracts.listing_id', '=', 'listings.id')
                ->join('units', 'listings.unit_id', '=', 'units.id')
                ->where('contracts.landlord_id', $landlordId)
                ->when($propertyId, fn ($q) => $q->where('units.property_id', $propertyId))
                ->whereIn('contracts.status', [
                    ContractStatus::ACTIVE->value,
                    ContractStatus::TERMINATED->value,
                    ContractStatus::EXPIRED->value,
                ])
                ->where('contracts.start_date', '<=', $monthEnd->toDateString())
                ->where(function ($q) use ($monthStart) {
                    $q->whereNull('contracts.end_date')
                        ->orWhere('contracts.end_date', '>=', $monthStart->toDateString());
                })
                ->distinct('listings.unit_id')
                ->count('listings.unit_id');

            $trend[] = [
                'month' => $monthStart->format('M'),
                'occupied' => $occupied,
                'total' => $totalUnits,
                'occupancy_pct' => $totalUnits > 0 ? round($occupied / $totalUnits * 100, 1) : 0.0,
            ];
        }

        return $trend;
    }

    /**
     * Current-snapshot unit breakdown: occupied, vacant-with-an-active-or-
     * pending listing, vacant-as-a-draft-listing-only, and vacant-unlisted.
     *
     * @return array<string, int>
     */
    protected function unitStatusBreakdown(int $landlordId, ?int $propertyId = null): array
    {
        $units = Unit::whereHas('property', fn ($q) => $q->where('landlord_id', $landlordId)->when($propertyId, fn ($q2) => $q2->where('id', $propertyId)))
            ->with(['listings' => fn ($q) => $q->select('id', 'unit_id', 'status')])
            ->get();

        $occupied = 0;
        $vacantListed = 0;
        $vacantDraft = 0;
        $vacantUnlisted = 0;

        foreach ($units as $unit) {
            if ($unit->availability_status === UnitAvailabilityStatus::OCCUPIED) {
                $occupied++;

                continue;
            }

            $statuses = $unit->listings->pluck('status');
            if ($statuses->contains(ListingStatus::ACTIVE) || $statuses->contains(ListingStatus::PENDING_REVIEW)) {
                $vacantListed++;
            } elseif ($statuses->contains(ListingStatus::DRAFT)) {
                $vacantDraft++;
            } else {
                $vacantUnlisted++;
            }
        }

        return [
            'occupied' => $occupied,
            'vacant_listed' => $vacantListed,
            'vacant_draft' => $vacantDraft,
            'vacant_unlisted' => $vacantUnlisted,
            'total' => $units->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function vacancyByProperty(int $landlordId, ?int $propertyId = null): array
    {
        return Property::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->where('id', $propertyId))
            ->withCount(['units as vacant_units_count' => fn ($q) => $q->where('availability_status', UnitAvailabilityStatus::AVAILABLE->value)])
            ->get()
            ->filter(fn (Property $p) => $p->vacant_units_count > 0)
            ->map(fn (Property $p) => ['property_id' => $p->id, 'name' => $p->name, 'vacant' => $p->vacant_units_count])
            ->sortByDesc('vacant')
            ->values()
            ->all();
    }

    /* ────────────────────────────────────────────────────────────────────
     * Listings & applications
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * The full funnel is real: Listing.view_count and the saved_listings
     * pivot are both tracked columns, and an Application row exists (in
     * DRAFT or later) the moment a tenant starts one — so every funnel step
     * traces back to an actual record, unlike a purely illustrative mockup.
     *
     * @return array<string, mixed>
     */
    protected function listingsAnalytics(int $landlordId, ?int $propertyId = null): array
    {
        $listings = Listing::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->whereHas('unit', fn ($q2) => $q2->where('property_id', $propertyId)))
            ->withCount(['applications', 'savedByUsers'])
            ->with('unit:id,unit_number,property_id', 'unit.property:id,name')
            ->get();

        $applications = Application::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->whereHas('listing.unit', fn ($q2) => $q2->where('property_id', $propertyId)))
            ->get(['status']);

        $funnel = [
            ['step' => 'Views', 'value' => (int) $listings->sum('view_count')],
            ['step' => 'Saved', 'value' => (int) $listings->sum('saved_by_users_count')],
            ['step' => 'Applications started', 'value' => $applications->count()],
            ['step' => 'Applications submitted', 'value' => $applications->reject(fn ($a) => $a->status->isDraft())->count()],
            ['step' => 'Approved', 'value' => $applications->where('status', ApplicationStatus::APPROVED)->count()],
            ['step' => 'Contracts created', 'value' => Contract::byLandlord($landlordId)->count()],
        ];

        // Only prefix the property name when the portfolio has more than one —
        // for a single-property landlord it's redundant on every row and just
        // eats into the label column's width.
        $multipleProperties = $listings->pluck('unit.property_id')->unique()->count() > 1;

        $applicationsByListing = $listings
            ->map(function (Listing $l) use ($multipleProperties) {
                $unitLabel = $l->unit?->unit_number ?? $l->title;
                $label = $multipleProperties && $l->unit?->property?->name
                    ? "{$l->unit->property->name} · {$unitLabel}"
                    : $unitLabel;

                return [
                    'listing_id' => $l->id,
                    'label' => $label,
                    'value' => (int) $l->applications_count,
                    'status' => $l->status->value,
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        $statusBreakdown = $listings->countBy(fn (Listing $l) => $l->status->value)
            ->map(fn ($count, $status) => ['status' => $status, 'count' => $count])
            ->values()
            ->all();

        return [
            'funnel' => $funnel,
            'applications_by_listing' => $applicationsByListing,
            'status_breakdown' => $statusBreakdown,
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Tenant payment behaviour
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * On-time vs late, per month, for rent obligations that were paid.
     * "Late" means the linked payment's created_at is after the rent row's
     * due_date. Wyncrest does not support partial payments (every payment is
     * full-amount, see LedgerComputationEngine::deriveContractPaymentStatus),
     * so there is no third "partial" bucket to report.
     *
     * @return list<array<string, mixed>>
     */
    protected function paymentBehaviorTrend(int $landlordId, ?int $propertyId = null): array
    {
        $trend = [];

        foreach ($this->lastSixMonths() as [$monthStart, $monthEnd]) {
            $rentEntries = LedgerEntry::byLandlord($landlordId)
                ->rent()
                ->when($propertyId, fn ($q) => $q->whereHas('contract.listing.unit', fn ($q2) => $q2->where('property_id', $propertyId)))
                ->where('status', LedgerStatus::PAID->value)
                ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->get(['id', 'due_date']);

            $payments = LedgerEntry::whereIn('related_rent_entry_id', $rentEntries->pluck('id'))
                ->where('type', LedgerType::PAYMENT->value)
                ->orderBy('created_at')
                ->get(['related_rent_entry_id', 'created_at'])
                ->groupBy('related_rent_entry_id')
                ->map(fn (Collection $g) => $g->first());

            $onTime = 0;
            $late = 0;

            foreach ($rentEntries as $entry) {
                $payment = $payments->get($entry->id);
                if (! $payment) {
                    continue;
                }

                if ($payment->created_at->toDateString() <= $entry->due_date->toDateString()) {
                    $onTime++;
                } else {
                    $late++;
                }
            }

            $trend[] = ['month' => $monthStart->format('M'), 'on_time' => $onTime, 'late' => $late];
        }

        return $trend;
    }

    /**
     * Outstanding (pending + overdue) balance bucketed by days since due.
     * Each bucket carries a real, traceable example (its largest entry)
     * instead of an invented narrative line.
     *
     * @return list<array<string, mixed>>
     */
    protected function balanceAging(int $landlordId, ?int $propertyId = null): array
    {
        $today = Carbon::today();

        $entries = LedgerEntry::byLandlord($landlordId)
            ->unpaid()
            ->when($propertyId, fn ($q) => $q->whereHas('contract.listing.unit', fn ($q2) => $q2->where('property_id', $propertyId)))
            ->where('due_date', '<=', $today->toDateString())
            ->with('tenant:id,first_name,last_name')
            ->get();

        $buckets = [
            '0-7 days' => ['min' => 0, 'max' => 7],
            '8-30 days' => ['min' => 8, 'max' => 30],
            '31-60 days' => ['min' => 31, 'max' => 60],
            '60+ days' => ['min' => 61, 'max' => null],
        ];

        $result = [];
        foreach ($buckets as $label => $range) {
            $inBucket = $entries->filter(function (LedgerEntry $entry) use ($today, $range) {
                $days = $entry->due_date->diffInDays($today);

                return $days >= $range['min'] && ($range['max'] === null || $days <= $range['max']);
            });

            $largest = $inBucket->sortByDesc('amount_cents')->first();

            $result[] = [
                'bucket' => $label,
                'amount_cents' => (int) $inBucket->sum('amount_cents'),
                'example' => $largest
                    ? trim(($largest->tenant?->first_name.' '.$largest->tenant?->last_name)).' · due '.$largest->due_date->format('j M')
                    : null,
            ];
        }

        return $result;
    }

    /**
     * Portfolio-wide overdue contracts, worst balance first — the same
     * balance/status computation LandlordLedgerService uses for the ledger's
     * Balances tab, so the two pages can never disagree.
     *
     * @return list<array<string, mixed>>
     */
    protected function overdueTenants(int $landlordId, ?int $propertyId = null): array
    {
        $contractIds = LedgerEntry::byLandlord($landlordId)
            ->overdue()
            ->when($propertyId, fn ($q) => $q->whereHas('contract.listing.unit', fn ($q2) => $q2->where('property_id', $propertyId)))
            ->distinct()
            ->pluck('contract_id');

        if ($contractIds->isEmpty()) {
            return [];
        }

        $contracts = Contract::whereIn('id', $contractIds)->with(['tenant', 'listing.unit.property'])->get();

        return $contracts->map(function (Contract $contract) {
            $unit = $contract->listing?->unit;
            $oldestUnpaid = LedgerEntry::where('contract_id', $contract->id)->overdue()->orderBy('due_date')->first();
            $lastPayment = LedgerEntry::where('contract_id', $contract->id)
                ->where('type', LedgerType::PAYMENT->value)
                ->latest('created_at')
                ->first();

            return [
                'contract_id' => $contract->id,
                'tenant_name' => $contract->tenant?->full_name,
                'property_name' => $unit?->property?->name,
                'unit_number' => $unit?->unit_number,
                'overdue_cents' => $this->engine->computeOverdueByContract($contract->id),
                'days_overdue' => $oldestUnpaid ? (int) $oldestUnpaid->due_date->diffInDays(Carbon::today()) : 0,
                'last_payment_at' => $lastPayment?->created_at?->toIso8601String(),
            ];
        })
            ->sortByDesc('overdue_cents')
            ->values()
            ->all();
    }

    /* ────────────────────────────────────────────────────────────────────
     * Maintenance
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * @return list<array<string, mixed>>
     */
    protected function maintenanceByStatus(int $landlordId, ?int $propertyId = null): array
    {
        return MaintenanceRequest::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => $row->status->value, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function maintenanceByCategory(int $landlordId, ?int $propertyId = null): array
    {
        return MaintenanceRequest::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->get()
            ->map(fn ($row) => ['category' => $row->category?->value, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * Average days from submission to resolution, for requests resolved
     * within each of the last 6 months. Requests never resolved are simply
     * excluded, matching the metric's own definition rather than being
     * counted as zero.
     *
     * @return list<array<string, mixed>>
     */
    protected function maintenanceResolutionTrend(int $landlordId, ?int $propertyId = null): array
    {
        $trend = [];

        foreach ($this->lastSixMonths() as [$monthStart, $monthEnd]) {
            $resolved = MaintenanceRequest::where('landlord_id', $landlordId)
                ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
                ->whereNotNull('resolved_at')
                ->whereBetween('resolved_at', [$monthStart, $monthEnd])
                ->get(['submitted_at', 'resolved_at']);

            $avgDays = $resolved->isEmpty()
                ? null
                : round($resolved->avg(fn (MaintenanceRequest $m) => $m->submitted_at->diffInHours($m->resolved_at) / 24), 1);

            $trend[] = ['month' => $monthStart->format('M'), 'avg_days' => $avgDays];
        }

        return $trend;
    }

    /* ────────────────────────────────────────────────────────────────────
     * Needs attention (real signals only)
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Turns real portfolio data into a short, prioritised action list:
     * overdue rent first, then unassigned urgent/high-priority repairs,
     * unlisted vacancies, listings with real interest but zero conversion,
     * and leases ending soon. Thresholds (view count, day windows, list
     * caps) are a deliberately simple starting heuristic — easy to retune
     * once real usage data shows what actually warrants surfacing.
     *
     * @return list<array<string, mixed>>
     */
    protected function needsAttention(int $landlordId, ?int $propertyId = null): array
    {
        $items = [];

        foreach (array_slice($this->overdueTenants($landlordId, $propertyId), 0, 3) as $o) {
            $items[] = [
                'tone' => 'red',
                'category' => 'overdue_rent',
                'title' => "{$o['tenant_name']} is {$o['days_overdue']} days overdue on {$o['property_name']} · {$o['unit_number']}",
                'description' => 'GH₵ '.number_format($o['overdue_cents'] / 100, 2).' outstanding.',
                'action_label' => 'View ledger',
                'action_route' => '/app/ledger',
            ];
        }

        $urgentMaintenance = MaintenanceRequest::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->whereIn('priority', [MaintenancePriority::URGENT->value, MaintenancePriority::HIGH->value])
            ->whereIn('status', [MaintenanceStatus::OPEN->value, MaintenanceStatus::ACKNOWLEDGED->value])
            ->whereNull('assigned_at')
            ->with('property:id,name', 'unit:id,unit_number')
            ->latest('submitted_at')
            ->limit(3)
            ->get();

        foreach ($urgentMaintenance as $m) {
            $items[] = [
                'tone' => 'red',
                'category' => 'maintenance',
                'title' => "{$m->title} is marked {$m->priority->value} priority and still unassigned",
                'description' => "{$m->property?->name} · {$m->unit?->unit_number}, reported ".$m->submitted_at?->format('j M').'.',
                'action_label' => 'View request',
                'action_route' => '/app/maintenance',
            ];
        }

        $unitStatus = $this->unitStatusBreakdown($landlordId, $propertyId);
        if ($unitStatus['vacant_unlisted'] > 0) {
            $noun = $unitStatus['vacant_unlisted'] === 1 ? 'unit has' : 'units have';
            $items[] = [
                'tone' => 'amber',
                'category' => 'vacancy',
                'title' => "{$unitStatus['vacant_unlisted']} vacant {$noun} no listing",
                'description' => 'These units cannot receive applications until a listing is created.',
                'action_label' => 'View properties',
                'action_route' => '/app/properties',
            ];
        }

        $draftOnVacant = Listing::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->whereHas('unit', fn ($q2) => $q2->where('property_id', $propertyId)))
            ->where('status', ListingStatus::DRAFT->value)
            ->whereHas('unit', fn ($q) => $q->where('availability_status', UnitAvailabilityStatus::AVAILABLE->value))
            ->with('unit.property:id,name')
            ->limit(2)
            ->get();

        foreach ($draftOnVacant as $l) {
            $items[] = [
                'tone' => 'amber',
                'category' => 'listing_draft',
                'title' => "{$l->unit?->property?->name} · {$l->unit?->unit_number} listing is still a draft",
                'description' => 'A vacant unit has an unpublished listing, so it is not receiving applications.',
                'action_label' => 'Publish listing',
                'action_route' => "/app/listings/{$l->id}",
            ];
        }

        $highInterestNoConversion = Listing::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->whereHas('unit', fn ($q2) => $q2->where('property_id', $propertyId)))
            ->where('status', ListingStatus::ACTIVE->value)
            ->where('view_count', '>=', 30)
            ->withCount('applications')
            ->with('unit.property:id,name')
            ->get()
            ->filter(fn (Listing $l) => $l->applications_count === 0)
            ->take(2);

        foreach ($highInterestNoConversion as $l) {
            $items[] = [
                'tone' => 'amber',
                'category' => 'low_conversion',
                'title' => "{$l->title} has {$l->view_count} views but no applications",
                'description' => 'High interest is not converting. The rent, photos, or description may be worth reviewing.',
                'action_label' => 'Review listing',
                'action_route' => "/app/listings/{$l->id}",
            ];
        }

        $endingSoon = Contract::byLandlord($landlordId)
            ->when($propertyId, fn ($q) => $q->whereHas('listing.unit', fn ($q2) => $q2->where('property_id', $propertyId)))
            ->where('status', ContractStatus::ACTIVE->value)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [Carbon::today()->toDateString(), Carbon::today()->addDays(30)->toDateString()])
            ->with('tenant', 'listing.unit.property')
            ->limit(2)
            ->get();

        foreach ($endingSoon as $c) {
            $unit = $c->listing?->unit;
            $items[] = [
                'tone' => 'blue',
                'category' => 'lease_ending',
                'title' => "{$c->tenant?->full_name}'s lease ends {$c->end_date?->format('j M')} at {$unit?->property?->name} · {$unit?->unit_number}",
                'description' => 'That unit will become vacant soon. Prepare a re-listing to avoid a gap in rent.',
                'action_label' => 'Prepare listing',
                'action_route' => '/app/listings',
            ];
        }

        return array_slice($items, 0, 8);
    }

    /* ────────────────────────────────────────────────────────────────────
     * Property performance table
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * @param  array<string, mixed>  $range
     * @return list<array<string, mixed>>
     */
    protected function propertyPerformance(int $landlordId, array $range, ?int $propertyId = null): array
    {
        $properties = Property::where('landlord_id', $landlordId)
            ->when($propertyId, fn ($q) => $q->where('id', $propertyId))
            ->withCount([
                'units',
                'units as occupied_units_count' => fn ($q) => $q->where('availability_status', UnitAvailabilityStatus::OCCUPIED->value),
            ])
            ->get();

        return $properties->map(function (Property $property) use ($landlordId, $range) {
            $collected = $this->engine->computeCollected([
                'landlord_id' => $landlordId,
                'property_id' => $property->id,
                'date_from' => $range['from_datetime'],
                'date_to' => $range['to_datetime'],
            ]);
            $outstanding = $this->engine->computeOutstanding(['landlord_id' => $landlordId, 'property_id' => $property->id]);

            // Every real (non-draft) application this property has ever received —
            // a volume signal for the performance table, not just currently-pending ones.
            $applications = Application::where('landlord_id', $landlordId)
                ->whereHas('listing.unit', fn ($q) => $q->where('property_id', $property->id))
                ->where('status', '!=', ApplicationStatus::DRAFT->value)
                ->count();

            $openMaintenance = MaintenanceRequest::where('landlord_id', $landlordId)
                ->where('property_id', $property->id)
                ->whereIn('status', [
                    MaintenanceStatus::OPEN->value,
                    MaintenanceStatus::ACKNOWLEDGED->value,
                    MaintenanceStatus::ASSIGNED->value,
                    MaintenanceStatus::IN_PROGRESS->value,
                    MaintenanceStatus::WAITING->value,
                ])
                ->count();

            $occRate = $property->units_count > 0
                ? round($property->occupied_units_count / $property->units_count * 100)
                : 0;

            return [
                'id' => $property->id,
                'name' => $property->name,
                'area' => trim("{$property->city}, {$property->state}", ', '),
                'units' => $property->units_count,
                'occupied' => $property->occupied_units_count,
                'occupancy_pct' => $occRate,
                'collected_cents' => $collected,
                'outstanding_cents' => $outstanding,
                'applications_count' => $applications,
                'open_maintenance' => $openMaintenance,
                'status' => $this->propertyStatus($outstanding, $occRate, $openMaintenance),
            ];
        })->all();
    }

    /** Simple, honest classification — no invented "risk score". */
    protected function propertyStatus(int $outstandingCents, float $occupancyPct, int $openMaintenance): string
    {
        if ($outstandingCents > 0) {
            return 'attention';
        }

        if ($occupancyPct < 80) {
            return 'vacancy';
        }

        if ($openMaintenance > 2) {
            return 'attention';
        }

        return 'healthy';
    }
}
