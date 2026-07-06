<?php

namespace App\Services\Analytics;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Admin\MaintenanceOverviewService;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\Ledger\LedgerReconciliationService;
use App\Services\ListingReviewService;
use App\Services\VerificationCaseService;
use App\Support\Audit\AuditClassifier;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SuperAdminAnalyticsService
 *
 * Assembles the composite payload for the Super Admin "Platform Analytics"
 * page. This is a READ-ONLY aggregator: every figure is either delegated to
 * an existing single-source-of-truth service (LedgerComputationEngine for
 * money, LedgerReconciliationService for ledger integrity,
 * VerificationCaseService/ListingReviewService/MaintenanceOverviewService for
 * their domains, and the existing Financial/Contract/Notification/Platform
 * analytics services) or computed here from real columns for gaps those
 * services don't cover (rent collection timing, aging buckets, per-landlord
 * risk, application funnel, admin activity, a cross-domain risk register).
 *
 * Nothing here invents a metric the schema can't support. Where the mockup
 * this page is built from asked for something the data model genuinely does
 * not track (partial/disputed payments — Wyncrest payments are always
 * full-amount; structured listing-rejection-reason categories — reasons are
 * free text; API error/slow-endpoint telemetry — no APM exists), the field is
 * simply omitted rather than fabricated.
 */
class SuperAdminAnalyticsService
{
    /** A verification/application case older than this (hours) counts as backlogged. */
    private const VERIFICATION_OVERDUE_HOURS = 72;

    public function __construct(
        private readonly LedgerComputationEngine $ledger,
        private readonly LedgerReconciliationService $reconciliation,
        private readonly FinancialAnalyticsService $financial,
        private readonly ContractAnalyticsService $contracts,
        private readonly NotificationAnalyticsService $notifications,
        private readonly PlatformAnalyticsService $platform,
        private readonly ApplicationAnalyticsService $applications,
        private readonly VerificationCaseService $verifications,
        private readonly ListingReviewService $listingReview,
        private readonly MaintenanceOverviewService $maintenance,
    ) {}

