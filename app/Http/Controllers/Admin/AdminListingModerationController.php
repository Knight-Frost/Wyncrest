<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ListingStatus;
use App\Events\ListingRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectListingRequest;
use App\Models\Listing;
use App\Services\AuditService;
use App\Services\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminListingModerationController
 *
 * Handles admin listing moderation (approve/reject).
 * All actions are audited.
 */
class AdminListingModerationController extends Controller
{
    public function __construct(
        protected ListingService $listingService,
        protected AuditService $auditService
    ) {}

    /**
     * Get all listings pending review.
     */
    public function pending(): JsonResponse
    {
        $listings = $this->listingService->getPendingReviewListings();

        return response()->json($listings);
    }

    /**
     * Approve a listing (publish it).
     */
    public function approve(Request $request, Listing $listing): JsonResponse
    {
        if ($listing->status !== ListingStatus::PENDING_REVIEW) {
            return response()->json([
                'message' => 'Only listings pending review can be approved',
            ], 422);
        }

        // Publish listing (fires event)
        $listing = $this->listingService->publishListing($listing);

        // Audit log
        $this->auditService->logListingPublished($listing, $request->user());

        return response()->json([
            'message' => 'Listing approved and published',
            'listing' => $listing,
        ]);
    }

    /**
     * Reject a listing.
     */
    public function reject(RejectListingRequest $request, Listing $listing): JsonResponse
    {
        if ($listing->status !== ListingStatus::PENDING_REVIEW) {
            return response()->json([
                'message' => 'Only listings pending review can be rejected',
            ], 422);
        }

        $reason = $request->validated()['reason'];

        // Update listing
        $listing->update([
            'status' => ListingStatus::REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Fire event (triggers email to landlord)
        event(new ListingRejected($listing, $reason));

        // Audit log
        $this->auditService->logListingRejected(
            $listing,
            $request->user(),
            $reason
        );

        return response()->json([
            'message' => 'Listing rejected',
            'listing' => $listing->fresh(),
        ]);
    }
}
