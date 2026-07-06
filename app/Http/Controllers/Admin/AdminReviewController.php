<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModerateReviewRequest;
use App\Models\Admin;
use App\Models\Review;
use App\Services\ReviewModerationService;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminReviewController
 *
 * Admin moderation queue for reviews.
 */
class AdminReviewController extends Controller
{
    public function __construct(
        protected ReviewService $reviewService,
        protected ReviewModerationService $moderationService
    ) {}

    /**
     * The moderation queue: truthful counts plus a filtered, sorted,
     * searchable list of review summaries. Defaults to pending + flagged.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:queue,approved,rejected,hidden,flagged,all'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'in:risk,newest,oldest,lowrating'],
        ]);

        return response()->json($this->moderationService->queue($validated));
    }

    /**
     * Full moderation detail for a single review: reviewer history, contract
     * context, and the real audit-log-backed decision timeline.
     */
    public function show(Review $review): JsonResponse
    {
        return response()->json($this->moderationService->detail($review));
    }

    /**
     * Moderate a review: approve, reject, hide, or flag.
     */
    public function moderate(ModerateReviewRequest $request, Review $review): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $updated = $this->reviewService->moderate(
                review: $review,
                admin: $admin,
                action: $request->action,
                reason: $request->reason
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->moderationService->detail($updated));
    }
}