    /**
     * @param  array{date_from?:Carbon,date_to?:Carbon}  $filters
     */
    public function getAnalytics(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $ledgerFilters = array_filter([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
        $legacyFilters = array_filter([
            'start_date' => $dateFrom,
            'end_date' => $dateTo,
        ]);

        $overdueCases = $this->ledger->overdueCases();
        $reconciliation = $this->reconciliation->run($ledgerFilters);
        $verificationSummary = $this->verifications->summary();
        $verificationTiming = $this->verifications->reviewTimingMetrics();
        $listingCounts = $this->listingReview->counts();
        $maintenanceSummary = $this->maintenance->summary();
        $applicationAnalytics = $this->applications->getAnalytics($ledgerFilters);

        return [
            'generated_at' => now()->toIso8601String(),
            'overview' => $this->overview($overdueCases, $verificationSummary, $verificationTiming, $listingCounts, $maintenanceSummary, $applicationAnalytics),
            'financial' => $this->financialSection($ledgerFilters),
            'ledger_integrity' => $this->ledgerIntegritySection($reconciliation),
            'rent_collection' => $this->rentCollectionSection($ledgerFilters, $overdueCases),
            'users' => $this->usersSection($dateFrom, $dateTo),
            'listings' => array_merge(
                ['by_status' => $this->listingsByStatus(), 'average_approval_time_hours' => $this->averageListingApprovalHours()],
                $this->platform->getUtilizationMetrics(),
                ['occupancy' => $this->platform->getOccupancyMetrics()],
            ),
            'contracts' => $this->contracts->getAnalytics($legacyFilters),
            'applications' => $applicationAnalytics,
            'verifications' => array_merge($verificationSummary, $verificationTiming),
            'maintenance' => array_merge($maintenanceSummary, $this->maintenance->analytics($ledgerFilters)),
            'notifications' => $this->notifications->getAnalytics($legacyFilters),
            'admin_activity' => $this->adminActivitySection($dateFrom, $dateTo),
            'risk' => $this->riskRegister($reconciliation, $overdueCases, $maintenanceSummary, $verificationTiming, $applicationAnalytics),
            'system_health' => $this->systemHealthSection(),
            'exports' => $this->exportsSection(),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Overview
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * The "right now" health-card explanatory sub-metrics are deliberately
     * anchored to the calendar (this month / next 30 days), not the
     * analytics page's arbitrary date-range filter — a card reading "3
     * joined this month" would be confusing if it silently meant "this
     * quarter" whenever a wider range was selected.
     */
    protected function overview($overdueCases, array $verificationSummary, array $verificationTiming, array $listingCounts, array $maintenanceSummary, array $applicationAnalytics): array
    {
        $monthStart = Carbon::now()->startOfMonth();
        $today = Carbon::today();

        return [
            'landlords' => User::landlords()->active()->count(),
            'tenants' => User::tenants()->active()->count(),
            'admins' => Admin::where('is_active', true)->count(),
            'properties' => \App\Models\Property::count(),
            'units' => \App\Models\Unit::count(),
            'active_listings' => (int) ($listingCounts['approved'] ?? 0),
            'pending_listings' => (int) ($listingCounts['pending'] ?? 0),
            'active_contracts' => \App\Models\Contract::where('status', \App\Enums\ContractStatus::ACTIVE)->count(),
            'open_applications' => $applicationAnalytics['in_review'] ?? 0,
            'pending_verifications' => (int) $verificationSummary['pending'],
            'open_maintenance' => (int) $maintenanceSummary['open'],
            'outstanding_cents' => $this->ledger->computeOutstanding(),
            'overdue_cents' => $this->ledger->computeOverdue(),
            'affected_tenants_overdue' => $overdueCases->pluck('tenant_id')->filter()->unique()->count(),

            // New this month, for the platform-health card microcopy.
            'new_landlords_this_month' => User::landlords()->where('created_at', '>=', $monthStart)->count(),
            'new_tenants_this_month' => User::tenants()->where('created_at', '>=', $monthStart)->count(),
            'landlords_with_overdue_balance' => $overdueCases->pluck('landlord_id')->filter()->unique()->count(),
            'tenants_with_outstanding_balance' => (int) LedgerEntry::whereIn('type', [LedgerType::RENT, LedgerType::LATE_FEE])
                ->whereIn('status', [LedgerStatus::PENDING, LedgerStatus::OVERDUE])
                ->distinct('tenant_id')
                ->count('tenant_id'),
            'properties_with_open_maintenance' => (int) \App\Models\MaintenanceRequest::open()
                ->distinct('property_id')
                ->count('property_id'),
            'contracts_starting_this_month' => \App\Models\Contract::where('start_date', '>=', $monthStart->toDateString())
                ->where('start_date', '<=', $monthStart->copy()->endOfMonth()->toDateString())
                ->count(),
            'contracts_ending_within_30_days' => \App\Models\Contract::where('status', \App\Enums\ContractStatus::ACTIVE)
                ->whereBetween('end_date', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])
                ->count(),
            'listings_needing_changes' => (int) ($listingCounts['rejected'] ?? 0),
            'verifications_pending_by_role' => $verificationSummary['pending_by_role'] ?? ['tenant' => 0, 'landlord' => 0],
            'verifications_overdue' => (int) ($verificationTiming['overdue_count'] ?? 0),
            'maintenance_emergency' => (int) ($maintenanceSummary['urgent'] ?? 0),
            'maintenance_overdue' => (int) ($maintenanceSummary['overdue'] ?? 0),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Financial
     * ──────────────────────────────────────────────────────────────────── */

    protected function financialSection(array $ledgerFilters): array
    {
        $summary = $this->ledger->computePlatformFinancialSummary($ledgerFilters);
        $collectionRate = $summary['rent_charged_cents'] > 0
            ? round(($summary['collected_cents'] / $summary['rent_charged_cents']) * 100, 2)
            : 0.0;

        $legacyFilters = array_filter([
            'start_date' => $ledgerFilters['date_from'] ?? null,
            'end_date' => $ledgerFilters['date_to'] ?? null,
        ]);

        return array_merge($summary, [
            'collection_rate_percentage' => $collectionRate,
            'revenue_by_month' => $this->financial->getRevenueMetrics($legacyFilters)['revenue_by_month'],
            'billed_by_month' => $this->billedByMonth(),
            'collected_by_month' => $this->collectedByMonth(),
            'outstanding_by_age' => $this->outstandingByAge(),
            'outstanding_by_landlord' => $this->outstandingByLandlord(),
            'outstanding_trend_by_month' => $this->outstandingTrend(),
        ]);
    }

    /**
     * Real, always-6-month billed/collected pair for the "billed vs
     * collected" trend chart — deliberately NOT `revenue_by_month` above
     * (that field is RENT entries with status=PAID, an accrual figure kept
     * for backward compatibility with its existing consumers). `billed`
     * counts every RENT charge generated per month regardless of status;
     * `collected` is cash-basis (PAYMENT-type entries, which are always
     * negative by sign rule, hence the abs()).
     */
    protected function billedByMonth(): array
    {
        return LedgerEntry::where('type', LedgerType::RENT)
            ->selectRaw("strftime('%Y-%m', created_at) as month, SUM(amount_cents) as total_cents")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->month => (int) $row->total_cents])
            ->all();
    }

    protected function collectedByMonth(): array
    {
        return LedgerEntry::where('type', LedgerType::PAYMENT)
            ->selectRaw("strftime('%Y-%m', created_at) as month, SUM(amount_cents) as total_cents")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->month => (int) abs($row->total_cents)])
            ->all();
    }

    /**
     * Historical replay of "outstanding balance as of each of the last 6
     * month-ends" — an honest reconstruction from immutable ledger data, not
     * a stored time series (none exists). An entry counts as outstanding as
     * of a given month-end if it existed by then (`created_at <= monthEnd`)
     * and had no linked payment yet, or its payment landed after that
     * month-end. Entries currently WAIVED are excluded outright: there is no
     * `waived_at` column, so we cannot know when (or whether) they were
     * outstanding at any given past month-end, and guessing would be a
     * fabrication.
     */
    protected function outstandingTrend(int $months = 6): array
    {
        $entries = LedgerEntry::whereIn('type', [LedgerType::RENT, LedgerType::LATE_FEE])
            ->where('status', '!=', LedgerStatus::WAIVED)
            ->get(['id', 'amount_cents', 'created_at']);

        $payments = LedgerEntry::where('type', LedgerType::PAYMENT)
            ->whereNotNull('related_rent_entry_id')
            ->get(['related_rent_entry_id', 'created_at'])
            ->keyBy('related_rent_entry_id');

        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthEnd = Carbon::now()->subMonthsNoOverflow($i)->endOfMonth();

            $outstanding = $entries->filter(function (LedgerEntry $entry) use ($monthEnd, $payments) {
                if ($entry->created_at->gt($monthEnd)) {
                    return false;
                }
                $payment = $payments->get($entry->id);

                return ! $payment || Carbon::parse($payment->created_at)->gt($monthEnd);
            })->sum('amount_cents');

            $trend[$monthEnd->format('Y-m')] = (int) $outstanding;
        }

        return $trend;
    }

