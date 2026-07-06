<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\MaintenanceStatus;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReopenMaintenanceRequestRequest;
use App\Http\Requests\SendMaintenanceMessageRequest;
use App\Http\Requests\StoreLandlordMaintenanceRequest;
use App\Http\Requests\UpdateMaintenanceCostsRequest;
use App\Http\Requests\UpdateMaintenanceStatusRequest;
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
use Symfony\Component\HttpFoundation\Response;

/**
 * LandlordMaintenanceController
 *
 * Allows landlords to list, view, triage, assign, and resolve maintenance
 * requests filed against their properties — plus log requests themselves,
 * message the tenant, and export their portfolio's maintenance history.
 */
class LandlordMaintenanceController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected MaintenanceService $maintenanceService,
        protected NotificationService $notificationService,
    ) {}

    /**
     * List maintenance requests for the authenticated landlord's properties.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaintenanceRequest::class);

        $requests = MaintenanceRequest::where('landlord_id', $request->user()->id)
            ->with(['tenant', 'property', 'unit', 'media'])
            ->latest('submitted_at')
            ->get();

        return response()->json($requests);
    }

    /**
     * Display a specific maintenance request with full detail (used by the
     * routed detail page — history, events, media, and messages are all
     * eager-loaded so the page works on a direct link/refresh).
     */
    public function show(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('view', $maintenanceRequest);

        return response()->json(
            $maintenanceRequest->load(['tenant', 'landlord', 'property', 'unit', 'contract', 'events.actor', 'media'])
        );
    }

    /**
     * Log a maintenance request as the landlord (e.g. from an inspection).
     */
    public function store(StoreLandlordMaintenanceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $contract = Contract::findOrFail($validated['contract_id']);

        $this->authorize('createAsLandlord', [MaintenanceRequest::class, $contract]);

        $maintenanceRequest = $this->maintenanceService->createForLandlord($request->user(), $contract, $validated);

        return response()->json(
            $maintenanceRequest->load(['tenant', 'property', 'unit', 'contract']),
            201
        );
    }

    /**
     * Update the status of a maintenance request. Assignment and resolution
     * fields are accepted on the same endpoint (see UpdateMaintenanceStatusRequest);
     * MaintenanceService stamps the appropriate lifecycle timestamps and
     * records the tenant-visible timeline entry + notification.
     */
    public function updateStatus(
        UpdateMaintenanceStatusRequest $request,
        MaintenanceRequest $maintenanceRequest
    ): JsonResponse {
        $this->authorize('updateStatus', $maintenanceRequest);

        $validated = $request->validated();
        $newStatus = MaintenanceStatus::from($validated['status']);
        $landlord = $request->user();

        $updated = match ($newStatus) {
            MaintenanceStatus::ACKNOWLEDGED => $this->maintenanceService->acknowledge($maintenanceRequest, $landlord),
            MaintenanceStatus::ASSIGNED => $this->maintenanceService->assign($maintenanceRequest, $landlord, $validated),
            MaintenanceStatus::IN_PROGRESS => $this->maintenanceService->markInProgress($maintenanceRequest, $landlord),
            MaintenanceStatus::WAITING => $this->maintenanceService->markWaiting($maintenanceRequest, $landlord, $validated['waiting_reason']),
            MaintenanceStatus::RESOLVED => $this->maintenanceService->resolve(
                $maintenanceRequest,
                $landlord,
                $validated['resolution_notes'],
                $validated['labor_cost_cents'] ?? null,
                $validated['parts_cost_cents'] ?? null,
            ),
            MaintenanceStatus::CLOSED => $this->maintenanceService->close($maintenanceRequest, $landlord),
            default => null,
        };

        if ($updated === null) {
            return response()->json(['message' => 'Unsupported status transition.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($updated);
    }

    /**
     * Reopen a resolved/closed/cancelled request. History (resolved_at/closed_at)
     * is never cleared — reopening is recorded as a new timeline event.
     */
    public function reopen(ReopenMaintenanceRequestRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('updateStatus', $maintenanceRequest);

        if (! $maintenanceRequest->status->isFinal()) {
            return response()->json(['message' => 'Only a resolved, closed, or cancelled request can be reopened.'], 422);
        }

        return response()->json(
            $this->maintenanceService->reopen($maintenanceRequest, $request->user(), $request->validated('reason'))
        );
    }

    /**
     * Edit the cost record on a request (invoice reference, paid flag, or
     * correcting the labour/parts figures) after resolution.
     */
    public function updateCosts(UpdateMaintenanceCostsRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('updateStatus', $maintenanceRequest);

        return response()->json(
            $this->maintenanceService->updateCosts($maintenanceRequest, $request->user(), $request->validated())
        );
    }

    /**
     * Fetch the message thread with the tenant about this request, if one
     * exists yet. Reuses the same Conversation/Message models as the
     * application-scoped landlord messaging.
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
     * Send a message to the tenant about this request, creating the
     * conversation on first use.
     */
    public function sendMessage(SendMaintenanceMessageRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('view', $maintenanceRequest);

        $landlord = $request->user();
        $conversation = $this->findConversation($maintenanceRequest) ?? Conversation::create([
            'participant_one_type' => User::class,
            'participant_one_id' => $landlord->id,
            'participant_two_type' => User::class,
            'participant_two_id' => $maintenanceRequest->tenant_id,
            'subject_type' => MaintenanceRequest::class,
            'subject_id' => $maintenanceRequest->id,
            'title' => $maintenanceRequest->title,
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
            description: "Landlord messaged tenant on maintenance request {$maintenanceRequest->id}",
            metadata: ['message_id' => $message->id, 'maintenance_request_id' => $maintenanceRequest->id],
        );

        $eventId = "message-received:{$message->id}";
        if (! $this->notificationService->exists($maintenanceRequest->tenant, $eventId)) {
            $this->notificationService->create(
                user: $maintenanceRequest->tenant,
                type: NotificationType::MESSAGE_RECEIVED,
                title: 'New message',
                message: "{$landlord->full_name} sent you a message about your maintenance request \"{$maintenanceRequest->title}\".",
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
            'messages' => $messages->map(fn (Message $m) => $this->formatMessage($m, $landlord))->values(),
        ], 201);
    }

    /**
     * Scoped CSV export of maintenance requests (filtered/property/tenant/
     * single/full), audited before streaming. The mockup's "certificate" is
     * a real SHA-256 checksum of the exported bytes, not a fabricated one.
     */
    public function export(Request $request): Response
    {
        $this->authorize('viewAny', MaintenanceRequest::class);

        $filters = $request->validate([
            'scope' => ['required', 'in:filtered,property,tenant,single,full'],
            'status' => ['sometimes', 'string'],
            'priority' => ['sometimes', 'string'],
            'property_id' => ['sometimes', 'integer'],
            'tenant_id' => ['sometimes', 'integer'],
            'maintenance_request_id' => ['sometimes', 'integer'],
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = MaintenanceRequest::where('landlord_id', $request->user()->id)
            ->with(['tenant', 'property', 'unit']);

        switch ($filters['scope']) {
            case 'property':
                $query->where('property_id', $filters['property_id'] ?? 0);
                break;
            case 'tenant':
                $query->where('tenant_id', $filters['tenant_id'] ?? 0);
                break;
            case 'single':
                $query->where('id', $filters['maintenance_request_id'] ?? 0);
                break;
            case 'filtered':
                if (! empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (! empty($filters['priority'])) {
                    $query->where('priority', $filters['priority']);
                }
                break;
            case 'full':
            default:
                break;
        }

        $requests = $query->orderByDesc('submitted_at')->get();

        $header = ['ID', 'Title', 'Property', 'Unit', 'Tenant', 'Category', 'Priority', 'Status', 'Reported', 'Resolved', 'Assignee', 'Total cost (GHS)'];
        $rows = $requests->map(fn (MaintenanceRequest $r) => [
            $r->id,
            $r->title,
            $r->property?->name,
            $r->unit?->unit_number,
            $r->tenant?->full_name,
            $r->category->value,
            $r->priority->value,
            $r->status->value,
            $r->submitted_at?->format('Y-m-d'),
            $r->resolved_at?->format('Y-m-d'),
            $r->assignee_name,
            $r->total_cost_cents !== null ? number_format($r->total_cost_cents / 100, 2, '.', '') : '',
        ])->all();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $checksum = hash('sha256', $csv);

        $this->auditService->log(
            actor: $request->user(),
            action: 'maintenance_exported',
            subject: null,
            description: "Landlord maintenance export generated: {$requests->count()} requests.",
            severity: 'info',
            metadata: [
                'scope' => $filters['scope'],
                'row_count' => $requests->count(),
                'reason' => $filters['reason'] ?? null,
                'checksum' => $checksum,
            ],
        );

        $filename = 'maintenance-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Export-Checksum' => $checksum,
            'X-Export-Row-Count' => (string) $requests->count(),
            'Access-Control-Expose-Headers' => 'X-Export-Checksum, X-Export-Row-Count',
        ]);
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
     * Format a Message for the landlord-facing JSON shape.
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
