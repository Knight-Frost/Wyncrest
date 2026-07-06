<?php

namespace App\Services\Admin;

use App\Enums\AdminCapability;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\ListingReviewService;
use App\Services\VerificationCaseService;
use App\Support\Audit\AuditClassifier;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * AdminAnalyticsService
 *
 * Assembles the "Admin Analytics" page: a permission-scoped work/risk
 * dashboard for the SIGNED-IN admin, distinct from the Super Admin
 * "Platform Analytics" page (SuperAdminAnalyticsService). Every section is
 * gated by the admin's real capabilities (Admin::hasCapability, which a
 * super admin bypasses implicitly) — a module the admin cannot moderate is
 * OMITTED from the response entirely, never returned empty or faked.
 *
 * Figures are delegated to the same single-source-of-truth read models the
 * rest of the admin console already uses (LedgerComputationEngine,
 * VerificationCaseService, ListingReviewService, MaintenanceOverviewService),
 * and attention-item severities intentionally MIRROR
 * SuperAdminAnalyticsService::riskRegister() so the two analytics pages never
 * disagree about how urgent the same underlying fact is.
 *
 * "My decisions" / "My activity" are read from the append-only audit log,
 * filtered to this admin as actor — the audit log is the only place that
 * reliably records who acted (Listing::reviewed_by is not set on approval,
 * only on reject/request-changes, so it cannot be trusted for "my decisions").
 *
 * Applications are deliberately NOT a section here: no AdminCapability
 * governs application review in Wyncrest — landlords decide applications,
 * not admins — so there is no real permission to scope a section behind.
 */
class AdminAnalyticsService
{
    /** Actions written by decision-bearing endpoints, used for "my decisions" counts. */
    private const DECISION_ACTIONS = [
        'listing_published', 'listing_rejected', 'listing_changes_requested',
        'verification_approved', 'verification_rejected', 'verification_needs_info',
        'entry_waived', 'late_fee_applied',
    ];

    public function __construct(
        private readonly LedgerComputationEngine $ledger,
        private readonly VerificationCaseService $verifications,
        private readonly ListingReviewService $listingReview,
        private readonly MaintenanceOverviewService $maintenance,
    ) {}

    public function forAdmin(Admin $admin, Carbon $dateFrom, Carbon $dateTo): array
    {
        $attention = [];
        $permitted = [];
        $restricted = [];
        $modules = [];

        // Maintenance: a baseline admin privilege with no capability gate,
        // mirroring the (likewise ungated) GET /admin/maintenance route.
        $maintenanceSummary = $this->maintenance->summary();
        $permitted[] = 'Maintenance';
        $modules['maintenance'] = $this->maintenanceModule($maintenanceSummary);
        $this->attendMaintenance($attention, $maintenanceSummary);

        if ($this->grants($admin, AdminCapability::MODERATE_LISTINGS, $restricted)) {
            $listingCounts = $this->listingReview->counts();
            $permitted[] = 'Listings';
            $modules['listings'] = $this->listingsModule($listingCounts, $admin, $dateFrom, $dateTo);
            $this->attendListings($attention, $listingCounts);
        }

        if ($this->grants($admin, AdminCapability::REVIEW_VERIFICATIONS, $restricted)) {
            $verificationSummary = $this->verifications->summary();
            $verificationTiming = $this->verifications->reviewTimingMetrics();
            $permitted[] = 'Verifications';
            $modules['verifications'] = $this->verificationsModule($verificationSummary, $verificationTiming, $admin, $dateFrom, $dateTo);
            $this->attendVerifications($attention, $verificationSummary, $verificationTiming);
        }

        // Financial/ledger analytics is gated stricter than the raw ledger
        // listing endpoint (which any admin can read): aggregated,
        // cross-tenant money exposure is treated as "finance access" here,
        // matching manage_ledger — the only ledger-related capability.
        if ($this->grants($admin, AdminCapability::MANAGE_LEDGER, $restricted)) {
            $overdueCases = $this->ledger->overdueCases();
            $permitted[] = 'Ledger';
            $modules['ledger'] = $this->ledgerModule($overdueCases);
            $this->attendLedger($attention, $overdueCases);
        }

        // Notification delivery analytics reuses the exact capability that
        // already gates GET /admin/notifications/deliveries (view_audit) —
        // no new capability invented for a page-specific need.
        if ($this->grants($admin, AdminCapability::VIEW_AUDIT, $restricted)) {
            $permitted[] = 'Notifications';
            $modules['notifications'] = $this->notificationsModule();
            $this->attendNotifications($attention);
        }

        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($attention, fn ($a, $b) => $rank[$b['severity']] <=> $rank[$a['severity']]);

        return [
            'generated_at' => now()->toIso8601String(),
            'admin' => [
                'name' => $admin->name,
                'is_super_admin' => $admin->is_super_admin,
            ],
            'scope' => [
                'permitted_modules' => $permitted,
                'restricted_modules' => array_values(array_unique($restricted)),
            ],
            'attention' => array_slice($attention, 0, 15),
            'workload' => $this->workload($modules),
            'modules' => $modules,
            'me' => $this->meSection($admin, $dateFrom, $dateTo, $admin->hasCapability(AdminCapability::VIEW_AUDIT->value)),
        ];
    }

