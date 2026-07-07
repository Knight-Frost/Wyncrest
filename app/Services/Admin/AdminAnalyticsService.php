<?php

namespace App\Services\Admin;

use App\Enums\AdminCapability;
use App\Enums\MaintenanceStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\VerificationRequest;
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
 *
 * "My performance" (decision trend, outcome split, send-back reasons, avg
 * decision time) covers Listings + Verifications only — see myPerformance()
 * for why Ledger/Maintenance actions are excluded rather than faked.
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
        $this->attendMaintenance($attention, $modules['maintenance']);

        if ($this->grants($admin, AdminCapability::MODERATE_LISTINGS, $restricted)) {
            $listingCounts = $this->listingReview->counts();
            $permitted[] = 'Listings';
            $modules['listings'] = $this->listingsModule($listingCounts, $admin, $dateFrom, $dateTo);
            $this->attendListings($attention, $modules['listings']);
        }

        if ($this->grants($admin, AdminCapability::REVIEW_VERIFICATIONS, $restricted)) {
            $verificationSummary = $this->verifications->summary();
            $verificationTiming = $this->verifications->reviewTimingMetrics();
            $permitted[] = 'Verifications';
            $modules['verifications'] = $this->verificationsModule($verificationSummary, $verificationTiming, $admin, $dateFrom, $dateTo);
            $this->attendVerifications($attention, $modules['verifications']);
        }

        // Financial/ledger analytics is gated stricter than the raw ledger
        // listing endpoint (which any admin can read): aggregated,
        // cross-tenant money exposure is treated as "finance access" here,
        // matching manage_ledger — the only ledger-related capability.
        if ($this->grants($admin, AdminCapability::MANAGE_LEDGER, $restricted)) {
            $overdueCases = $this->ledger->overdueCases();
            $permitted[] = 'Ledger';
            $modules['ledger'] = $this->ledgerModule($overdueCases, $dateFrom, $dateTo);
            $this->attendLedger($attention, $overdueCases);
        }

        // Notification delivery analytics reuses the exact capability that
        // already gates GET /admin/notifications/deliveries (view_audit) —
        // no new capability invented for a page-specific need.
        if ($this->grants($admin, AdminCapability::VIEW_AUDIT, $restricted)) {
            $permitted[] = 'Notifications';
            $modules['notifications'] = $this->notificationsModule($dateFrom, $dateTo);
            $this->attendNotifications($attention, $modules['notifications']);
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
            'me' => array_merge(
                $this->meSection($admin, $dateFrom, $dateTo, $admin->hasCapability(AdminCapability::VIEW_AUDIT->value)),
                $this->myPerformance($admin, $dateFrom, $dateTo),
            ),
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

        // Real open-request counts grouped by MaintenanceStatus — platform-wide,
        // no capability beyond the module's own baseline gate.
        $byStatus = MaintenanceRequest::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, array_filter(MaintenanceStatus::cases(), fn ($s) => $s->isOpen())))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'summary' => $summary,
            'by_status' => $byStatus,
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
            // Platform-wide (every admin's decisions, not just this one) — why
            // listings fail review across the whole moderation team, not a
            // personal metric. Real, from the same AuditLog metadata the
            // Listing Review command centre already reads (ListingReviewService).
            'top_reasons' => $this->platformListingReasons($from, $to),
            'route' => '/app/listing-review',
        ];
    }

    /**
     * @return array<int, array{reason: string, count: int}>
     */
    private function platformListingReasons(Carbon $from, Carbon $to): array
    {
        $logs = AuditLog::where('subject_type', Listing::class)
            ->whereIn('action', ['listing_rejected', 'listing_changes_requested'])
            ->whereBetween('created_at', [$from, $to])
            ->get(['metadata']);

        $counts = [];
        foreach ($logs as $log) {
            $reason = $log->metadata['reason'] ?? null;
            if (filled($reason)) {
                $reason = trim((string) $reason);
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        arsort($counts);

        return collect($counts)->take(6)->map(fn ($count, $reason) => ['reason' => $reason, 'count' => $count])->values()->all();
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

    private function ledgerModule(Collection $overdueCases, Carbon $from, Carbon $to): array
    {
        $preview = $overdueCases->sortByDesc('display_amount_cents')->take(5)->map(fn ($e) => [
            'id' => $e['id'],
            'tenant' => $e['tenant']['full_name'] ?? null,
            'amount_cents' => $e['display_amount_cents'],
            'days_late' => $e['days_late'],
            'route' => '/app/ledger',
        ])->values()->all();

        $periodFilter = ['date_from' => $from, 'date_to' => $to];

        return [
            'overdue_count' => $overdueCases->count(),
            'overdue_cents' => $this->ledger->computeOverdue(),
            'outstanding_cents' => $this->ledger->computeOutstanding(),
            'affected_tenants' => $overdueCases->pluck('tenant_id')->filter()->unique()->count(),
            // Period-scoped (not all-time like the overdue figures above) —
            // real sums via LedgerComputationEngine, the same engine backing
            // every other ledger view in the app.
            'collected_cents' => $this->ledger->computeCollected($periodFilter),
            'charged_cents' => $this->ledger->computeRentCharged($periodFilter),
            'queue_preview' => $preview,
            'route' => '/app/ledger',
        ];
    }

    private function notificationsModule(Carbon $from, Carbon $to): array
    {
        // why: the failure figures MUST respect the selected date range like
        // every other figure in this module. Previously $failedBase was
        // all-time while the per-channel chart ($channel, built from
        // $periodBase) was range-scoped, so the same tab showed two different
        // "email failed" numbers and the tab badge/attention item never
        // responded to the range picker. Anchoring failures to [$from, $to]
        // makes the stat cards and the channel chart agree.
        $failedBase = Notification::whereBetween('created_at', [$from, $to])
            ->where(function ($q) {
                $q->whereNotNull('delivery_failed_at')->orWhereNotNull('sms_failed_at');
            });

        $periodBase = Notification::whereBetween('created_at', [$from, $to]);
        // "Sent" per channel: in-app = every notification created (creation IS
        // in-app delivery); email/SMS = an attempt was recorded either way
        // (delivered or failed) — a channel with no attempt at all isn't counted
        // as "sent". Real counts, no assumed universal multi-channel fan-out.
        $channel = [
            ['channel' => 'In-app', 'sent' => (clone $periodBase)->count(), 'failed' => 0],
            ['channel' => 'Email', 'sent' => (clone $periodBase)->where(fn ($q) => $q->whereNotNull('delivered_at')->orWhereNotNull('delivery_failed_at'))->count(), 'failed' => (clone $periodBase)->whereNotNull('delivery_failed_at')->count()],
            ['channel' => 'SMS', 'sent' => (clone $periodBase)->where(fn ($q) => $q->whereNotNull('sms_delivered_at')->orWhereNotNull('sms_failed_at'))->count(), 'failed' => (clone $periodBase)->whereNotNull('sms_failed_at')->count()],
        ];

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

        $oldest = (clone $failedBase)->orderBy('created_at', 'asc')->first();

        return [
            'failed_total' => (clone $failedBase)->count(),
            'email_failed' => (clone $failedBase)->whereNotNull('delivery_failed_at')->count(),
            'sms_failed' => (clone $failedBase)->whereNotNull('sms_failed_at')->count(),
            'recent_failures' => $recent,
            'oldest_failed_at' => $oldest?->created_at?->toIso8601String(),
            'channel' => $channel,
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
                // Reuses the same classification meSection already computes
                // sensitive_actions_period from, plus a distinct "Export" bucket
                // for the two export actions — no separate frontend logic.
                'type' => in_array($l->action, ['ledger_exported', 'admin_analytics_exported'], true)
                    ? 'Export'
                    : AuditClassifier::classification($l->action, $l->severity),
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

    private function attendMaintenance(array &$attention, array $module): void
    {
        $summary = $module['summary'];
        $ageHours = isset($summary['oldest']['age_days']) ? $summary['oldest']['age_days'] * 24 : null;

        if (($summary['urgent'] ?? 0) > 0) {
            $attention[] = $this->attentionItem(
                title: 'Urgent maintenance open',
                severity: 'critical',
                subject: $summary['urgent'].' urgent request(s)',
                area: 'Maintenance',
                route: '/app/maintenance',
                ageHours: $ageHours,
                action: 'Escalate',
            );
        }
        if (($summary['overdue'] ?? 0) > 0) {
            $attention[] = $this->attentionItem(
                title: 'Maintenance past target',
                severity: 'high',
                subject: $summary['overdue'].' request(s) overdue',
                area: 'Maintenance',
                route: '/app/maintenance',
                ageHours: $ageHours,
                action: 'Open case',
            );
        }
    }

    private function attendListings(array &$attention, array $module): void
    {
        $pending = $module['counts']['pending'] ?? 0;
        if ($pending > 0) {
            $ageHours = $module['oldest_pending_age_hours'] ?? null;
            $attention[] = $this->attentionItem(
                title: 'Listings pending review',
                severity: $pending >= 10 ? 'high' : 'medium',
                subject: $pending.' listing(s) waiting',
                area: 'Listings',
                route: '/app/listing-review',
                ageHours: $ageHours,
                action: 'Review',
            );
        }
    }

    private function attendVerifications(array &$attention, array $module): void
    {
        $summary = $module['summary'];
        $timing = $module['timing'];
        $ageHours = isset($summary['oldest_pending']['waiting_days']) ? $summary['oldest_pending']['waiting_days'] * 24 : null;

        $pending = $summary['pending'] ?? 0;
        if ($pending > 0) {
            $attention[] = $this->attentionItem(
                title: 'Verifications pending review',
                severity: 'medium',
                subject: $pending.' case(s) waiting',
                area: 'Verifications',
                route: '/app/verifications',
                ageHours: $ageHours,
                action: 'Review',
            );
        }

        $overdue = $timing['overdue_count'] ?? 0;
        if ($overdue > 0) {
            $attention[] = $this->attentionItem(
                title: 'Verification backlog',
                severity: 'medium',
                subject: $overdue.' case(s) waiting over 72 hours',
                area: 'Verification',
                route: '/app/verifications',
                ageHours: $ageHours,
                action: 'Review',
            );
        }
    }

    private function attendLedger(array &$attention, Collection $overdueCases): void
    {
        $highest = $overdueCases->sortByDesc('display_amount_cents')->first();
        if (! $highest) {
            return;
        }

        $days = $highest['days_late'];
        $attention[] = $this->attentionItem(
            title: "Rent overdue {$days} days",
            severity: $days >= 60 ? 'critical' : ($days >= 30 ? 'high' : 'medium'),
            subject: $highest['tenant']['full_name'] ?? 'Unknown tenant',
            area: 'Finance',
            route: '/app/ledger',
            ageHours: $days * 24,
            action: 'Investigate',
        );
    }

    private function attendNotifications(array &$attention, array $module): void
    {
        $failedTotal = $module['failed_total'] ?? 0;

        if ($failedTotal > 0) {
            $ageHours = ($module['oldest_failed_at'] ?? null) !== null
                ? (int) abs(now()->diffInHours(Carbon::parse($module['oldest_failed_at'])))
                : null;

            $attention[] = $this->attentionItem(
                title: 'Failed notification deliveries',
                severity: 'low',
                subject: $failedTotal.' notice(s) failed delivery',
                area: 'Communication',
                route: '/app/notifications',
                ageHours: $ageHours,
                action: 'Resend',
            );
        }
    }

    /**
     * Builds one attention/risk-queue item. `age` is a human string
     * ("2d 4h") derived from `age_hours` so the frontend never re-derives
     * timing math from a raw integer; both are included since the queue view
     * sorts by age while the card display shows the human string.
     */
    private function attentionItem(string $title, string $severity, string $subject, string $area, string $route, ?int $ageHours, string $action): array
    {
        return [
            'title' => $title,
            'severity' => $severity,
            'subject' => $subject,
            'area' => $area,
            'route' => $route,
            'age_hours' => $ageHours,
            'age' => $ageHours !== null ? $this->humanizeHours($ageHours) : null,
            'action' => $action,
        ];
    }

    private function humanizeHours(int $hours): string
    {
        if ($hours < 1) {
            return '<1h';
        }
        if ($hours < 24) {
            return "{$hours}h";
        }
        $days = intdiv($hours, 24);
        $rem = $hours % 24;

        return $rem > 0 ? "{$days}d {$rem}h" : "{$days}d";
    }

    /* ────────────────────────────────────────────────────────────────────
     * My performance — real, actor-scoped decision quality metrics.
     * Trend/outcome totals cover Listings + Verifications only: those are
     * the two record types with a reliable per-decision actor + a
     * comparable "submitted → decided" clock. Ledger actions (waive/late
     * fee) and maintenance have no equivalent single review clock, so they
     * are deliberately excluded here rather than forced into a fake average.
     * ──────────────────────────────────────────────────────────────────── */

    private const REVIEW_OUTCOME_MAP = [
        'listing_published' => 'approved',
        'listing_rejected' => 'rejected',
        'listing_changes_requested' => 'sent_back',
        'verification_approved' => 'approved',
        'verification_rejected' => 'rejected',
        'verification_needs_info' => 'sent_back',
    ];

    private function myPerformance(Admin $admin, Carbon $from, Carbon $to): array
    {
        $reviewLogs = AuditLog::where('actor_type', Admin::class)
            ->where('actor_id', $admin->id)
            ->whereIn('action', array_keys(self::REVIEW_OUTCOME_MAP))
            ->whereBetween('created_at', [$from, $to])
            ->get(['action', 'subject_type', 'subject_id', 'metadata', 'created_at']);

        $outcomeTotals = ['approved' => 0, 'rejected' => 0, 'sent_back' => 0];
        $trendByWeek = [];
        $reasonCounts = [];

        foreach ($reviewLogs as $log) {
            $outcome = self::REVIEW_OUTCOME_MAP[$log->action];
            $outcomeTotals[$outcome]++;

            $week = $log->created_at->copy()->startOfWeek()->toDateString();
            $trendByWeek[$week] ??= ['week' => $week, 'approved' => 0, 'rejected' => 0, 'sent_back' => 0];
            $trendByWeek[$week][$outcome]++;

            // Listing reasons live in AuditLog metadata (Listing has no
            // reliable decision-attribution columns of its own — see class
            // docblock). Verification reasons live on the model instead
            // (reviewed_by_admin_id/decision_reason are reliably set there),
            // tallied separately below — reading them here too would
            // double-count the same decision from two sources.
            if ($log->subject_type === Listing::class) {
                $reason = $log->metadata['reason'] ?? null;
                if ($outcome !== 'approved' && filled($reason)) {
                    $reason = trim((string) $reason);
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                }
            }
        }

        // Verification reasons live directly on the model (reliable columns
        // set at decision time), not in audit metadata — merged in here.
        $verificationDecisions = VerificationRequest::where('reviewed_by_admin_id', $admin->id)
            ->whereBetween('reviewed_at', [$from, $to])
            ->get(['status', 'decision_reason', 'submitted_at', 'reviewed_at']);

        foreach ($verificationDecisions as $req) {
            if (in_array($req->status, ['rejected', 'needs_more_information'], true) && filled($req->decision_reason)) {
                $reason = trim($req->decision_reason);
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            }
        }

        arsort($reasonCounts);
        $topReasons = collect($reasonCounts)->take(6)->map(fn ($count, $reason) => ['reason' => $reason, 'count' => $count])->values()->all();

        ksort($trendByWeek);

        return [
            'decision_trend' => array_values($trendByWeek),
            'outcome_totals' => $outcomeTotals,
            'top_reasons' => $topReasons,
            'avg_decision_hours' => $this->avgDecisionHours($reviewLogs, $verificationDecisions),
        ];
    }

    /**
     * @param  Collection<int,AuditLog>  $reviewLogs
     * @param  Collection<int,VerificationRequest>  $verificationDecisions
     * @return array{listings: float|null, verifications: float|null}
     */
    private function avgDecisionHours(Collection $reviewLogs, Collection $verificationDecisions): array
    {
        $listingLogs = $reviewLogs->filter(fn (AuditLog $l) => $l->subject_type === Listing::class);
        $listingHours = null;
        if ($listingLogs->isNotEmpty()) {
            // "submitted_at" for a listing is its creation timestamp — the
            // same proxy ListingReviewService itself uses; there is no
            // dedicated submitted-for-review column.
            $createdAt = Listing::whereIn('id', $listingLogs->pluck('subject_id')->unique())
                ->pluck('created_at', 'id');
            $hours = $listingLogs
                ->map(fn (AuditLog $l) => $createdAt->has($l->subject_id) ? abs($l->created_at->diffInHours($createdAt[$l->subject_id])) : null)
                ->filter(fn ($h) => $h !== null);
            $listingHours = $hours->isNotEmpty() ? round($hours->avg(), 1) : null;
        }

        $verificationHours = null;
        $decidedWithSubmission = $verificationDecisions->filter(fn (VerificationRequest $r) => $r->submitted_at !== null && $r->reviewed_at !== null);
        if ($decidedWithSubmission->isNotEmpty()) {
            $hours = $decidedWithSubmission->map(fn (VerificationRequest $r) => abs($r->reviewed_at->diffInHours($r->submitted_at)));
            $verificationHours = round($hours->avg(), 1);
        }

        return ['listings' => $listingHours, 'verifications' => $verificationHours];
    }
}
