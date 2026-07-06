<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideApplicationRequest;
use App\Http\Requests\RequestApplicationInfoRequest;
use App\Http\Requests\SendApplicationMessageRequest;
use App\Models\Application;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\TenantReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordApplicationController
 *
 * Handles a landlord's view of applications directed at their listings.
 * SECURITY: All queries are scoped to the authenticated landlord's ID.
 * landlord_notes is hidden on the model but made visible here because this
 * controller is exclusively landlord-facing.
 */
class LandlordApplicationController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected TenantReadinessService $readinessService,
        protected NotificationService $notificationService,
        protected ApplicationService $applicationService,
    ) {}

    /**
     * List all applications for the authenticated landlord's listings.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'listing_id' => ['nullable', 'integer', 'exists:listings,id'],
        ]);

        $applications = Application::where('landlord_id', $request->user()->id)
            // A tenant's still-private draft is not yet "with" the landlord.
            ->where('status', '!=', ApplicationStatus::DRAFT->value)
            ->when($request->filled('listing_id'), fn ($q) => $q->where('listing_id', $request->integer('listing_id')))
            ->with(['tenant', 'listing.unit.property', 'documents'])
            ->latest()
            ->get();

        // Landlords may see their own internal notes
        $applications->each->makeVisible('landlord_notes');

        $payload = $applications->map(fn (Application $application) => $this->withReadiness($application));

        return response()->json($payload);
    }

    /**
     * Display a single application (landlord must own the listing).
     */
    public function show(Request $request, Application $application): JsonResponse
    {
        $this->authorize('view', $application);

        // Opening a freshly-submitted application moves it into review and
        // records a timeline event for the tenant ("Landlord opened…").
        $this->applicationService->markOpenedByLandlord($application, $request->user());

        $application = $application->fresh()->makeVisible('landlord_notes');
        $application->load(['tenant', 'listing.unit.property', 'documents', 'requests', 'events']);

        return response()->json($this->withReadiness($application));
    }

    /**
     * Approve or reject an application.
     */
    public function decide(DecideApplicationRequest $request, Application $application): JsonResponse
    {
        $this->authorize('decide', $application);

        $decision = $request->decision; // 'approved' or 'rejected'

        $application->status = ApplicationStatus::from($decision);
        $application->decided_at = now();
        $application->reviewed_at = $application->reviewed_at ?? now();
        $application->decision_reason = $request->decision_reason;
        $application->save();

        $this->applicationService->recordDecision($application, $request->user(), $decision);

        $this->auditService->log(
            actor: $request->user(),
            action: 'application_decided',
            subject: $application,
            description: "Landlord {$decision} application {$application->id}",
            metadata: ['decision' => $decision],
            severity: 'info'
        );

        // Notify the tenant of the decision
        $eventId = "application-decided:{$application->id}";
        $notificationType = $decision === 'approved'
            ? NotificationType::APPLICATION_APPROVED
            : NotificationType::APPLICATION_REJECTED;

        $listing = $application->listing;
        $decisionLabel = $decision === 'approved' ? 'approved' : 'rejected';
        $listingTitle = $listing?->title ?? 'your application';
        $reason = $request->decision_reason;

        $message = "Your application for \"{$listingTitle}\" has been {$decisionLabel}.";
        if ($reason) {
            $message .= " Reason: {$reason}";
        }

        if (! $this->notificationService->exists($application->tenant, $eventId)) {
            $this->notificationService->create(
                user: $application->tenant,
                type: $notificationType,
                title: 'Application '.ucfirst($decisionLabel),
                message: $message,
                data: [
                    'event_id' => $eventId,
                    'application_id' => $application->id,
                    'listing_id' => $listing?->id,
                    'listing_title' => $listingTitle,
                    'decision' => $decision,
                    'decision_reason' => $reason,
                ]
            );
        }

        $fresh = $application->fresh()->makeVisible('landlord_notes');
        $fresh->load(['tenant', 'listing.unit.property']);

        return response()->json($this->withReadiness($fresh));
    }

    /**
     * Request more information / a document replacement from the tenant. Moves
     * the application into NEEDS_ACTION and notifies the tenant.
     */
    public function requestInfo(RequestApplicationInfoRequest $request, Application $application): JsonResponse
    {
        $this->authorize('requestInfo', $application);

        $this->applicationService->requestInfo(
            $application,
            $request->user(),
            'landlord',
            $request->validated(),
        );

        $fresh = $application->fresh()->makeVisible('landlord_notes');
        $fresh->load(['tenant', 'listing.unit.property', 'documents', 'requests', 'events']);

        return response()->json($this->withReadiness($fresh));
    }

    /**
     * Toggle whether this applicant is on the landlord's shortlist. Purely an
     * internal organisational flag — it never appears on the tenant's own
     * timeline, only in the audit log.
     */
    public function toggleShortlist(Request $request, Application $application): JsonResponse
    {
        $this->authorize('shortlist', $application);

        $application->shortlisted_at = $application->shortlisted_at ? null : now();
        $application->save();

        $this->auditService->log(
            actor: $request->user(),
            action: $application->shortlisted_at ? 'application_shortlisted' : 'application_unshortlisted',
            subject: $application,
            description: $application->shortlisted_at
                ? "Landlord shortlisted application {$application->id}"
                : "Landlord removed application {$application->id} from the shortlist",
            severity: 'info',
        );

        $fresh = $application->fresh()->makeVisible('landlord_notes');
        $fresh->load(['tenant', 'listing.unit.property', 'documents']);

        return response()->json($this->withReadiness($fresh));
    }

    /**
     * Fetch the message thread with this applicant (about their listing), if
     * one exists yet. Reuses the same Conversation/Message models the tenant
     * messaging system already uses — a landlord and tenant sharing an
     * application are simply the two participants of one conversation.
     */
    public function messages(Request $request, Application $application): JsonResponse
    {
        $this->authorize('view', $application);

        $conversation = $this->findConversation($application);

        if (! $conversation) {
            return response()->json(['conversation_id' => null, 'messages' => []]);
        }

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $messages->map(fn (Message $m) => $this->formatMessage($m, $request->user()))->values(),
        ]);
    }

    /**
     * Send a message to this applicant, creating the conversation on first use.
     */
    public function sendMessage(SendApplicationMessageRequest $request, Application $application): JsonResponse
    {
        $this->authorize('view', $application);

        $landlord = $request->user();
        $conversation = $this->findConversation($application) ?? Conversation::create([
            'participant_one_type' => User::class,
            'participant_one_id' => $landlord->id,
            'participant_two_type' => User::class,
            'participant_two_id' => $application->tenant_id,
            'subject_type' => Listing::class,
            'subject_id' => $application->listing_id,
            'title' => $application->listing?->title,
            'status' => 'active',
            'last_message_at' => now(),
            'last_message_by' => $landlord->id,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $landlord->id,
            'body' => $request->validated('body'),
            'is_read' => false,
            'is_system_message' => false,
            'has_attachments' => false,
        ]);

        $conversation->update(['last_message_at' => now(), 'last_message_by' => $landlord->id]);

        $this->auditService->log(
            actor: $landlord,
            action: 'message_sent',
            subject: $conversation,
            description: "Landlord messaged applicant on application {$application->id}",
            metadata: ['message_id' => $message->id, 'application_id' => $application->id],
        );

        $eventId = "message-received:{$message->id}";
        if (! $this->notificationService->exists($application->tenant, $eventId)) {
            $this->notificationService->create(
                user: $application->tenant,
                type: NotificationType::MESSAGE_RECEIVED,
                title: 'New message',
                message: "{$landlord->full_name} sent you a message about your application for \"{$application->listing?->title}\".",
                data: [
                    'event_id' => $eventId,
                    'application_id' => $application->id,
                    'message_id' => $message->id,
                ]
            );
        }

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $messages->map(fn (Message $m) => $this->formatMessage($m, $landlord))->values(),
        ], 201);
    }

    /**
     * Find the existing conversation between this application's tenant and
     * landlord about this listing, in either participant order.
     */
    private function findConversation(Application $application): ?Conversation
    {
        $type = User::class;
        $tenantId = $application->tenant_id;
        $landlordId = $application->landlord_id;

        return Conversation::where('subject_type', Listing::class)
            ->where('subject_id', $application->listing_id)
            ->where(function ($query) use ($type, $tenantId, $landlordId) {
                $query->where(function ($inner) use ($type, $tenantId, $landlordId) {
                    $inner->where('participant_one_type', $type)->where('participant_one_id', $tenantId)
                        ->where('participant_two_type', $type)->where('participant_two_id', $landlordId);
                })->orWhere(function ($inner) use ($type, $tenantId, $landlordId) {
                    $inner->where('participant_one_type', $type)->where('participant_one_id', $landlordId)
                        ->where('participant_two_type', $type)->where('participant_two_id', $tenantId);
                });
            })
            ->first();
    }

    /**
     * Format a Message for the landlord-facing JSON shape, mirroring
     * ConversationController's format (no attachments — landlord messaging is
     * text-only for now).
     */
    private function formatMessage(Message $message, User $viewer): array
    {
        $isMe = $message->sender_type === User::class
            && (int) $message->sender_id === (int) $viewer->id;

        $sender = $message->relationLoaded('sender') ? $message->sender : User::find($message->sender_id);

        return [
            'id' => $message->id,
            'body' => $message->body,
            'is_read' => (bool) $message->is_read,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'has_attachments' => false,
            'sender' => [
                'id' => $message->sender_id,
                'name' => $sender?->full_name,
                'avatar_url' => $sender?->avatar_url,
                'is_me' => $isMe,
            ],
            'attachments' => [],
        ];
    }

    /**
     * Serialize an application and attach the tenant's rental-readiness summary.
     *
     * The readiness key is merged into the array form so it survives JSON
     * serialization regardless of the model's hidden/appends config.
     */
    private function withReadiness(Application $application): array
    {
        return array_merge(
            $application->toArray(),
            ['readiness' => $this->readinessService->compute($application->tenant)]
        );
    }
}
