<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationDocumentRequest;
use App\Http\Requests\StoreApplicationDraftRequest;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Requests\UpdateApplicationRequest;
use App\Models\Application;
use App\Models\Document;
use App\Models\Listing;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ApplicationController (Tenant)
 *
 * Handles a tenant's own rental applications end-to-end: draft → guided form →
 * submit → review → decision, plus per-application documents. All status
 * transitions are delegated to ApplicationService so the timeline, audit log,
 * and notifications stay consistent.
 *
 * SECURITY: All queries are scoped to the authenticated tenant's ID. Status,
 * landlord_id, and tenant_id are never accepted from the client.
 */
class ApplicationController extends Controller
{
    public function __construct(
        protected ApplicationService $applicationService,
        protected AuditService $auditService,
        protected NotificationService $notificationService,
    ) {}

    /**
     * List all applications belonging to the authenticated tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $applications = Application::where('tenant_id', $request->user()->id)
            ->with(['listing.unit.property', 'listing.primaryPhoto', 'latestEvent'])
            ->withCount(['openRequests', 'documents'])
            ->orderByRaw('COALESCE(submitted_at, created_at) DESC')
            ->get();

        return response()->json($applications);
    }

    /**
     * Submit a new application directly (quick apply: listing_id + cover_note).
     * Retained for the one-tap apply flow from a listing.
     */
    public function store(StoreApplicationRequest $request): JsonResponse
    {
        if (! $request->user()->isVerified()) {
            return response()->json([
                'message' => 'You must complete identity verification before applying to a listing.',
            ], 403);
        }

        $listing = Listing::findOrFail($request->listing_id);

        if (! $listing->isPublic()) {
            return response()->json([
                'message' => 'This listing is not available for applications',
            ], 422);
        }

        if ($this->hasOpenApplication($request->user()->id, $listing->id)) {
            return response()->json([
                'message' => 'You already have an application for this listing',
            ], 422);
        }

        $application = Application::create([
            'tenant_id' => $request->user()->id,
            'listing_id' => $listing->id,
            'landlord_id' => $listing->landlord_id,
            'status' => ApplicationStatus::SUBMITTED,
            'cover_note' => $request->cover_note,
            'submitted_at' => now(),
        ]);

        $this->applicationService->recordEvent($application, 'started', 'Application started', $request->user());
        $this->applicationService->recordEvent($application, 'submitted', 'Application submitted to landlord', $request->user());

        $this->auditService->log(
            actor: $request->user(),
            action: 'application_submitted',
            subject: $application,
            description: "Tenant submitted application for listing {$listing->id}",
            severity: 'info'
        );

        $eventId = "application-submitted:{$application->id}";
        if (! $this->notificationService->exists($listing->landlord, $eventId)) {
            $applicantName = $request->user()->full_name ?: $request->user()->email;
            $this->notificationService->create(
                user: $listing->landlord,
                type: NotificationType::APPLICATION_SUBMITTED,
                title: 'New Application Received',
                message: "{$applicantName} has submitted an application for \"{$listing->title}\".",
                data: [
                    'event_id' => $eventId,
                    'application_id' => $application->id,
                    'listing_id' => $listing->id,
                    'listing_title' => $listing->title,
                    'applicant_id' => $request->user()->id,
                    'applicant_name' => $applicantName,
                ]
            );
        }

        return response()->json($this->detail($application), 201);
    }

    /**
     * Start a DRAFT application, opening the guided multi-step form.
     */
    public function storeDraft(StoreApplicationDraftRequest $request): JsonResponse
    {
        if (! $request->user()->isVerified()) {
            return response()->json([
                'message' => 'You must complete identity verification before applying to a listing.',
            ], 403);
        }

        $listing = Listing::findOrFail($request->listing_id);

        if (! $listing->isPublic()) {
            return response()->json([
                'message' => 'This listing is not available for applications',
            ], 422);
        }

        if ($this->hasOpenApplication($request->user()->id, $listing->id)) {
            return response()->json([
                'message' => 'You already have an application for this listing',
            ], 422);
        }

        $application = $this->applicationService->createDraft($request->user(), $listing);

        return response()->json($this->detail($application), 201);
    }

