<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ContractStatus;
use App\Enums\NotificationType;
use App\Enums\TerminatedBy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddContractNoteRequest;
use App\Http\Requests\RenewContractRequest;
use App\Http\Requests\SendContractMessageRequest;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\TerminateContractRequest;
use App\Models\Contract;
use App\Models\ContractLandlordNote;
use App\Models\ContractRenewal;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordContractController
 *
 * Handles landlord contract operations.
 */
class LandlordContractController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Display landlord's contracts
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = Contract::byLandlord($request->user()->id)
            ->with(['listing.unit.property', 'tenant'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($contracts);
    }

    /**
     * Store a newly created contract (draft status)
     */
    public function store(StoreContractRequest $request): JsonResponse
    {
        // Check if listing already has a contract
        $existingContract = Contract::where('listing_id', $request->listing_id)->first();
        if ($existingContract) {
            return response()->json([
                'message' => 'This listing already has a contract',
            ], 422);
        }

        // Verify listing belongs to landlord
        $listing = Listing::findOrFail($request->listing_id);
        if ($listing->landlord_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You do not own this listing',
            ], 403);
        }

        $contract = Contract::create([
            'listing_id' => $request->listing_id,
            'landlord_id' => $request->user()->id,
            'tenant_id' => $request->tenant_id,
            'rent_amount' => $request->rent_amount,
            'currency' => $request->currency ?? 'USD',
            'billing_cycle' => $request->billing_cycle ?? \App\Enums\BillingCycle::MONTHLY,
            'payment_day' => $request->payment_day,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'status' => ContractStatus::DRAFT,
        ]);

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_created',
            subject: $contract,
            description: "Created contract for listing {$listing->title}"
        );

        return response()->json([
            'message' => 'Contract created as draft',
            'contract' => $contract->load(['listing', 'tenant']),
        ], 201);
    }

    /**
     * Display the specified contract
     */
    public function show(Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        return response()->json($contract->load(['listing.unit.property', 'tenant', 'admin', 'renewals']));
    }

    /**
     * Send contract to tenant for acceptance
     */
    public function send(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('send', $contract);

        $contract->update([
            'status' => ContractStatus::PENDING_TENANT,
        ]);

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_sent',
            subject: $contract,
            description: 'Sent contract to tenant for acceptance'
        );

        // Notify the tenant that a lease is ready to sign
        $eventId = "contract-sent:{$contract->id}";
        if (! $this->notificationService->exists($contract->tenant, $eventId)) {
            $this->notificationService->create(
                user: $contract->tenant,
                type: NotificationType::CONTRACT_SENT,
                title: 'New lease ready to sign',
                message: "A lease for \"{$contract->listing->title}\" is ready for your review and signature.",
                data: [
                    'event_id' => $eventId,
                    'contract_id' => $contract->id,
                ]
            );
        }

        return response()->json([
            'message' => 'Contract sent to tenant',
            'contract' => $contract->fresh(),
        ]);
    }

    /**
     * Terminate active contract
     */
    public function terminate(TerminateContractRequest $request, Contract $contract): JsonResponse
    {
        // TerminateContractRequest@authorize() already delegates to ContractPolicy@terminate,
        // but we repeat it here for defense-in-depth visibility.
        $this->authorize('terminate', $contract);

        $contract->update([
            'status' => ContractStatus::TERMINATED,
            'terminated_by' => TerminatedBy::LANDLORD,
            'termination_reason' => $request->reason,
        ]);

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_terminated',
            subject: $contract,
            description: 'Landlord terminated contract',
            severity: 'warning'
        );

        // Notify the tenant that the landlord terminated
        $landlord = $request->user();
        $eventId = "contract-terminated:{$contract->id}:tenant";
        if (! $this->notificationService->exists($contract->tenant, $eventId)) {
            $this->notificationService->create(
                user: $contract->tenant,
                type: NotificationType::CONTRACT_TERMINATED,
                title: 'Contract Terminated',
                message: "{$landlord->full_name} has terminated your contract for \"{$contract->listing->title}\". Reason: {$request->reason}",
                data: [
                    'event_id' => $eventId,
                    'contract_id' => $contract->id,
                    'terminated_by' => 'landlord',
                    'reason' => $request->reason,
                ]
            );
        }

        return response()->json([
            'message' => 'Contract terminated',
            'contract' => $contract->fresh(),
        ]);
    }

    /**
     * Renew an ACTIVE contract in-place: mutate the same contract's end date
     * / rent amount directly (no new signed contract — listing_id is unique,
     * so a second Contract row for the same listing is impossible), while
     * recording a ContractRenewal history row so the real before/after is
     * never lost.
     */
    public function renew(RenewContractRequest $request, Contract $contract): JsonResponse
    {
        $this->authorize('renew', $contract);

        $renewal = ContractRenewal::create([
            'contract_id' => $contract->id,
            'landlord_id' => $request->user()->id,
            'previous_end_date' => $contract->end_date,
            'previous_rent_amount' => $contract->rent_amount,
            'new_end_date' => $request->new_end_date,
            'new_rent_amount' => $request->new_rent_amount ?? $contract->rent_amount,
            'note' => $request->note,
        ]);

        $contract->update([
            'end_date' => $request->new_end_date,
            'rent_amount' => $request->new_rent_amount ?? $contract->rent_amount,
        ]);

        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_renewed',
            subject: $contract,
            description: 'Landlord renewed contract',
            severity: 'info'
        );

        $landlord = $request->user();
        $eventId = "contract-renewed:{$contract->id}:{$renewal->id}:tenant";
        if (! $this->notificationService->exists($contract->tenant, $eventId)) {
            $this->notificationService->create(
                user: $contract->tenant,
                type: NotificationType::CONTRACT_RENEWED,
                title: 'Lease Renewed',
                message: "{$landlord->full_name} has renewed your lease for \"{$contract->listing->title}\", new end date {$request->new_end_date}.",
                data: [
                    'event_id' => $eventId,
                    'contract_id' => $contract->id,
                    'renewal_id' => $renewal->id,
                ]
            );
        }

        return response()->json([
            'message' => 'Lease renewed',
            'contract' => $contract->fresh()->load(['listing.unit.property', 'tenant', 'renewals']),
        ]);
    }

    /**
     * Fetch the message thread with this contract's tenant, if one exists
     * yet. Reuses the same Conversation/Message models the application
     * messaging system already uses, keyed on Contract instead of Listing —
     * a landlord and tenant sharing an active tenancy are simply the two
     * participants of one conversation.
     */
    public function messages(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        $conversation = $this->findConversation($contract);

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
     * Send a message to this contract's tenant, creating the conversation on
     * first use.
     */
    public function sendMessage(SendContractMessageRequest $request, Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        $landlord = $request->user();
        $conversation = $this->findConversation($contract) ?? Conversation::create([
            'participant_one_type' => User::class,
            'participant_one_id' => $landlord->id,
            'participant_two_type' => User::class,
            'participant_two_id' => $contract->tenant_id,
            'subject_type' => Contract::class,
            'subject_id' => $contract->id,
            'title' => $contract->listing?->title,
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
            description: "Landlord messaged tenant on contract {$contract->id}",
            metadata: ['message_id' => $message->id, 'contract_id' => $contract->id],
        );

        $eventId = "message-received:{$message->id}";
        if (! $this->notificationService->exists($contract->tenant, $eventId)) {
            $this->notificationService->create(
                user: $contract->tenant,
                type: NotificationType::MESSAGE_RECEIVED,
                title: 'New message',
                message: "{$landlord->full_name} sent you a message about \"{$contract->listing->title}\".",
                data: [
                    'event_id' => $eventId,
                    'contract_id' => $contract->id,
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
     * Find the existing conversation between this contract's tenant and
     * landlord, in either participant order.
     */
    private function findConversation(Contract $contract): ?Conversation
    {
        $type = User::class;
        $tenantId = $contract->tenant_id;
        $landlordId = $contract->landlord_id;

        return Conversation::where('subject_type', Contract::class)
            ->where('subject_id', $contract->id)
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
     * LandlordApplicationController's format (no attachments — this
     * messaging surface is text-only for now).
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
     * List this contract's landlord-authored notes.
     */
    public function notes(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        return response()->json($contract->landlordNotes()->with('landlord')->get());
    }

    /**
     * Add a landlord-authored note to this contract's case file.
     */
    public function addNote(AddContractNoteRequest $request, Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        $note = ContractLandlordNote::create([
            'contract_id' => $contract->id,
            'landlord_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_note_added',
            subject: $contract,
            description: 'Landlord added a private note',
            severity: 'info'
        );

        return response()->json($note->load('landlord'), 201);
    }
}
