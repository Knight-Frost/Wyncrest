<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ApplicationStatus;
use App\Enums\ListingStatus;
use App\Events\ListingSubmittedForReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\SubmitListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
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
            ->withCount([
                'applications',
                'applications as new_applications_count' => fn ($q) => $q->where('status', ApplicationStatus::SUBMITTED),
                'mediaAssets',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $listings->each(fn (Listing $listing) => $listing->missing_requirements = $this->missingRequirements($listing));

        return response()->json($listings);
    }

    /**
     * What must still be added before this listing can be submitted for
     * review. Mirrors SubmitListingRequest's own gate exactly, plus a cover
     * photo (checked here rather than there since it's advisory pre-submit
     * guidance, not itself a submit-blocking validation rule). Only
     * meaningful for draft/rejected listings — active/pending/etc already
     * cleared these bars.
     *
     * @return list<string>
     */
    private function missingRequirements(Listing $listing): array
    {
        if (! in_array($listing->status, [ListingStatus::DRAFT, ListingStatus::REJECTED], true)) {
            return [];
        }

        $missing = [];

        if (empty($listing->title)) {
            $missing[] = 'listing title';
        }

        if (empty($listing->description) || strlen($listing->description) < 50) {
            $missing[] = 'description (at least 50 characters)';
        }

        $hasCoverPhoto = ($listing->relationLoaded('primaryPhoto') && $listing->primaryPhoto)
            || ($listing->relationLoaded('photos') && $listing->photos->isNotEmpty())
            || ($listing->relationLoaded('mediaAssets') && $listing->mediaAssets->isNotEmpty())
            || ($listing->media_assets_count ?? 0) > 0;

        if (! $hasCoverPhoto) {
            $missing[] = 'cover photo';
        }

        $unit = $listing->relationLoaded('unit') ? $listing->unit : $listing->unit()->with('property')->first();

        if (! $unit) {
            $missing[] = 'unit assignment';
        } else {
            if (! $unit->rent_amount || (float) $unit->rent_amount <= 0) {
                $missing[] = 'monthly rent (set on the unit)';
            }
            if (! $unit->available_from) {
                $missing[] = 'available date (set on the unit)';
            }
            if (! $unit->property || ! $unit->property->city || ! $unit->property->state) {
                $missing[] = 'complete property address';
            }
        }

        return $missing;
    }

    /**
     * Store a newly created listing (as DRAFT).
     */
    public function store(StoreListingRequest $request, Unit $unit): JsonResponse
    {
        $this->authorize('create', Listing::class);

        // Feature gating check
        $this->featureGatingService->requireFeature($request->user(), 'listings');

        // Reject when the unit already has any in-flight listing (draft, pending_review, or active).
        // why: the frontend treats all three statuses as blocking; the backend must match.
        if ($unit->blockingListing) {
            return response()->json([
                'message' => 'This unit already has a listing in progress. Edit or remove it before creating another.',
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

        $listing->load(['unit.property', 'photos', 'reviewer', 'mediaAssets'])
            ->loadCount([
                'applications',
                'applications as new_applications_count' => fn ($q) => $q->where('status', ApplicationStatus::SUBMITTED),
            ]);

        $listing->missing_requirements = $this->missingRequirements($listing);

        return response()->json($listing);
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
        // Phase 4: Verification gate — landlord must be identity-verified before
        // submitting a listing for admin review.
        if (! $request->user()->isVerified()) {
            return response()->json([
                'message' => 'You must complete identity verification before submitting a listing for review.',
            ], 403);
        }

        // Update status. Clear any prior change-request/rejection so a
        // (re)submitted listing arrives clean — the earlier feedback no longer
        // applies once the landlord has acted on it.
        $listing->update([
            'status' => ListingStatus::PENDING_REVIEW,
            'changes_requested_reason' => null,
            'changes_requested_at' => null,
            'rejection_reason' => null,
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
     * Withdraw a pending submission back to draft, before an admin has decided.
     */
    public function withdraw(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('withdraw', $listing);

        $listing->update(['status' => ListingStatus::DRAFT]);

        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_withdrawn',
            subject: $listing,
            description: "Withdrew submission for review: {$listing->title}"
        );

        return response()->json([
            'message' => 'Submission withdrawn',
            'listing' => $listing->fresh(),
        ]);
    }

    /**
     * Deactivate an active listing, hiding it from tenants without deleting it.
     */
    public function deactivate(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('deactivate', $listing);

        $listing->update(['status' => ListingStatus::INACTIVE]);

        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_deactivated',
            subject: $listing,
            description: "Deactivated listing: {$listing->title}"
        );

        return response()->json([
            'message' => 'Listing deactivated',
            'listing' => $listing->fresh(),
        ]);
    }

    /**
     * Reactivate a previously-active listing without going back through review.
     */
    public function reactivate(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('reactivate', $listing);

        $listing->update(['status' => ListingStatus::ACTIVE]);

        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_reactivated',
            subject: $listing,
            description: "Reactivated listing: {$listing->title}",
            severity: 'info'
        );

        return response()->json([
            'message' => 'Listing is live again',
            'listing' => $listing->fresh(),
        ]);
    }

    /**
     * Archive a listing for record-keeping. Read-only until restored.
     */
    public function archive(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('archive', $listing);

        $listing->update(['status' => ListingStatus::ARCHIVED]);

        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_archived',
            subject: $listing,
            description: "Archived listing: {$listing->title}"
        );

        return response()->json([
            'message' => 'Listing archived',
            'listing' => $listing->fresh(),
        ]);
    }

    /**
     * Restore an archived listing back to an editable draft.
     */
    public function restore(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('restoreArchived', $listing);

        $listing->update(['status' => ListingStatus::DRAFT]);

        $this->auditService->log(
            actor: $request->user(),
            action: 'listing_restored',
            subject: $listing,
            description: "Restored listing from archive: {$listing->title}"
        );

        return response()->json([
            'message' => 'Listing restored to draft',
            'listing' => $listing->fresh(),
        ]);
    }

    /**
     * Real activity/review timeline for this listing, drawn from the
     * append-only audit log (never a fabricated history).
     */
    public function history(Listing $listing): JsonResponse
    {
        $this->authorize('view', $listing);

        $logs = AuditLog::where('subject_type', Listing::class)
            ->where('subject_id', $listing->id)
            ->with('actor')
            ->orderBy('created_at')
            ->get();

        return response()->json(AuditLogResource::collection($logs));
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