    /**
     * Aging buckets over currently unpaid, past-due rent/late_fee entries.
     * Reports both entry_count and distinct tenant_count since those answer
     * different questions ("how many charges" vs "how many people").
     */
    protected function outstandingByAge(): array
    {
        $today = Carbon::today();
        $entries = LedgerEntry::whereIn('type', [LedgerType::RENT, LedgerType::LATE_FEE])
            ->whereIn('status', [LedgerStatus::PENDING, LedgerStatus::OVERDUE])
            ->whereDate('due_date', '<', $today->toDateString())
            ->get();

        $buckets = [
            ['label' => '1-7 days', 'min' => 1, 'max' => 7],
            ['label' => '8-30 days', 'min' => 8, 'max' => 30],
            ['label' => '31-60 days', 'min' => 31, 'max' => 60],
            ['label' => '60+ days', 'min' => 61, 'max' => PHP_INT_MAX],
        ];

        return collect($buckets)->map(function ($bucket) use ($entries, $today) {
            $matching = $entries->filter(function (LedgerEntry $e) use ($bucket, $today) {
                $daysLate = (int) $e->due_date->diffInDays($today);

                return $daysLate >= $bucket['min'] && $daysLate <= $bucket['max'];
            });

            return [
                'label' => $bucket['label'],
                'amount_cents' => (int) $matching->sum('amount_cents'),
                'entry_count' => $matching->count(),
                'tenant_count' => $matching->pluck('tenant_id')->filter()->unique()->count(),
            ];
        })->values()->all();
    }

