<?php

namespace App\Services\Admin;

use App\Enums\ContractStatus;
use App\Enums\ListingStatus;
use App\Enums\NotificationType;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\User;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\ListingReviewService;
use App\Services\VerificationCaseService;
use App\Support\Audit\AuditClassifier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * AdminOperationsDashboardService
 *
 * Assembles the admin/super-admin command-center payload for GET
 * /admin/dashboard: an attention queue, a cross-domain priority-case list,
 * a platform snapshot, the rent risk monitor, capped review queues, system
 * health, and human-readable recent activity.
 *
 * Every figure is either read from a real column or delegated to the
 * existing single-source-of-truth services (LedgerComputationEngine for all
 * money, VerificationCaseService/ListingReviewService/MaintenanceOverviewService
 * for their respective domains) — nothing here re-derives money math or
 * invents a metric the backend can't actually answer. Where a metric isn't
 * tracked (e.g. scheduled-job "last run" timestamps), the value is labelled
 * as approximate or omitted rather than fabricated — see systemHealth().
 */
class AdminOperationsDashboardService
{
    /** Recent-activity notices this platform treats as legally/financially significant. */
    private const CRITICAL_NOTIFICATION_TYPES = [
        NotificationType::RENT_OVERDUE->value,
        NotificationType::PAYMENT_FAILED->value,
        NotificationType::LATE_FEE_ADDED->value,
        NotificationType::VERIFICATION_REJECTED->value,
        NotificationType::CONTRACT_TERMINATED->value,
    ];

    /** Window for "recent" failed-payment signals (attention queue + system health). */
    private const FINANCE_ISSUES_WINDOW_DAYS = 7;

    public function __construct(
        private readonly LedgerComputationEngine $ledger,
        private readonly VerificationCaseService $verifications,
        private readonly ListingReviewService $listingReview,
        private readonly MaintenanceOverviewService $maintenance,
    ) {}

