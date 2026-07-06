<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMaintenanceMessageRequest;
use App\Http\Requests\StoreMaintenanceRequest;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\MaintenanceRequest;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MaintenanceService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MaintenanceRequestController (Tenant)
 *
 * Allows tenants to file, view, and cancel maintenance requests against
 * their active leases, message their landlord about a request, and attach
 * photo evidence.
 */
class MaintenanceRequestController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected MaintenanceService $maintenanceService,
        protected NotificationService $notificationService,
    ) {}

    /**
     * List the authenticated tenant's maintenance requests.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaintenanceRequest::class);

        $requests = MaintenanceRequest::where('tenant_id', $request->user()->id)
            ->with(['property', 'unit', 'contract', 'media'])
            ->latest('submitted_at')
            ->get();

        return response()->json($requests);
    }

    /**
     * File a new maintenance request.
     *
     * The contract must belong to this tenant AND be ACTIVE.
     * Property, unit, and landlord IDs are derived from the contract —
     * the client never supplies them directly.
     */
    public function store(StoreMaintenanceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Load contract and verify tenant ownership
        $contract = Contract::find($validated['contract_id']);

        if ((int) $contract->tenant_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Enforce active-lease constraint
        if ($contract->status !== ContractStatus::ACTIVE) {
            return response()->json([
                'message' => 'You can only open maintenance requests against an active lease.',
            ], 422);
        }

        $maintenanceRequest = $this->maintenanceService->createForTenant($request->user(), $contract, $validated);

        return response()->json(
            $maintenanceRequest->load(['property', 'unit', 'contract', 'media']),
            201
        );
    }

    /**
     * Display a specific maintenance request.
     */
    public function show(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('view', $maintenanceRequest);

        return response()->json(
            $maintenanceRequest->load(['property', 'unit', 'contract', 'events.actor', 'media'])
        );
    }

    /**
     * Cancel an open maintenance request.
     * Only allowed while the status is OPEN.
     */
    public function cancel(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('cancel', $maintenanceRequest);

        return response()->json(
            $this->maintenanceService->cancel($maintenanceRequest, $request->user())
        );
    }

    /**
     * Fetch the message thread with the landlord about this request, if one
     * exists yet. Reuses the same Conversation/Message models the tenant's
     * other messaging surfaces use.
     */
    public function messages(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('view', $maintenanceRequest);

        $conversation = $this->findConversation($maintenanceRequest);

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
     * Send a message to the landlord about this request, creating the
     * conversation on first use.
     */
    public function sendMessage(SendMaintenanceMessageRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('view', $maintenanceRequest);

        $tenant = $request->user();
        $conversation = $this->findConversation($maintenanceRequest) ?? Conversation::create([
            'participant_one_type' => User::class,
            'participant_one_id' => $tenant->id,
            'participant_two_type' => User::class,
            'participant_two_id' => $maintenanceRequest->landlord_id,
            'subject_type' => MaintenanceRequest::class,
            'subject_id' => $maintenanceRequest->id,
            'title' => $maintenanceRequest->title,
            'status' => 'active',
            'last_message_at' => now(),
            'last_message_by' => $tenant->id,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => User::class,
            'sender_id' => $tenant->id,
            'body' => $request->validated('body'),
            'is_read' => false,
            'is_system_message' => false,
            'has_attachments' => false,
        ]);

        $conversation->update(['last_message_at' => now(), 'last_message_by' => $tenant->id]);

        $this->auditService->log(
            actor: $tenant,
            action: 'message_sent',
            subject: $conversation,
            description: "Tenant messaged landlord on maintenance request {$maintenanceRequest->id}",
            metadata: ['message_id' => $message->id, 'maintenance_request_id' => $maintenanceRequest->id],
        );

        $eventId = "message-received:{$message->id}";
        if (! $this->notificationService->exists($maintenanceRequest->landlord, $eventId)) {
            $this->notificationService->create(
                user: $maintenanceRequest->landlord,
                type: NotificationType::MESSAGE_RECEIVED,
                title: 'New message',
                message: "{$tenant->full_name} sent you a message about maintenance request \"{$maintenanceRequest->title}\".",
                data: [
                    'event_id' => $eventId,
                    'maintenance_request_id' => $maintenanceRequest->id,
                    'message_id' => $message->id,
                ]
            );
        }

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $messages->map(fn (Message $m) => $this->formatMessage($m, $tenant))->values(),
        ], 201);
    }

    /**
     * Find the existing conversation about this request, in either
     * participant order.
     */
    private function findConversation(MaintenanceRequest $maintenanceRequest): ?Conversation
    {
        $type = User::class;
        $tenantId = $maintenanceRequest->tenant_id;
        $landlordId = $maintenanceRequest->landlord_id;

        return Conversation::where('subject_type', MaintenanceRequest::class)
            ->where('subject_id', $maintenanceRequest->id)
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
     * Format a Message for the tenant-facing JSON shape.
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
            'sender' => [
                'id' => $message->sender_id,
                'name' => $sender?->full_name,
                'is_me' => $isMe,
            ],
        ];
    }
}
