<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ListingStatus;
use App\Events\ListingChangesRequested;
use App\Events\ListingRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectListingRequest;
use App\Http\Requests\RequestListingChangesRequest;
use App\Models\Listing;
use App\Models\ListingNote;
use App\Services\AuditService;
use App\Services\ListingReviewService;
use App\Services\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminListingModerationController
 *
 * Powers the admin Listing Review command centre: queue, full review detail,
 * tenant preview, approve/reject decisions, and internal admin notes.
 * Every decision is audited and (via events) notifies the landlord.
 */
class AdminListingModerationController extends Controller
{
    public function __construct(
        protected ListingService $listingService,
        protected AuditService $auditService,
        protected ListingReviewService $reviewService,
    ) {}

    /**
     * Legacy flat queue of pending listings (kept for backward compatibility).
     */
    public function pending(): JsonResponse
    {
        return response()->json($this->listingService->getPendingReviewListings());
    }

    /**
     * Review queue with truthful counts, filtering, search and sorting.
     * GET /admin/listings/review
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,all'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:newest,oldest,rent_high,rent_low,attention'],
        ]);

        return response()->json($this->reviewService->queue($filters));
    }

    /**
     * Full review detail for a single listing.
     * GET /admin/listings/review/{listing}
     */
    public function show(Listing $listing): JsonResponse
    {
        return response()->json($this->reviewService->detail($listing));
    }

    /**
     * Tenant-safe preview payload (what tenants see once published).
     * GET /admin/listings/review/{listing}/preview
     */
    public function preview(Listing $listing): JsonResponse
    {
        return response()->json($this->reviewService->preview($listing));
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

        $validated = $request->validate([
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $listing = $this->listingService->publishListing($listing);
        $this->auditService->logListingPublished($listing, $request->user());

        $this->recordOptionalNote($listing, $request->user(), $validated['internal_note'] ?? null);

        return response()->json([
            'message' => 'Listing approved and published',
            'listing' => $this->reviewService->detail($listing->fresh()),
        ]);
    }

    /**
     * Reject a listing with a required, landlord-facing reason and an
     * optional admin-only internal note.
     */
    public function reject(RejectListingRequest $request, Listing $listing): JsonResponse
    {
        if ($listing->status !== ListingStatus::PENDING_REVIEW) {
            return response()->json([
                'message' => 'Only listings pending review can be rejected',
            ], 422);
        }

        $reason = $request->validated()['reason'];

        $listing->update([
            'status' => ListingStatus::REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Fires the landlord notification.
        event(new ListingRejected($listing, $reason));

        $this->auditService->logListingRejected($listing, $request->user(), $reason);

        $this->recordOptionalNote($listing, $request->user(), $request->validated()['internal_note'] ?? null);

        return response()->json([
            'message' => 'Listing rejected',
            'listing' => $this->reviewService->detail($listing->fresh()),
        ]);
    }

    /**
     * Send a listing back to the landlord for changes.
     *
     * Unlike a rejection, this returns the listing to DRAFT with an actionable,
     * landlord-facing message. It carries no rejection stigma and does not count
     * against the landlord — it is the preferred outcome for anything fixable.
     * POST /admin/listings/review/{listing}/request-changes
     */
    public function requestChanges(RequestListingChangesRequest $request, Listing $listing): JsonResponse
    {
        if ($listing->status !== ListingStatus::PENDING_REVIEW) {
            return response()->json([
                'message' => 'Only listings pending review can be sent back for changes',
            ], 422);
        }

        $validated = $request->validated();
        $reason = $validated['reason'];

        $listing->update([
            'status' => ListingStatus::DRAFT,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'changes_requested_reason' => $reason,
            'changes_requested_at' => now(),
        ]);

        // Notify the landlord (in-app + email intent) with the message.
        event(new ListingChangesRequested($listing, $reason));

        $this->auditService->logListingChangesRequested($listing, $request->user(), $reason);

        $this->recordOptionalNote($listing, $request->user(), $validated['internal_note'] ?? null);

        return response()->json([
            'message' => 'Listing sent back to the landlord for changes',
            'listing' => $this->reviewService->detail($listing->fresh()),
        ]);
    }

    /**
     * Add an internal, admin-only note to a listing.
     * POST /admin/listings/review/{listing}/notes
     */
    public function storeNote(Request $request, Listing $listing): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $note = ListingNote::create([
            'listing_id' => $listing->id,
            'admin_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $note->load('admin');

        return response()->json([
            'message' => 'Note added',
            'note' => [
                'id' => $note->id,
                'body' => $note->body,
                'admin_id' => $note->admin_id,
                'admin_name' => $note->admin?->name,
                'created_at' => $note->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Persist an internal note captured alongside an approve/reject decision.
     */
    protected function recordOptionalNote(Listing $listing, $admin, ?string $body): void
    {
        $body = $body !== null ? trim($body) : null;
        if ($body === null || $body === '') {
            return;
        }

        ListingNote::create([
            'listing_id' => $listing->id,
            'admin_id' => $admin->id,
            'body' => $body,
        ]);
    }
}