    public function overview(): array
    {
        $overdueCases = $this->ledger->overdueCases();
        $verificationSummary = $this->verifications->summary();
        $listingCounts = $this->listingReview->counts();
        $maintenanceSummary = $this->maintenance->summary();
        $financeIssues = $this->financeIssues();
        $notificationFailures = $this->notificationFailureSummary();

        return [
            'attention_queue' => $this->attentionQueue(
                $overdueCases,
                $verificationSummary,
                $listingCounts,
                $maintenanceSummary,
                $financeIssues,
                $notificationFailures,
            ),
            'priority_cases' => $this->priorityCases($overdueCases, $maintenanceSummary),
            'platform_snapshot' => $this->platformSnapshot($overdueCases, $maintenanceSummary, $notificationFailures),
            'rent_risk_monitor' => $this->rentRiskMonitor($overdueCases),
            'review_queues' => $this->reviewQueues(),
            'system_health' => $this->systemHealth($notificationFailures),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Section 1 — Today's Attention Queue
     * ──────────────────────────────────────────────────────────────────── */

    protected function attentionQueue(
        $overdueCases,
        array $verificationSummary,
        array $listingCounts,
        array $maintenanceSummary,
        array $financeIssues,
        array $notificationFailures,
    ): array {
        $oldestListing = null;
        if ($listingCounts['pending'] > 0) {
            $queue = $this->listingReview->queue(['status' => 'pending', 'sort' => 'oldest']);
            $oldestRow = $queue['data'][0] ?? null;
            if ($oldestRow) {
                $oldestListing = [
                    'title' => $oldestRow['title'] ?? null,
                    'landlord_name' => $oldestRow['landlord']['name'] ?? null,
                    'location' => $oldestRow['location'] ?? null,
                    'age_days' => isset($oldestRow['submitted_at'])
                        ? (int) abs(now()->diffInDays(Carbon::parse($oldestRow['submitted_at'])))
                        : 0,
                ];
            }
        }

        $overdueTenants = $overdueCases->pluck('tenant_id')->filter()->unique()->count();
        $oldestOverdue = $overdueCases->first(); // sorted due_date asc -> first is longest overdue
        $highestOverdue = $overdueCases->sortByDesc('display_amount_cents')->first();

        return [
            'verification' => [
                'pending' => $verificationSummary['pending'],
                'pending_by_role' => $verificationSummary['pending_by_role'],
                'oldest' => $verificationSummary['oldest_pending'],
                'action_route' => '/app/verifications',
            ],
            'listings' => [
                'pending' => $listingCounts['pending'],
                'oldest' => $oldestListing,
                'action_route' => '/app/listing-review',
            ],
            'rent_risk' => [
                'overdue_count' => $overdueCases->count(),
                'overdue_total_cents' => $this->ledger->computeOverdue(),
                'affected_tenants' => $overdueTenants,
                'oldest' => $oldestOverdue ? $this->rentCaseSummary($oldestOverdue) : null,
                'highest_risk' => $highestOverdue ? $this->rentCaseSummary($highestOverdue) : null,
                'action_route' => '/app/ledger',
            ],
            'finance_issues' => array_merge($financeIssues, ['action_route' => '/app/notifications']),
            'maintenance' => array_merge($maintenanceSummary, ['action_route' => '/app/maintenance']),
            'notifications' => array_merge($notificationFailures, ['action_route' => '/app/notifications']),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Section 2 — Priority Cases (cross-domain, top N)
     * ──────────────────────────────────────────────────────────────────── */

    protected function priorityCases($overdueCases, array $maintenanceSummary, int $limit = 8): array
    {
        $cases = [];

        foreach ($overdueCases->take(5) as $entry) {
            $cases[] = [
                'priority' => $entry['days_late'] >= 14 ? 'high' : 'medium',
                'case_type' => 'rent',
                'person' => $entry['tenant']['full_name'] ?? 'Unknown tenant',
                'role' => 'Tenant',
                'related_property' => $this->propertyLabelFromEntry($entry),
                'issue_summary' => number_format($entry['display_amount_cents'] / 100, 2)." GH₵ unpaid · {$entry['days_late']} days late",
                'age_days' => $entry['days_late'],
                'action_label' => 'View ledger',
                'action_route' => '/app/ledger',
            ];
        }

        $verificationOldest = $this->verifications->paginate(['status' => 'pending', 'sort' => 'oldest']);
        foreach (array_slice($verificationOldest->items(), 0, 3) as $req) {
            $ageDays = $req->submitted_at ? (int) abs(now()->diffInDays($req->submitted_at)) : 0;
            $cases[] = [
                'priority' => 'high',
                'case_type' => 'verification',
                'person' => $req->user?->full_name ?? 'Unknown applicant',
                'role' => ucfirst($req->user?->user_type?->value ?? ''),
                'related_property' => null,
                'issue_summary' => 'Identity verification pending review',
                'age_days' => $ageDays,
                'action_label' => 'Review',
                'action_route' => "/app/verifications/{$req->id}",
            ];
        }

        $listingQueue = $this->listingReview->queue(['status' => 'pending', 'sort' => 'oldest']);
        foreach (array_slice($listingQueue['data'], 0, 3) as $row) {
            $ageDays = isset($row['submitted_at']) ? (int) abs(now()->diffInDays(Carbon::parse($row['submitted_at']))) : 0;
            $cases[] = [
                'priority' => ($row['warning_count'] ?? 0) > 0 ? 'medium' : 'low',
                'case_type' => 'listing',
                'person' => $row['landlord']['name'] ?? 'Unknown landlord',
                'role' => 'Landlord',
                'related_property' => $row['location'] ?? null,
                'issue_summary' => 'New listing waiting for approval',
                'age_days' => $ageDays,
                'action_label' => 'Open',
                'action_route' => "/app/listing-review/{$row['id']}",
            ];
        }

        $maintenanceCases = $this->maintenance->cases(['status' => 'urgent', 'limit' => 3]);
        if (empty($maintenanceCases)) {
            $maintenanceCases = $this->maintenance->cases(['status' => 'overdue', 'limit' => 3]);
        }
        foreach ($maintenanceCases as $case) {
            $cases[] = [
                'priority' => $case['is_overdue'] || in_array($case['priority'], ['urgent', 'high'], true) ? 'high' : 'medium',
                'case_type' => 'maintenance',
                'person' => $case['tenant']['name'] ?? 'Unknown tenant',
                'role' => 'Tenant',
                'related_property' => $case['property'],
                'issue_summary' => $case['waiting_reason'] ?? ucfirst(str_replace('_', ' ', $case['status'])),
                'age_days' => $case['age_days'],
                // Phase A: no admin-facing maintenance detail page exists yet
                // (the existing /maintenance/:id route is scoped to the filing
                // tenant/landlord, on a different auth guard) — link to the
                // list rather than a route that would 403 for an admin.
                'action_label' => 'View case',
                'action_route' => '/app/maintenance',
            ];
        }

        $failedNotifications = Notification::query()
            ->where(function ($q) {
                $q->whereNotNull('delivery_failed_at')->orWhereNotNull('sms_failed_at');
            })
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();
        foreach ($failedNotifications as $n) {
            $cases[] = [
                'priority' => in_array($n->type?->value, self::CRITICAL_NOTIFICATION_TYPES, true) ? 'medium' : 'low',
                'case_type' => 'notification',
                'person' => $n->user?->full_name ?? 'Unknown recipient',
                'role' => null,
                'related_property' => null,
                'issue_summary' => 'Delivery failed for '.str_replace('_', ' ', $n->type?->value ?? 'notification'),
                'age_days' => (int) abs(now()->diffInDays($n->created_at)),
                'action_label' => 'Retry',
                'action_route' => '/app/notifications',
            ];
        }

        $rank = ['high' => 3, 'medium' => 2, 'low' => 1];
        usort($cases, fn ($a, $b) => [$rank[$b['priority']], $b['age_days']] <=> [$rank[$a['priority']], $a['age_days']]);

        return array_slice($cases, 0, $limit);
    }

    /* ────────────────────────────────────────────────────────────────────
     * Section 3 — Platform Snapshot
     * ──────────────────────────────────────────────────────────────────── */

    protected function platformSnapshot($overdueCases, array $maintenanceSummary, array $notificationFailures): array
    {
        $listingCounts = Listing::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $contractCounts = Contract::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $monthFilters = ['date_from' => now()->startOfMonth(), 'date_to' => now()->endOfMonth()];

        return [
            'users' => [
                'tenants' => User::tenants()->count(),
                'landlords' => User::landlords()->count(),
                'active' => User::where('is_active', true)->count(),
                'suspended' => User::where('is_active', false)->count(),
                'pending_verifications' => $this->verifications->summary()['pending'],
                'new_this_week' => User::where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'listings' => [
                'total' => (int) $listingCounts->sum(),
                'active' => (int) ($listingCounts[ListingStatus::ACTIVE->value] ?? 0),
                'pending' => (int) ($listingCounts[ListingStatus::PENDING_REVIEW->value] ?? 0),
                'draft' => (int) ($listingCounts[ListingStatus::DRAFT->value] ?? 0),
                'rejected' => (int) ($listingCounts[ListingStatus::REJECTED->value] ?? 0),
                'recently_submitted' => Listing::where('status', ListingStatus::PENDING_REVIEW->value)
                    ->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'contracts' => [
                'active' => (int) ($contractCounts[ContractStatus::ACTIVE->value] ?? 0),
                'ending_soon' => Contract::where('status', ContractStatus::ACTIVE->value)
                    ->whereBetween('end_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count(),
                'awaiting_action' => (int) ($contractCounts[ContractStatus::PENDING_TENANT->value] ?? 0),
                'with_overdue_rent' => $overdueCases->pluck('contract_id')->filter()->unique()->count(),
            ],
            'rent_ledger' => [
                'expected_this_month_cents' => $this->ledger->computeRentCharged($monthFilters),
                'collected_this_month_cents' => $this->ledger->computeCollected($monthFilters),
                'outstanding_cents' => $this->ledger->computeOutstanding(),
                'overdue_cents' => $this->ledger->computeOverdue(),
            ],
            'maintenance' => $maintenanceSummary,
            'notifications' => $notificationFailures,
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Section 4 — Rent Risk Monitor
     * ──────────────────────────────────────────────────────────────────── */

    protected function rentRiskMonitor($overdueCases): array
    {
        return [
            'summary' => [
                'outstanding_cents' => $this->ledger->computeOutstanding(),
                'overdue_cents' => $this->ledger->computeOverdue(),
                'affected_tenants' => $overdueCases->pluck('tenant_id')->filter()->unique()->count(),
                'oldest_days_late' => (int) ($overdueCases->max('days_late') ?? 0),
                'highest_overdue_cents' => (int) ($overdueCases->max('display_amount_cents') ?? 0),
            ],
            'cases' => $overdueCases->take(25)->map(fn ($entry) => $this->rentCaseSummary($entry))->values()->all(),
        ];
    }

    protected function rentCaseSummary(array $entry): array
    {
        return [
            'ledger_entry_id' => $entry['id'],
            'tenant' => $entry['tenant']['full_name'] ?? null,
            'landlord' => $entry['landlord']['full_name'] ?? null,
            'property' => $this->propertyLabelFromEntry($entry),
            'amount_cents' => $entry['display_amount_cents'],
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
     * Section 5 — Review Queues (capped previews of the full queues)
     * ──────────────────────────────────────────────────────────────────── */

    protected function reviewQueues(): array
    {
        $verificationPage = $this->verifications->paginate(['status' => 'pending', 'sort' => 'oldest']);
        $verificationRows = collect(array_slice($verificationPage->items(), 0, 5))->map(fn ($req) => [
            'id' => $req->id,
            'user_name' => $req->user?->full_name,
            'role' => $req->user?->user_type?->value,
            'submitted_at' => $req->submitted_at?->toIso8601String(),
            'document_count' => $req->documents_count,
        ])->values()->all();

        $listingQueue = $this->listingReview->queue(['status' => 'pending', 'sort' => 'oldest']);

        return [
            'verification' => $verificationRows,
            'listings' => array_slice($listingQueue['data'], 0, 5),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Section 6 — System Health
     * ──────────────────────────────────────────────────────────────────── */

    protected function systemHealth(array $notificationFailures): array
    {
        return [
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'failed_notifications' => $notificationFailures['failed_total'],
            'payment_failures_24h' => Notification::where('type', NotificationType::PAYMENT_FAILED->value)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'scheduler' => [
                'rent_generation' => $this->schedulerLogSignal('scheduler-rent.log'),
                'overdue_marking' => $this->schedulerLogSignal('scheduler-overdue.log'),
            ],
        ];
    }

    /**
     * Best-effort "last activity" signal from the scheduler's plain-text log
     * file — the app persists no last_run_at for these commands (see
     * routes/console.php). Explicitly labelled approximate; never presented
     * as a guaranteed success record.
     */
    protected function schedulerLogSignal(string $filename): array
    {
        $path = storage_path("logs/{$filename}");

        if (! File::exists($path)) {
            return ['status' => 'not_tracked', 'last_activity_at' => null];
        }

        return [
            'status' => 'approximate',
            'last_activity_at' => Carbon::createFromTimestamp(File::lastModified($path))->toIso8601String(),
        ];
    }

    /* ────────────────────────────────────────────────────────────────────
     * Section 7 — Recent Important Activity
     * ──────────────────────────────────────────────────────────────────── */

    protected function recentActivity(int $limit = 10): array
    {
        $logs = \App\Models\AuditLog::with('actor')
            ->orderByDesc('created_at')
            ->limit(200) // bounded scan window; classified/filtered below
            ->get()
            ->filter(fn ($log) => AuditClassifier::classification($log->action, $log->severity) !== 'Routine')
            ->take($limit);

        return $logs->map(fn ($log) => [
            'id' => $log->id,
            'title' => AuditClassifier::title($log->action),
            'action' => $log->action,
            'severity' => $log->severity,
            'created_at' => $log->created_at?->toIso8601String(),
            'actor' => $log->actor ? ($log->actor instanceof \App\Models\Admin ? $log->actor->name : $log->actor->full_name) : 'System',
            'detail_route' => "/app/audit/{$log->id}",
        ])->values()->all();
    }

    /* ────────────────────────────────────────────────────────────────────
     * Shared helpers
     * ──────────────────────────────────────────────────────────────────── */

    protected function financeIssues(): array
    {
        $base = Notification::where('type', NotificationType::PAYMENT_FAILED->value)
            ->where('created_at', '>=', now()->subDays(self::FINANCE_ISSUES_WINDOW_DAYS));

        $count = (clone $base)->count();
        $latest = (clone $base)->with('user:id,first_name,last_name,email')->orderByDesc('created_at')->first();

        return [
            'count' => $count,
            'window_days' => self::FINANCE_ISSUES_WINDOW_DAYS,
            'latest' => $latest ? [
                'recipient_name' => $latest->user?->full_name,
                'amount_cents' => $latest->data['amount_cents'] ?? null,
                'error' => $latest->data['error_message'] ?? null,
                'occurred_at' => $latest->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    protected function notificationFailureSummary(): array
    {
        $failedQuery = Notification::query()->where(function ($q) {
            $q->whereNotNull('delivery_failed_at')->orWhereNotNull('sms_failed_at');
        });

        $latest = (clone $failedQuery)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('created_at')
            ->first();

        return [
            'failed_total' => (clone $failedQuery)->count(),
            'critical_failed' => (clone $failedQuery)->whereIn('type', self::CRITICAL_NOTIFICATION_TYPES)->count(),
            'latest' => $latest ? [
                'recipient_name' => $latest->user?->full_name,
                'type' => $latest->type?->value,
                'error' => $latest->delivery_error ?? $latest->sms_error,
                'occurred_at' => $latest->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}