    /**
     * Checks (and records, for a scoped admin) whether $admin holds
     * $capability. Super admins always pass via Admin::hasCapability's own
     * bypass — nothing here special-cases them.
     */
    private function grants(Admin $admin, AdminCapability $capability, array &$restricted): bool
    {
        if ($admin->hasCapability($capability->value)) {
            return true;
        }

        $restricted[] = $capability->label();

        return false;
    }

    /* ────────────────────────────────────────────────────────────────────
     * Module sections
     * ──────────────────────────────────────────────────────────────────── */

    private function maintenanceModule(array $summary): array
    {
        $preview = $this->maintenance->cases(['status' => 'urgent', 'limit' => 5]);
        if (empty($preview)) {
            $preview = $this->maintenance->cases(['status' => 'overdue', 'limit' => 5]);
        }

        return [
            'summary' => $summary,
            'queue_preview' => collect($preview)->map(fn ($c) => [
                'id' => $c['id'],
                'title' => $c['title'],
                'property' => $c['property'],
                'priority' => $c['priority'],
                'age_days' => $c['age_days'],
                'route' => '/app/maintenance',
            ])->values()->all(),
            'route' => '/app/maintenance',
        ];
    }

    private function listingsModule(array $counts, Admin $admin, Carbon $from, Carbon $to): array
    {
        $preview = [];
        $oldestPendingAgeHours = null;

        if (($counts['pending'] ?? 0) > 0) {
            $queue = $this->listingReview->queue(['status' => 'pending', 'sort' => 'oldest']);
            $oldest = $queue['data'][0] ?? null;
            if ($oldest && isset($oldest['submitted_at'])) {
                $oldestPendingAgeHours = (int) abs(now()->diffInHours(Carbon::parse($oldest['submitted_at'])));
            }
            $preview = collect($queue['data'])->take(5)->map(fn ($row) => [
                'id' => $row['id'],
                'title' => $row['title'] ?? null,
                'landlord' => $row['landlord']['name'] ?? null,
                'location' => $row['location'] ?? null,
                'route' => "/app/listing-review/{$row['id']}",
            ])->values()->all();
        }

        return [
            'counts' => $counts,
            'oldest_pending_age_hours' => $oldestPendingAgeHours,
            'queue_preview' => $preview,
            'my_decisions' => $this->decisionCounts($admin, $from, $to, [
                'approved' => 'listing_published',
                'rejected' => 'listing_rejected',
                'sent_back' => 'listing_changes_requested',
            ]),
            'route' => '/app/listing-review',
        ];
    }

