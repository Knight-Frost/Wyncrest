<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ApplicationRequest;
use App\Models\Document;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ApplicationService
 *
 * Single source of truth for the rental-application lifecycle. Every state
 * transition flows through here so that the tenant-visible timeline
 * (application_events), the privileged audit log, and notifications stay in
 * lock-step. Controllers stay thin and never mutate status directly.
 */
class ApplicationService
{
    public function __construct(
        protected AuditService $auditService,
        protected NotificationService $notificationService,
    ) {}

    // -------------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------------

    /**
     * Append a tenant-visible event to an application's timeline.
     */
    public function recordEvent(
        Application $application,
        string $event,
        string $description,
        ?Model $actor = null,
        array $meta = [],
    ): ApplicationEvent {
        return $application->events()->create([
            'actor_type' => $actor ? $actor->getMorphClass() : null,
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'description' => $description,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Draft lifecycle
    // -------------------------------------------------------------------------

    /**
     * Start a new draft application for a listing. The draft is private to the
     * tenant until submitted — no landlord notification fires yet.
     */
    public function createDraft(User $tenant, Listing $listing): Application
    {
        return DB::transaction(function () use ($tenant, $listing) {
            $application = Application::create([
                'tenant_id' => $tenant->id,
                'listing_id' => $listing->id,
                'landlord_id' => $listing->landlord_id,
                'status' => ApplicationStatus::DRAFT,
                'form_data' => $this->seedFormData($tenant),
            ]);

            $this->recordEvent(
                $application,
                'started',
                'Application started',
                $tenant,
            );

            return $application;
        });
    }

    /**
     * Persist edits to a draft's structured form. Merges into any existing
     * snapshot so partial saves never clobber previously-entered sections.
     */
    public function saveDraft(Application $application, array $formData): Application
    {
        $application->form_data = array_replace_recursive(
            $application->form_data ?? [],
            $formData,
        );
        $application->save();

        return $application;
    }

    /**
     * Submit a draft (or re-submit) to the landlord for review.
     */
    public function submit(Application $application, ?string $coverNote = null): Application
    {
        return DB::transaction(function () use ($application, $coverNote) {
            if ($coverNote !== null) {
                $application->cover_note = $coverNote;
            }

            $application->status = ApplicationStatus::SUBMITTED;
            $application->submitted_at = $application->submitted_at ?? now();
            $application->save();

            $tenant = $application->tenant;

            $this->recordEvent(
                $application,
                'submitted',
                'Application submitted to landlord',
                $tenant,
            );

            $this->auditService->log(
                actor: $tenant,
                action: 'application_submitted',
                subject: $application,
                description: "Tenant submitted application {$application->id}",
                severity: 'info',
            );

            $this->notifyLandlordSubmitted($application);

            return $application->fresh();
        });
    }

    /**
     * Withdraw an active application.
     */
    public function withdraw(Application $application, User $actor): Application
    {
        $application->status = ApplicationStatus::WITHDRAWN;
        $application->withdrawn_at = now();
        $application->save();

        $this->recordEvent(
            $application,
            'withdrawn',
            'Application withdrawn by tenant',
            $actor,
        );

        $this->auditService->log(
            actor: $actor,
            action: 'application_withdrawn',
            subject: $application,
            description: "Tenant withdrew application {$application->id}",
            severity: 'info',
        );

        return $application->fresh();
    }

    /**
     * Permanently remove a draft (soft delete). Only ever called for drafts.
     */
    public function deleteDraft(Application $application): void
    {
        $application->delete();
    }

    // -------------------------------------------------------------------------
    // Documents
    // -------------------------------------------------------------------------

    /**
     * Record that the tenant attached a document to this application. Resolves
     * any matching open landlord request and, if that clears the last open
     * request, moves a NEEDS_ACTION application back into review.
     */
    public function attachDocument(Application $application, Document $document, User $actor): Application
    {
        return DB::transaction(function () use ($application, $document, $actor) {
            $label = $document->document_type->value;

            $this->recordEvent(
                $application,
                'documents_uploaded',
                'Document uploaded',
                $actor,
                ['document_type' => $label, 'document_id' => $document->id],
            );

            // Resolve open requests this document satisfies (same doc type, or
            // any document_replacement request if the tenant re-uploaded).
            $resolved = 0;
            foreach ($application->openRequests()->get() as $request) {
                $matches = $request->document_type === null
                    || $request->document_type === $label
                    || $request->type === 'document_replacement';

                if ($matches) {
                    $request->resolved_at = now();
                    $request->save();
                    $resolved++;
                }
            }

            // If the application was waiting on the tenant and nothing is open
            // anymore, hand it back to the landlord.
            if (
                $application->status === ApplicationStatus::NEEDS_ACTION
                && $resolved > 0
                && $application->openRequests()->count() === 0
            ) {
                $application->status = ApplicationStatus::IN_REVIEW;
                $application->save();

                $this->recordEvent(
                    $application,
                    'request_resolved',
                    'Requested items provided — back with the landlord',
                    $actor,
                );

                $this->notifyLandlordUpdated($application);
            }

            return $application->fresh();
        });
    }

    // -------------------------------------------------------------------------
    // Landlord / admin requests
    // -------------------------------------------------------------------------

    /**
     * Raise a request for more information / a document replacement on an
     * application. Moves the application into NEEDS_ACTION and notifies the
     * tenant.
     *
     * @param  array{message:string,type?:string,document_type?:?string,reason?:?string,due_at?:?string}  $data
     */
    public function requestInfo(
        Application $application,
        Model $actor,
        string $requesterRole,
        array $data,
    ): ApplicationRequest {
        return DB::transaction(function () use ($application, $actor, $requesterRole, $data) {
            $request = $application->requests()->create([
                'requested_by_type' => $actor->getMorphClass(),
                'requested_by_id' => $actor->getKey(),
                'requester_role' => $requesterRole,
                'type' => $data['type'] ?? 'more_info',
                'document_type' => $data['document_type'] ?? null,
                'message' => $data['message'],
                'reason' => $data['reason'] ?? null,
                'due_at' => $data['due_at'] ?? null,
            ]);

            $application->status = ApplicationStatus::NEEDS_ACTION;
            $application->reviewed_at = $application->reviewed_at ?? now();
            $application->save();

            $this->recordEvent(
                $application,
                'info_requested',
                $this->requesterLabel($requesterRole).' requested more information',
                $actor,
                ['request_id' => $request->id, 'type' => $request->type],
            );

            $this->auditService->log(
                actor: $actor,
                action: 'application_info_requested',
                subject: $application,
                description: "{$requesterRole} requested info on application {$application->id}",
                metadata: ['request_id' => $request->id, 'type' => $request->type],
                severity: 'info',
            );

            $this->notifyTenantNeedsAction($application, $request);

            return $request;
        });
    }

    /**
     * Record that the landlord opened a freshly-submitted application, moving it
     * from SUBMITTED into IN_REVIEW. Idempotent: only transitions once.
     */
    public function markOpenedByLandlord(Application $application, Model $actor): Application
    {
        if ($application->status !== ApplicationStatus::SUBMITTED) {
            return $application;
        }

        $application->status = ApplicationStatus::IN_REVIEW;
        $application->reviewed_at = $application->reviewed_at ?? now();
        $application->save();

        $this->recordEvent(
            $application,
            'opened',
            'Landlord opened the application',
            $actor,
        );

        return $application;
    }

    /**
     * Record a landlord decision on the timeline (status/audit/notification are
     * handled by the controller, which owns the landlord-facing response shape).
     */
    public function recordDecision(Application $application, Model $actor, string $decision): void
    {
        $this->recordEvent(
            $application,
            $decision === 'approved' ? 'approved' : 'rejected',
            $decision === 'approved'
                ? 'Application approved'
                : 'Landlord did not move forward',
            $actor,
            ['decision' => $decision],
        );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Pre-fill the draft form from what we already truthfully know about the
     * tenant (name, email, phone). Everything else is left blank for them.
     */
    protected function seedFormData(User $tenant): array
    {
        return [
            'personal' => [
                'first' => $tenant->first_name ?? '',
                'last' => $tenant->last_name ?? '',
                'email' => $tenant->email ?? '',
                'phone' => $tenant->phone ?? '',
            ],
        ];
    }

    protected function requesterLabel(string $role): string
    {
        return match ($role) {
            'admin', 'platform' => 'Wyncrest',
            default => 'The landlord',
        };
    }

    protected function notifyLandlordSubmitted(Application $application): void
    {
        $landlord = $application->landlord;
        if (! $landlord) {
            return;
        }

        $eventId = "application-submitted:{$application->id}";
        if ($this->notificationService->exists($landlord, $eventId)) {
            return;
        }

        $listing = $application->listing;
        $applicantName = $application->tenant?->full_name ?: $application->tenant?->email;

        $this->notificationService->create(
            user: $landlord,
            type: NotificationType::APPLICATION_SUBMITTED,
            title: 'New Application Received',
            message: "{$applicantName} has submitted an application for \"{$listing?->title}\".",
            data: [
                'event_id' => $eventId,
                'application_id' => $application->id,
                'listing_id' => $listing?->id,
                'listing_title' => $listing?->title,
                'applicant_id' => $application->tenant_id,
                'applicant_name' => $applicantName,
            ],
        );
    }

    protected function notifyLandlordUpdated(Application $application): void
    {
        $landlord = $application->landlord;
        if (! $landlord) {
            return;
        }

        $eventId = "application-updated:{$application->id}:".now()->timestamp;
        $applicantName = $application->tenant?->full_name ?: $application->tenant?->email;

        $this->notificationService->create(
            user: $landlord,
            type: NotificationType::APPLICATION_UPDATED,
            title: 'Application Updated',
            message: "{$applicantName} provided the requested information for \"{$application->listing?->title}\".",
            data: [
                'event_id' => $eventId,
                'application_id' => $application->id,
                'listing_id' => $application->listing_id,
            ],
        );
    }

    protected function notifyTenantNeedsAction(Application $application, ApplicationRequest $request): void
    {
        $tenant = $application->tenant;
        if (! $tenant) {
            return;
        }

        $eventId = "application-request:{$request->id}";
        if ($this->notificationService->exists($tenant, $eventId)) {
            return;
        }

        $this->notificationService->create(
            user: $tenant,
            type: NotificationType::APPLICATION_NEEDS_ACTION,
            title: 'Action Needed on Your Application',
            message: "Your application for \"{$application->listing?->title}\" needs your attention: {$request->message}",
            data: [
                'event_id' => $eventId,
                'application_id' => $application->id,
                'request_id' => $request->id,
                'listing_id' => $application->listing_id,
            ],
        );
    }
}
