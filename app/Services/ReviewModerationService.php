<?php

namespace App\Services;

use App\Enums\ReviewStatus;
use App\Models\AuditLog;
use App\Models\Review;
use Illuminate\Support\Collection;

/**
 * ReviewModerationService
 *
 * Assembles everything an admin needs to moderate a review, computed
 * strictly from real data. There is no toxicity/spam/PII scoring and no
 * "reported by host" mechanism in this platform (landlords can only
 * respond to an approved review, never report one) — the signals here are
 * all derived from the review's own status, rating, age, and the
 * reviewer's history across the platform.
 *
 * Two thresholds are the only judgment calls, named here so the policy is
 * explicit and tunable:
 *  - LONG_PENDING_HOURS flags a review that has waited a while for a decision.
 *  - LOW_RATING_MAX defines what counts as a "low rating" signal.
 */
class ReviewModerationService
{
    /** A pending/flagged review older than this is called out as long-pending. */
    private const LONG_PENDING_HOURS = 48;

    /** Ratings at or below this are surfaced as a low-rating signal. */
    private const LOW_RATING_MAX = 2;

    /**
     * The moderation queue: truthful counts plus a filtered, sorted,
     * searchable list of review summaries.
     *
     * @param  array{status?:string,search?:string,sort?:string}  $filters
     * @return array{counts:array<string,int>,data:array<int,array<string,mixed>>}
     */
    public function queue(array $filters = []): array
    {
        $status = $filters['status'] ?? 'queue';
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'risk';

        $query = Review::with(['reviewer', 'property', 'landlord', 'contract', 'moderator']);

        match ($status) {
            'approved' => $query->where('status', ReviewStatus::APPROVED->value),
            'rejected' => $query->where('status', ReviewStatus::REJECTED->value),
            'hidden' => $query->where('status', ReviewStatus::HIDDEN->value),
            'flagged' => $query->where('status', ReviewStatus::FLAGGED->value),
            'all' => null,
            default => $query->whereIn('status', [ReviewStatus::PENDING->value, ReviewStatus::FLAGGED->value]),
        };

        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(body) LIKE ?', [$like])
                    ->orWhereHas('reviewer', function ($rq) use ($like) {
                        $rq->whereRaw('LOWER(first_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                    })
                    ->orWhereHas('property', function ($pq) use ($like) {
                        $pq->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(city) LIKE ?', [$like]);
                    });
            });
        }

        // Moderation queues are small; a generous cap keeps the response
        // bounded without paginating a list the SPA scans in one view.
        $reviews = $query->latest()->limit(300)->get();

        $reviewerStats = $this->reviewerStatsFor($reviews->pluck('reviewer_user_id'));

        $data = $reviews->map(fn (Review $r) => $this->summary($r, $reviewerStats));

        $data = match ($sort) {
            'oldest' => $data->sortBy('created_at')->values(),
            'newest' => $data->sortByDesc('created_at')->values(),
            'lowrating' => $data->sortBy(fn ($row) => [$row['rating'], $row['created_at']])->values(),
            // 'risk' (default): highest-risk signal first, ties broken by whoever has waited longest.
            default => $data->sort(
                fn ($a, $b) => $this->riskRank($b) <=> $this->riskRank($a) ?: $a['created_at'] <=> $b['created_at']
            )->values(),
        };

        return [
            'counts' => $this->counts(),
            'data' => $data->values()->all(),
        ];
    }