    private function verificationsModule(array $summary, array $timing, Admin $admin, Carbon $from, Carbon $to): array
    {
        $preview = [];
        if (($summary['pending'] ?? 0) > 0) {
            $page = $this->verifications->paginate(['status' => 'pending', 'sort' => 'oldest']);
            $preview = collect(array_slice($page->items(), 0, 5))->map(fn ($req) => [
                'id' => $req->id,
                'name' => $req->user?->full_name,
                'role' => $req->user?->user_type?->value,
                'submitted_at' => $req->submitted_at?->toIso8601String(),
                'route' => "/app/verifications/{$req->id}",
            ])->values()->all();
        }

        return [
            'summary' => $summary,
            'timing' => $timing,
            'queue_preview' => $preview,
            'my_decisions' => $this->decisionCounts($admin, $from, $to, [
                'approved' => 'verification_approved',
                'rejected' => 'verification_rejected',
                'sent_back' => 'verification_needs_info',
            ]),
            'route' => '/app/verifications',
        ];
    }

    private function ledgerModule(Collection $overdueCases): array
    {
        $preview = $overdueCases->sortByDesc('display_amount_cents')->take(5)->map(fn ($e) => [
            'id' => $e['id'],
            'tenant' => $e['tenant']['full_name'] ?? null,
            'amount_cents' => $e['display_amount_cents'],
            'days_late' => $e['days_late'],
            'route' => '/app/ledger',
        ])->values()->all();

        return [
            'overdue_count' => $overdueCases->count(),
            'overdue_cents' => $this->ledger->computeOverdue(),
            'outstanding_cents' => $this->ledger->computeOutstanding(),
            'affected_tenants' => $overdueCases->pluck('tenant_id')->filter()->unique()->count(),
            'queue_preview' => $preview,
            'route' => '/app/ledger',
        ];
    }

