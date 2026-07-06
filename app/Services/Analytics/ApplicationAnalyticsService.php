<?php

namespace App\Services\Analytics;

use App\Enums\ApplicationStatus;
use App\Models\Application;

/**
 * ApplicationAnalyticsService
 *
 * Platform-wide (admin) view of tenant application movement. No admin-facing
 * application analytics existed before this — landlords only see their own
 * queue (LandlordApplicationController). Excludes DRAFT applications:
 * they are private, tenant-only working documents until submitted, matching
 * LandlordApplicationController::index()'s own draft exclusion.
 */
class ApplicationAnalyticsService
{
    /** An in-review application older than this is considered stale. */
    private const STALE_DAYS = 5;

    public function getAnalytics(array $filters = []): array
    {
        $query = Application::query()->where('status', '!=', ApplicationStatus::DRAFT->value);
        $this->applyFilters($query, $filters);

        $byStatus = $this->countsByStatus((clone $query));

        $reviewed = (clone $query)
            ->whereNotNull('submitted_at')
            ->whereNotNull('decided_at')
            ->get();
        $reviewHours = $reviewed->map(fn (Application $a) => $a->submitted_at->floatDiffInHours($a->decided_at));

        $inReviewStatuses = [
            ApplicationStatus::SUBMITTED->value,
            ApplicationStatus::IN_REVIEW->value,
            ApplicationStatus::LANDLORD_REVIEW->value,
            ApplicationStatus::NEEDS_ACTION->value,
        ];
        $staleCutoff = now()->subDays(self::STALE_DAYS);
        $stale = (clone $query)
            ->whereIn('status', $inReviewStatuses)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<=', $staleCutoff)
            ->count();

        $submitted = (int) ($byStatus[ApplicationStatus::SUBMITTED->value] ?? 0)
            + (int) ($byStatus[ApplicationStatus::IN_REVIEW->value] ?? 0)
            + (int) ($byStatus[ApplicationStatus::LANDLORD_REVIEW->value] ?? 0)
            + (int) ($byStatus[ApplicationStatus::NEEDS_ACTION->value] ?? 0)
            + (int) ($byStatus[ApplicationStatus::APPROVED->value] ?? 0)
            + (int) ($byStatus[ApplicationStatus::REJECTED->value] ?? 0)
            + (int) ($byStatus[ApplicationStatus::WITHDRAWN->value] ?? 0);

        $decidedTotal = (int) ($byStatus[ApplicationStatus::APPROVED->value] ?? 0)
            + (int) ($byStatus[ApplicationStatus::REJECTED->value] ?? 0);

        return [
            'submitted_total' => $submitted,
            'in_review' => (int) ($byStatus[ApplicationStatus::SUBMITTED->value] ?? 0)
                + (int) ($byStatus[ApplicationStatus::IN_REVIEW->value] ?? 0)
                + (int) ($byStatus[ApplicationStatus::LANDLORD_REVIEW->value] ?? 0),
            'needs_action' => (int) ($byStatus[ApplicationStatus::NEEDS_ACTION->value] ?? 0),
            'approved' => (int) ($byStatus[ApplicationStatus::APPROVED->value] ?? 0),
            'rejected' => (int) ($byStatus[ApplicationStatus::REJECTED->value] ?? 0),
            'withdrawn' => (int) ($byStatus[ApplicationStatus::WITHDRAWN->value] ?? 0),
            'stale_count' => $stale,
            'stale_threshold_days' => self::STALE_DAYS,
            'average_review_time_hours' => $reviewHours->isNotEmpty() ? round($reviewHours->avg(), 2) : 0.0,
            'approval_rate_percentage' => $decidedTotal > 0
                ? round((($byStatus[ApplicationStatus::APPROVED->value] ?? 0) / $decidedTotal) * 100, 2)
                : 0.0,
            'submissions_by_month' => $this->submissionsByMonth($filters),
        ];
    }

    protected function countsByStatus($query): array
    {
        $rows = $query->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->get();

        $output = [];
        foreach ($rows as $row) {
            $value = $row->status instanceof ApplicationStatus ? $row->status->value : (string) $row->status;
            $output[$value] = (int) $row->aggregate;
        }

        return $output;
    }

    protected function submissionsByMonth(array $filters = []): array
    {
        $query = Application::query()
            ->where('status', '!=', ApplicationStatus::DRAFT->value)
            ->whereNotNull('submitted_at');
        $this->applyFilters($query, $filters);

        return $query->get()
            ->groupBy(fn (Application $a) => $a->submitted_at->format('Y-m'))
            ->map->count()
            ->sortKeys()
            ->toArray();
    }

    protected function applyFilters($query, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
    }
}