    /**
     * Truthful counts for the queue's header tiles.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $pending = Review::where('status', ReviewStatus::PENDING->value)->count();
        $flagged = Review::where('status', ReviewStatus::FLAGGED->value)->count();

        return [
            'pending' => $pending,
            'flagged' => $flagged,
            'awaiting' => $pending + $flagged,
            'low_rated_awaiting' => Review::whereIn('status', [ReviewStatus::PENDING->value, ReviewStatus::FLAGGED->value])
                ->where('rating', '<=', self::LOW_RATING_MAX)->count(),
            'approved' => Review::where('status', ReviewStatus::APPROVED->value)->count(),
            'approved_week' => Review::where('status', ReviewStatus::APPROVED->value)
                ->where('updated_at', '>=', now()->subDays(7))->count(),
            'rejected' => Review::where('status', ReviewStatus::REJECTED->value)->count(),
            'hidden' => Review::where('status', ReviewStatus::HIDDEN->value)->count(),
            'all' => Review::count(),
        ];
    }

    /**
     * Full moderation detail for a single review.
     *
     * @return array<string,mixed>
     */
    public function detail(Review $review): array
    {
        $review->loadMissing(['reviewer', 'property', 'landlord', 'contract', 'moderator']);

        $reviewerStats = $this->reviewerStatsFor(collect([$review->reviewer_user_id]));
        $row = $this->summary($review, $reviewerStats);

        $row['reviewer_stats'] = [
            'review_count' => $reviewerStats[$review->reviewer_user_id]['review_count'] ?? 1,
            'average_rating' => $reviewerStats[$review->reviewer_user_id]['average_rating'] ?? (float) $review->rating,
        ];
        $row['timeline'] = $this->timeline($review);

        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function signals(Review $review): array
    {
        $signals = [];

        if ($review->status === ReviewStatus::FLAGGED) {
            $signals[] = ['key' => 'flagged', 'label' => 'Flagged for review', 'severity' => 'high'];
        }

        if ((int) $review->rating <= self::LOW_RATING_MAX) {
            $signals[] = ['key' => 'low_rating', 'label' => $review->rating.' star rating', 'severity' => 'medium'];
        }

        $isAwaiting = in_array($review->status, [ReviewStatus::PENDING, ReviewStatus::FLAGGED], true);
        if ($isAwaiting && $review->created_at && $review->created_at->lt(now()->subHours(self::LONG_PENDING_HOURS))) {
            $days = (int) $review->created_at->diffInDays(now());
            $signals[] = ['key' => 'long_pending', 'label' => "Waiting {$days}d for a decision", 'severity' => 'medium'];
        }

        return $signals;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function timeline(Review $review): array
    {
        $events = [[
            'key' => 'review_submitted',
            'label' => 'Review submitted',
            'at' => $this->iso($review->created_at),
            'actor' => $review->reviewer?->full_name,
            'detail' => null,
            'severity' => 'info',
        ]];

        $labels = [
            'review_approved' => ['Approved', 'success'],
            'review_rejected' => ['Rejected', 'danger'],
            'review_hidden' => ['Hidden', 'warning'],
            'review_flagged' => ['Flagged for review', 'warning'],
            'review_responded' => ['Landlord responded', 'info'],
            // Historical entries written before the audit-action naming fix.
            'review_rejectd' => ['Rejected', 'danger'],
            'review_hided' => ['Hidden', 'warning'],
            'review_flagd' => ['Flagged for review', 'warning'],
        ];

        $logs = AuditLog::query()
            ->where('subject_type', Review::class)
            ->where('subject_id', $review->id)
            ->orderBy('created_at')
            ->get();

        foreach ($logs as $log) {
            if (! isset($labels[$log->action])) {
                continue;
            }
            [$label, $severity] = $labels[$log->action];
            $events[] = [
                'key' => $log->action,
                'label' => $label,
                'at' => $this->iso($log->created_at),
                'actor' => $this->actorName($log),
                'detail' => $log->metadata['reason'] ?? null,
                'severity' => $severity,
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $events;
    }

    /**
     * One aggregate query for every reviewer in a result set: how many
     * reviews they have written (any status) and the average rating they
     * give, platform-wide. Never fabricated, never an N+1.
     *
     * @param  Collection<int,int>  $reviewerIds
     * @return array<int,array{review_count:int,average_rating:float}>
     */
    private function reviewerStatsFor(Collection $reviewerIds): array
    {
        $ids = $reviewerIds->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Review::whereIn('reviewer_user_id', $ids)
            ->selectRaw('reviewer_user_id, COUNT(*) as review_count, AVG(rating) as average_rating')
            ->groupBy('reviewer_user_id')
            ->get()
            ->keyBy('reviewer_user_id')
            ->map(fn ($row) => [
                'review_count' => (int) $row->review_count,
                'average_rating' => round((float) $row->average_rating, 1),
            ])
            ->all();
    }

    /**
     * @param  array<int,array{review_count:int,average_rating:float}>  $reviewerStats
     * @return array<string,mixed>
     */
    private function summary(Review $review, array $reviewerStats): array
    {
        $signals = $this->signals($review);
        $reviewerCount = $reviewerStats[$review->reviewer_user_id]['review_count'] ?? 1;
        if ($reviewerCount === 1) {
            $signals[] = ['key' => 'first_review', 'label' => "Reviewer's first review", 'severity' => 'info'];
        }

        return [
            'id' => $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'status' => $review->status->value,
            'moderation_reason' => $review->moderation_reason,
            'landlord_response' => $review->landlord_response,
            'responded_at' => $this->iso($review->responded_at),
            'created_at' => $this->iso($review->created_at),
            'updated_at' => $this->iso($review->updated_at),
            'reviewer' => $review->reviewer ? [
                'id' => $review->reviewer->id,
                'name' => $review->reviewer->full_name,
            ] : null,
            'property' => $review->property ? [
                'id' => $review->property->id,
                'name' => $review->property->name,
                'city' => $review->property->city,
            ] : null,
            'landlord' => $review->landlord ? [
                'id' => $review->landlord->id,
                'name' => $review->landlord->full_name,
            ] : null,
            'moderator' => $review->moderator ? [
                'id' => $review->moderator->id,
                'name' => $review->moderator->name,
            ] : null,
            'contract_status' => $review->contract?->status?->value,
            'signals' => $signals,
        ];
    }

    /** Sort weight for the 'risk' sort: flagged > low-rated pending > long-pending > the rest. */
    private function riskRank(array $row): int
    {
        $rank = 0;
        foreach ($row['signals'] as $signal) {
            $rank = max($rank, match ($signal['severity']) {
                'high' => 3,
                'medium' => 2,
                default => 1,
            });
        }

        return $rank;
    }

    private function actorName(AuditLog $log): ?string
    {
        if (! $log->actor_type || ! $log->actor_id) {
            return 'System';
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $actor */
        $actor = $log->actor_type::query()->find($log->actor_id);
        if (! $actor) {
            return null;
        }

        return $actor->full_name ?? $actor->name ?? $actor->email ?? null;
    }

    private function iso($value): ?string
    {
        return $value instanceof \Carbon\CarbonInterface ? $value->toIso8601String() : ($value ?: null);
    }
}