    private function notificationsModule(): array
    {
        $failedBase = Notification::where(function ($q) {
            $q->whereNotNull('delivery_failed_at')->orWhereNotNull('sms_failed_at');
        });

        $recent = (clone $failedBase)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Notification $n) => [
                'id' => $n->id,
                'recipient' => $n->user?->full_name,
                'type' => $n->type?->value,
                'error' => $n->delivery_error ?? $n->sms_error,
                'occurred_at' => $n->created_at?->toIso8601String(),
                'route' => '/app/notifications',
            ])->values()->all();

        return [
            'failed_total' => (clone $failedBase)->count(),
            'email_failed' => (clone $failedBase)->whereNotNull('delivery_failed_at')->count(),
            'sms_failed' => (clone $failedBase)->whereNotNull('sms_failed_at')->count(),
            'recent_failures' => $recent,
            'route' => '/app/notifications',
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Cross-module aggregates
     * ──────────────────────────────────────────────────────────────────── */

    private function workload(array $modules): array
    {
        $byModule = [];
        if (isset($modules['listings'])) {
            $byModule['Listings'] = $modules['listings']['counts']['pending'] ?? 0;
        }
        if (isset($modules['verifications'])) {
            $byModule['Verifications'] = $modules['verifications']['summary']['pending'] ?? 0;
        }
        if (isset($modules['maintenance'])) {
            $byModule['Maintenance'] = $modules['maintenance']['summary']['open'] ?? 0;
        }

        return [
            'pending_total' => array_sum($byModule),
            'by_module' => $byModule,
        ];
    }

    /**
     * Real audit-log events, actor-scoped to this admin only — never another
     * admin's activity or the platform-wide trail (that stays behind
     * view_audit on /app/audit).
     */
    private function meSection(Admin $admin, Carbon $from, Carbon $to, bool $canOpenAuditDetail): array
    {
        $logs = AuditLog::where('actor_type', Admin::class)
            ->where('actor_id', $admin->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        $sensitive = $logs->filter(fn (AuditLog $l) => AuditClassifier::classification($l->action, $l->severity) !== 'Routine');

        return [
            'actions_period' => $logs->count(),
            'sensitive_actions_period' => $sensitive->count(),
            'decisions_period' => $logs->whereIn('action', self::DECISION_ACTIONS)->count(),
            'exports_period' => $logs->whereIn('action', ['ledger_exported', 'admin_analytics_exported'])->count(),
            'recent_activity' => $logs->take(20)->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'title' => AuditClassifier::title($l->action),
                'area' => AuditClassifier::area($l->action),
                'severity' => $l->severity,
                'created_at' => $l->created_at?->toIso8601String(),
                // Only link into the audit detail page when this admin can
                // actually open it (view_audit) — never a link that 403s.
                'detail_route' => $canOpenAuditDetail ? "/app/audit/{$l->id}" : null,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function decisionCounts(Admin $admin, Carbon $from, Carbon $to, array $actionMap): array
    {
        $rows = AuditLog::where('actor_type', Admin::class)
            ->where('actor_id', $admin->id)
            ->whereIn('action', array_values($actionMap))
            ->whereBetween('created_at', [$from, $to])
            ->get(['action']);

        $counts = [];
        foreach ($actionMap as $label => $action) {
            $counts[$label] = $rows->where('action', $action)->count();
        }

        return $counts;
    }

    /* ────────────────────────────────────────────────────────────────────
     * Attention items — severities MIRROR SuperAdminAnalyticsService::
     * riskRegister() for the same underlying facts, so the scoped and
     * platform-wide analytics pages never disagree.
     * ──────────────────────────────────────────────────────────────────── */

    private function attendMaintenance(array &$attention, array $summary): void
    {
        if (($summary['urgent'] ?? 0) > 0) {
            $attention[] = [
                'title' => 'Urgent maintenance open',
                'severity' => 'critical',
                'subject' => $summary['urgent'].' urgent request(s)',
                'area' => 'Maintenance',
                'route' => '/app/maintenance',
            ];
        }
        if (($summary['overdue'] ?? 0) > 0) {
            $attention[] = [
                'title' => 'Maintenance past target',
                'severity' => 'high',
                'subject' => $summary['overdue'].' request(s) overdue',
                'area' => 'Maintenance',
                'route' => '/app/maintenance',
            ];
        }
    }

    private function attendListings(array &$attention, array $counts): void
    {
        $pending = $counts['pending'] ?? 0;
        if ($pending > 0) {
            $attention[] = [
                'title' => 'Listings pending review',
                'severity' => $pending >= 10 ? 'high' : 'medium',
                'subject' => $pending.' listing(s) waiting',
                'area' => 'Listings',
                'route' => '/app/listing-review',
            ];
        }
    }

    private function attendVerifications(array &$attention, array $summary, array $timing): void
    {
        $pending = $summary['pending'] ?? 0;
        if ($pending > 0) {
            $attention[] = [
                'title' => 'Verifications pending review',
                'severity' => 'medium',
                'subject' => $pending.' case(s) waiting',
                'area' => 'Verifications',
                'route' => '/app/verifications',
            ];
        }

        $overdue = $timing['overdue_count'] ?? 0;
        if ($overdue > 0) {
            $attention[] = [
                'title' => 'Verification backlog',
                'severity' => 'medium',
                'subject' => $overdue.' case(s) waiting over 72 hours',
                'area' => 'Verification',
                'route' => '/app/verifications',
            ];
        }
    }

    private function attendLedger(array &$attention, Collection $overdueCases): void
    {
        $highest = $overdueCases->sortByDesc('display_amount_cents')->first();
        if (! $highest) {
            return;
        }

        $days = $highest['days_late'];
        $attention[] = [
            'title' => "Rent overdue {$days} days",
            'severity' => $days >= 60 ? 'critical' : ($days >= 30 ? 'high' : 'medium'),
            'subject' => $highest['tenant']['full_name'] ?? 'Unknown tenant',
            'area' => 'Finance',
            'route' => '/app/ledger',
        ];
    }

    private function attendNotifications(array &$attention): void
    {
        $failedTotal = Notification::where(function ($q) {
            $q->whereNotNull('delivery_failed_at')->orWhereNotNull('sms_failed_at');
        })->count();

        if ($failedTotal > 0) {
            $attention[] = [
                'title' => 'Failed notification deliveries',
                'severity' => 'low',
                'subject' => $failedTotal.' notice(s) failed delivery',
                'area' => 'Communication',
                'route' => '/app/notifications',
            ];
        }
    }
}
