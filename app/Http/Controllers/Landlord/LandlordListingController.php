<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ListingStatus;
use App\Events\ListingSubmittedForReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\SubmitListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Models\Listing;
use App\Models\Unit;
use App\Services\AuditService;
use App\Services\FeatureGatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordListingController
 *
 * Handles landlord listing management with feature gating.
 * Listing lifecycle: Draft → Submit → (Admin Review) → Active
 */
class LandlordListingController extends Controller
{
    public function __construct(
        protected FeatureGatingService $featureGatingService,
        protected AuditService $auditService
    ) {}

    /**
     * Display a listing of the landlord's listings.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Listing::class);

        // Feature gating check
        $this->featureGatingService->requireFeature($request->user(), 'listings');

        $listings = $request->user()
            ->listings()
            ->with(['unit.property', 'primaryPhoto'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($listings);
    }

    /**
     * Store a newly created listing (as DRAFT).
     */
    public function store(StoreListingRequest $request, Unit $unit): JsonResponse
    {
        $this->authorize('create', Listing::class);

        // Feature gating check
        $this->featureGatingService->requireFeature($request->user(), 'listings');

        // Check if unit already has an active listing
        if ($unit->activeListing) {
            return response()->json([
                'message' => 'This unit already has an active listing',
            ], 422);
        }

        $listing = new Listing($request->validated());
        $listing->unit_id = $unit->id;
        $listing->landlord_id = $request->user()->id;
        $listing->status = ListingStatus::DRAFT;
        $listing->save();

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_created',
            subject: $listing,
            description: "Created draft listing: {$listing->title}"
        );

        return response()->json([
            'message' => 'Listing created as draft',
            'listing' => $listing->load(['unit.property', 'photos']),
        ], 201);
    }

    /**
     * Display the specified listing.
     */
    public function show(Listing $listing): JsonResponse
    {
        $this->authorize('view', $listing);

        return response()->json($listing->load(['unit.property', 'photos', 'reviewer']));
    }

    /**
     * Update the specified listing.
     */
    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        $oldValues = $listing->only(array_keys($request->validated()));

        $listing->update($request->validated());

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_updated',
            subject: $listing,
            description: "Updated listing: {$listing->title}",
            oldValues: $oldValues,
            newValues: $listing->only(array_keys($request->validated()))
        );

        return response()->json([
            'message' => 'Listing updated successfully',
            'listing' => $listing->fresh(['unit.property', 'photos']),
        ]);
    }

    /**
     * Submit listing for admin review.
     */
    public function submit(SubmitListingRequest $request, Listing $listing): JsonResponse
    {
        // Update status
        $listing->update([
            'status' => ListingStatus::PENDING_REVIEW,
        ]);

        // Fire event (triggers email to admin)
        event(new ListingSubmittedForReview($listing));

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_submitted',
            subject: $listing,
            description: "Submitted listing for review: {$listing->title}",
            severity: 'info'
        );

        return response()->json([
            'message' => 'Listing submitted for admin review',
            'listing' => $listing->fresh(),
        ]);
    }

    /**
     * Remove the specified listing (soft delete).
     */
    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('delete', $listing);

        $listing->delete();

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_deleted',
            subject: $listing,
            description: "Deleted listing: {$listing->title}"
        );

        return response()->json([
            'message' => 'Listing deleted successfully',
        ]);
    }
}