    protected function outstandingByLandlord(int $limit = 8): array
    {
        $rows = LedgerEntry::whereIn('type', [LedgerType::RENT, LedgerType::LATE_FEE])
            ->whereIn('status', [LedgerStatus::PENDING, LedgerStatus::OVERDUE])
            ->whereNotNull('landlord_id')
            ->selectRaw('landlord_id,
                SUM(amount_cents) as outstanding_cents,
                SUM(CASE WHEN status = ? OR due_date < ? THEN amount_cents ELSE 0 END) as overdue_cents', [
                LedgerStatus::OVERDUE->value,
                Carbon::today()->toDateString(),
            ])
            ->groupBy('landlord_id')
            ->orderByDesc('outstanding_cents')
            ->limit($limit)
            ->get();

        $landlords = User::whereIn('id', $rows->pluck('landlord_id'))->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'landlord_id' => $row->landlord_id,
            'name' => $landlords->get($row->landlord_id)?->full_name ?? 'Unknown landlord',
            'outstanding_cents' => (int) $row->outstanding_cents,
            'overdue_cents' => (int) $row->overdue_cents,
        ])->values()->all();
    }

    /* ────────────────────────────────────────────────────────────────────
     * Ledger integrity
     * ──────────────────────────────────────────────────────────────────── */

    protected function ledgerIntegritySection(array $reconciliation): array
    {
        return [
            'status' => $reconciliation['status'],
            'issue_count' => count($reconciliation['issues']),
            'issues' => $reconciliation['issues'],
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Rent collection health
     * ──────────────────────────────────────────────────────────────────── */

    protected function rentCollectionSection(array $ledgerFilters, Collection $overdueCases): array
    {
        $today = Carbon::today()->toDateString();

        $pastDueRent = LedgerEntry::where('type', LedgerType::RENT)
            ->whereDate('due_date', '<', $today)
            ->when(! empty($ledgerFilters['date_from']), fn ($q) => $q->where('created_at', '>=', $ledgerFilters['date_from']))
            ->when(! empty($ledgerFilters['date_to']), fn ($q) => $q->where('created_at', '<=', $ledgerFilters['date_to']))
            ->get();

        $payments = LedgerEntry::where('type', LedgerType::PAYMENT)
            ->whereIn('related_rent_entry_id', $pastDueRent->pluck('id'))
            ->get()
            ->keyBy('related_rent_entry_id');

        $onTime = 0;
        $late = 0;
        $missed = 0;
        $waived = 0;
        $lateDaysTotal = 0;
        $lateCount = 0;
        $lateTenantCounts = [];

        foreach ($pastDueRent as $rent) {
            if ($rent->status === LedgerStatus::WAIVED) {
                $waived++;

                continue;
            }

            $payment = $payments->get($rent->id);
            if (! $payment) {
                $missed++;

                continue;
            }

            $daysLate = (int) $rent->due_date->diffInDays($payment->created_at, false);
            if ($daysLate > 0) {
                $late++;
                $lateDaysTotal += $daysLate;
                $lateCount++;
                $lateTenantCounts[$rent->tenant_id] = ($lateTenantCounts[$rent->tenant_id] ?? 0) + 1;
            } else {
                $onTime++;
            }
        }

        $denominator = $onTime + $late + $missed;
        $repeatLateTenants = collect($lateTenantCounts)->filter(fn ($c) => $c > 1)->count();

        return [
            'on_time_count' => $onTime,
            'on_time_rate_percentage' => $denominator > 0 ? round($onTime / $denominator * 100, 2) : 0.0,
            'late_count' => $late,
            'late_rate_percentage' => $denominator > 0 ? round($late / $denominator * 100, 2) : 0.0,
            'missed_count' => $missed,
            'missed_rate_percentage' => $denominator > 0 ? round($missed / $denominator * 100, 2) : 0.0,
            'waived_count' => $waived,
            'average_days_late' => $lateCount > 0 ? round($lateDaysTotal / $lateCount, 2) : 0.0,
            'repeat_late_tenant_count' => $repeatLateTenants,
            'top_overdue_cases' => $overdueCases->sortByDesc('display_amount_cents')->take(10)
                ->map(fn ($entry) => $this->rentCaseSummary($entry))->values()->all(),
            'top_landlords_by_overdue' => $this->outstandingByLandlord(5),
        ];
    }

    /**
     * Flattens a decorated overdue-entry array (as returned by
     * LedgerComputationEngine::overdueCases(), whose `tenant`/`landlord`/
     * `contract` keys are the full eager-loaded relation arrays) into the
     * flat, frontend-facing shape: plain name strings, not nested objects.
     * Mirrors AdminOperationsDashboardService::rentCaseSummary() so the two
     * pages never disagree about what an overdue case looks like.
     */
    protected function rentCaseSummary(array $entry): array
    {
        return [
            'id' => $entry['id'],
            'tenant' => $entry['tenant']['full_name'] ?? null,
            'landlord' => $entry['landlord']['full_name'] ?? null,
            'property' => $this->propertyLabelFromEntry($entry),
            'display_amount_cents' => $entry['display_amount_cents'],
            'due_date' => $entry['due_date'],
            'days_late' => $entry['days_late'],
            'status' => $entry['status'],
            'contract_id' => $entry['contract_id'],
        ];
    }

    protected function propertyLabelFromEntry(array $entry): ?string
    {
        $property = $entry['contract']['listing']['unit']['property']['name'] ?? null;
        $unit = $entry['contract']['listing']['unit']['display_name'] ?? null;

        $label = trim(collect([$property, $unit])->filter()->implode(' · '));

        return $label !== '' ? $label : null;
    }

    /* ────────────────────────────────────────────────────────────────────
     * Users
     * ──────────────────────────────────────────────────────────────────── */

    protected function usersSection(?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $growth = $this->platform->getGrowthMetrics();

        $signupQuery = User::query();
        if ($dateFrom) {
            $signupQuery->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $signupQuery->where('created_at', '<=', $dateTo);
        }

        $signups = $signupQuery->get(['id', 'user_type', 'created_at'])
            ->groupBy(fn (User $u) => $u->created_at->format('Y-m'))
            ->map(fn ($group) => [
                'tenants' => $group->filter(fn (User $u) => $u->user_type?->value === 'tenant')->count(),
                'landlords' => $group->filter(fn (User $u) => $u->user_type?->value === 'landlord')->count(),
            ])
            ->sortKeys();

        return [
            'total_users' => $growth['total_users'],
            'users_by_role' => $growth['users_by_role'],
            'tenants' => [
                'total' => User::tenants()->count(),
                'active' => User::tenants()->active()->count(),
                'new_this_period' => (clone $signupQuery)->tenants()->count(),
            ],
            'landlords' => [
                'total' => User::landlords()->count(),
                'active' => User::landlords()->active()->count(),
                'new_this_period' => (clone $signupQuery)->landlords()->count(),
            ],
            'admins' => [
                'total' => Admin::count(),
                'active' => Admin::where('is_active', true)->count(),
                'super_admins' => Admin::where('is_super_admin', true)->count(),
            ],
            'signups_by_month' => $signups->toArray(),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Listings
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Real, mutually-exclusive listing status distribution across all six
     * ListingStatus values (draft/pending_review/active/inactive/rejected/
     * archived) — every listing counted exactly once. Deliberately NOT
     * ListingReviewService::counts(), whose keys (pending/approved/rejected/
     * all/approved_today/needs_attention/missing_info) are queue-summary
     * figures that overlap each other and would double-count in a chart that
     * shows parts of a whole.
     *
     * @return array<string, int>
     */
    protected function listingsByStatus(): array
    {
        $rows = \App\Models\Listing::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $output = [];
        foreach ($rows as $row) {
            $value = $row->status instanceof ListingStatus ? $row->status->value : (string) $row->status;
            $output[$value] = (int) $row->aggregate;
        }

        return $output;
    }

    /**
     * Average hours from a listing's real "listing_submitted" audit event to
     * its "listing_published" event, matched by subject_id. Uses the audit
     * trail (not created_at/published_at) because a listing can sit in DRAFT
     * indefinitely before submission — created_at is not a submission time.
     */
    protected function averageListingApprovalHours(): float
    {
        $submitted = AuditLog::where('action', 'listing_submitted')
            ->whereNotNull('subject_id')
            ->get(['subject_id', 'created_at'])
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->min('created_at'));

        $published = AuditLog::where('action', 'listing_published')
            ->whereNotNull('subject_id')
            ->get(['subject_id', 'created_at'])
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->min('created_at'));

        $hours = $submitted->filter(fn ($submittedAt, $id) => $published->has($id))
            ->map(fn ($submittedAt, $id) => Carbon::parse($submittedAt)->floatDiffInHours($published->get($id)));

        return $hours->isNotEmpty() ? round($hours->avg(), 2) : 0.0;
    }

    /* ────────────────────────────────────────────────────────────────────
     * Admin activity
     * ──────────────────────────────────────────────────────────────────── */

    protected function adminActivitySection(?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $periodStart = $dateFrom ?? now()->subDays(30);
        $periodEnd = $dateTo ?? now();

        $logs = AuditLog::where('actor_type', Admin::class)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->get();

        $logins = AuditLog::where('action', 'admin_login')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $permissionChanges = $logs->whereIn('action', [
            'admin_capabilities_updated', 'admin_promoted_super', 'admin_demoted_super',
        ])->count();

        $failedAccess = $logs->where('action', 'admin_access_denied')->count();

        $byAdmin = $logs->groupBy('actor_id')->map(function ($rows, $adminId) {
            $sensitive = $rows->filter(fn (AuditLog $l) => AuditClassifier::classification($l->action, $l->severity) !== 'Routine');

            return [
                'admin_id' => $adminId,
                'actions' => $rows->count(),
                'sensitive_actions' => $sensitive->count(),
            ];
        });

        $admins = Admin::whereIn('id', $byAdmin->keys())->get()->keyBy('id');

        $byAdminRows = $byAdmin->map(function ($row) use ($admins) {
            $admin = $admins->get($row['admin_id']);

            return array_merge($row, [
                'name' => $admin?->name ?? 'Unknown admin',
                'is_super_admin' => $admin?->is_super_admin ?? false,
                'capabilities' => $admin?->grantedCapabilities() ?? [],
                'last_active_at' => $admin?->last_login_at?->toIso8601String(),
            ]);
        })->sortByDesc('actions')->values()->take(10)->all();

        $recent = $logs
            ->filter(fn (AuditLog $l) => AuditClassifier::classification($l->action, $l->severity) !== 'Routine')
            ->sortByDesc('created_at')
            ->take(10)
            ->map(fn (AuditLog $log) => [
                'created_at' => $log->created_at?->toIso8601String(),
                'admin_name' => $admins->get($log->actor_id)?->name ?? 'Unknown admin',
                'action' => $log->action,
                'title' => AuditClassifier::title($log->action),
                'description' => $log->description,
                'area' => AuditClassifier::area($log->action),
            ])
            ->values()
            ->all();

        return [
            'logins_24h' => $logins,
            'sensitive_actions_period' => $logs->filter(fn (AuditLog $l) => AuditClassifier::classification($l->action, $l->severity) !== 'Routine')->count(),
            'permission_changes_period' => $permissionChanges,
            'failed_access_attempts_period' => $failedAccess,
            'by_admin' => $byAdminRows,
            'recent' => $recent,
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Risk & compliance register — a flat worklist synthesized from figures
     * already computed above, not a fresh set of queries.
     * ──────────────────────────────────────────────────────────────────── */

    protected function riskRegister(array $reconciliation, Collection $overdueCases, array $maintenanceSummary, array $verificationTiming, array $applicationAnalytics): array
    {
        $items = [];

        foreach ($reconciliation['issues'] as $issue) {
            $items[] = [
                'title' => str_replace('_', ' ', ucfirst($issue['code'])),
                'severity' => $issue['severity'] === 'fail' ? 'critical' : 'medium',
                'subject' => $issue['message'],
                'area' => 'Ledger',
                'route' => '/app/ledger',
            ];
        }

        $highestOverdue = $overdueCases->sortByDesc('display_amount_cents')->first();
        if ($highestOverdue) {
            $items[] = [
                'title' => 'Rent overdue '.$highestOverdue['days_late'].' days',
                'severity' => $highestOverdue['days_late'] >= 60 ? 'critical' : ($highestOverdue['days_late'] >= 30 ? 'high' : 'medium'),
                'subject' => $highestOverdue['tenant']['full_name'] ?? 'Unknown tenant',
                'area' => 'Finance',
                'route' => '/app/ledger',
            ];
        }

        if (($maintenanceSummary['urgent'] ?? 0) > 0) {
            $items[] = [
                'title' => 'Urgent maintenance open',
                'severity' => 'critical',
                'subject' => $maintenanceSummary['urgent'].' urgent request(s)',
                'area' => 'Maintenance',
                'route' => '/app/maintenance',
            ];
        }
        if (($maintenanceSummary['overdue'] ?? 0) > 0) {
            $items[] = [
                'title' => 'Maintenance past target',
                'severity' => 'high',
                'subject' => $maintenanceSummary['overdue'].' request(s) overdue',
                'area' => 'Maintenance',
                'route' => '/app/maintenance',
            ];
        }

        $verificationOverdue = $verificationTiming['overdue_count'] ?? 0;
        if ($verificationOverdue > 0) {
            $items[] = [
                'title' => 'Verification backlog',
                'severity' => 'medium',
                'subject' => $verificationOverdue.' case(s) waiting over 72 hours',
                'area' => 'Verification',
                'route' => '/app/verifications',
            ];
        }

        $staleApplications = $applicationAnalytics['stale_count'] ?? 0;
        if ($staleApplications > 0) {
            $items[] = [
                'title' => 'Stale applications',
                'severity' => 'medium',
                'subject' => $staleApplications.' application(s) untouched over 5 days',
                'area' => 'Applications',
                'route' => '/app/applicants',
            ];
        }

        $failedNotifications = \App\Models\Notification::where(function ($q) {
            $q->whereNotNull('delivery_failed_at')->orWhereNotNull('sms_failed_at');
        })->count();
        if ($failedNotifications > 0) {
            $items[] = [
                'title' => 'Failed notification deliveries',
                'severity' => 'low',
                'subject' => $failedNotifications.' notice(s) failed delivery',
                'area' => 'Communication',
                'route' => '/app/notifications',
            ];
        }

        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($items, fn ($a, $b) => $rank[$b['severity']] <=> $rank[$a['severity']]);

        return array_slice($items, 0, 15);
    }

    /* ────────────────────────────────────────────────────────────────────
     * System health — reuses the same truthful signals as the admin
     * dashboard's system_health card so the two pages never disagree.
     * ──────────────────────────────────────────────────────────────────── */

    protected function systemHealthSection(): array
    {
        $failedNotifications = \App\Models\Notification::where(function ($q) {
            $q->whereNotNull('delivery_failed_at')->orWhereNotNull('sms_failed_at');
        })->count();

        return [
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'failed_notifications' => $failedNotifications,
            'payment_failures_24h' => \App\Models\Notification::where('type', \App\Enums\NotificationType::PAYMENT_FAILED->value)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Exports — only two export flows are currently audit-logged
     * (AdminLedgerController::export → 'ledger_exported',
     * AdminAnalyticsController::export → 'admin_analytics_exported'). Both
     * are financial/PII-adjacent, so both are flagged sensitive. Reporting
     * only what's real rather than inventing a report catalogue.
     * ──────────────────────────────────────────────────────────────────── */

    protected function exportsSection(): array
    {
        $recent = AuditLog::whereIn('action', ['ledger_exported', 'admin_analytics_exported'])
            ->with('actor')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'recent_exports' => $recent->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'by' => $log->actor instanceof Admin ? $log->actor->name : 'Unknown',
                'description' => $log->description,
                'sensitive' => true,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
