<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectListingRequest;
use App\Models\Listing;
use App\Services\ListingService;
use App\Services\AuditService;
use App\Events\ListingRejected;
use App\Enums\ListingStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
     * 
     * @return JsonResponse
     */
    public function pending(): JsonResponse
    {
        $listings = $this->listingService->getPendingReviewListings();

        return response()->json($listings);
    }

    /**
     * Approve a listing (publish it).
     * 
     * @param Request $request
     * @param Listing $listing
     * @return JsonResponse
     */
    public function approve(Request $request, Listing $listing): JsonResponse
    {
        if ($listing->status !== ListingStatus::PENDING_REVIEW) {
            return response()->json([
                'message' => 'Only listings pending review can be approved'
            ], 422);
        }

        // Publish listing (fires event)
        $listing = $this->listingService->publishListing($listing);

        // Audit log
        $this->auditService->logListingPublished($listing, auth('admin')->user());

        return response()->json([
            'message' => 'Listing approved and published',
            'listing' => $listing
        ]);
    }

    /**
     * Reject a listing.
     * 
     * @param RejectListingRequest $request
     * @param Listing $listing
     * @return JsonResponse
     */
    public function reject(RejectListingRequest $request, Listing $listing): JsonResponse
    {
        if ($listing->status !== ListingStatus::PENDING_REVIEW) {
            return response()->json([
                'message' => 'Only listings pending review can be rejected'
            ], 422);
        }

        $reason = $request->validated()['reason'];

        // Update listing
        $listing->update([
            'status' => ListingStatus::REJECTED,
            'reviewed_by' => auth('admin')->id(),
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Fire event (triggers email to landlord)
        event(new ListingRejected($listing, $reason));

        // Audit log
        $this->auditService->logListingRejected(
            $listing,
            auth('admin')->user(),
            $reason
        );

        return response()->json([
            'message' => 'Listing rejected',
            'listing' => $listing->fresh()
        ]);
    }
}