    /**
     * Display a single application (owning tenant or its landlord).
     */
    public function show(Request $request, Application $application): JsonResponse
    {
        $this->authorize('view', $application);

        return response()->json($this->detail($application));
    }

    /**
     * Save the draft's structured form (partial autosave).
     */
    public function update(UpdateApplicationRequest $request, Application $application): JsonResponse
    {
        $this->authorize('update', $application);

        $this->applicationService->saveDraft($application, $request->validated()['form_data']);

        return response()->json($this->detail($application));
    }

    /**
     * Submit a draft to the landlord for review.
     */
    public function submit(Request $request, Application $application): JsonResponse
    {
        $this->authorize('submit', $application);

        $coverNote = $request->input('cover_note');
        $this->applicationService->submit($application, is_string($coverNote) ? $coverNote : null);

        return response()->json($this->detail($application->fresh()));
    }

    /**
     * Withdraw an active application.
     */
    public function withdraw(Request $request, Application $application): JsonResponse
    {
        $this->authorize('withdraw', $application);

        $this->applicationService->withdraw($application, $request->user());

        return response()->json($this->detail($application->fresh()));
    }

    /**
     * Permanently delete a draft application.
     */
    public function destroy(Request $request, Application $application): JsonResponse
    {
        $this->authorize('delete', $application);

        $this->applicationService->deleteDraft($application);

        return response()->json(['message' => 'Draft deleted.']);
    }

    /**
     * Attach a document to a specific application.
     *
     * Stored on the PRIVATE 'local' disk, linked polymorphically to the
     * application, and routed through ApplicationService so any matching
     * landlord request is resolved.
     */
    public function storeDocument(StoreApplicationDocumentRequest $request, Application $application): JsonResponse
    {
        $this->authorize('uploadDocument', $application);

        $user = $request->user();
        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            'documents/'.$user->id,
            (string) Str::uuid().'.'.$ext,
            'local'
        );

        $type = $request->input('document_type')
            ? DocumentType::from($request->input('document_type'))
            : DocumentType::APPLICATION_ATTACHMENT;

        $document = Document::create([
            'owner_user_id' => $user->id,
            'uploaded_by_id' => $user->id,
            'document_type' => $type,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'related_type' => $application->getMorphClass(),
            'related_id' => $application->id,
        ]);

        $this->auditService->log(
            actor: $user,
            action: 'document_uploaded',
            subject: $document,
            description: "Document uploaded for application {$application->id}",
            metadata: ['document_type' => $type->value, 'application_id' => $application->id],
            severity: 'info'
        );

        $this->applicationService->attachDocument($application, $document, $user);

        return response()->json([
            'document' => $document,
            'application' => $this->detail($application->fresh()),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A tenant may only have one application per listing at a time.
     *
     * Policy: every status blocks a fresh application EXCEPT withdrawn. A
     * withdrawn application was the tenant's own choice to retract, so it's
     * safe to let them start over. Rejected does NOT get a special exemption
     * here: nothing in the product elects to allow re-applying after a
     * landlord's decision, so the safe default is to block it like any other
     * non-withdrawn status and let the tenant see the decision instead.
     */
    private function hasOpenApplication(int $tenantId, int $listingId): bool
    {
        return Application::where('tenant_id', $tenantId)
            ->where('listing_id', $listingId)
            ->where('status', '!=', ApplicationStatus::WITHDRAWN->value)
            ->exists();
    }

    /**
     * Full tenant-facing detail payload (landlord_notes stays hidden via the
     * model's $hidden). Includes application documents, requests, and the
     * timeline.
     */
    private function detail(Application $application): Application
    {
        return $application->load([
            'listing.unit.property',
            'listing.primaryPhoto',
            'documents',
            'requests',
            'events',
        ]);
    }
}
