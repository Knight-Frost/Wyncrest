<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\StartConversationRequest;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MessageAttachmentService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ConversationController (Tenant)
 *
 * Handles tenant-initiated messaging with landlords around listings.
 *
 * Routes (wired by supervisor in routes/api.php, tenant middleware group):
 *   GET    /api/tenant/conversations
 *   POST   /api/tenant/conversations
 *   GET    /api/tenant/conversations/{conversation}
 *   POST   /api/tenant/conversations/{conversation}/messages
 */
class ConversationController extends Controller
{
    // -------------------------------------------------------------------------
    // index — list the tenant's conversations
    // -------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();
        $userType = User::class;
        $userId = $authUser->id;

        // Fetch conversations the tenant participates in, most recent first.
        $conversations = Conversation::forParticipant($authUser)
            ->with(['participantOne', 'participantTwo', 'subject.primaryPhoto'])
            ->withCount([
                // unread = messages FROM the other party that are not yet read
                'messages as unread_count' => function ($q) use ($userType, $userId) {
                    $q->where('is_read', false)
                        ->where(function ($inner) use ($userType, $userId) {
                            // sender is NOT the current user
                            $inner->where('sender_type', '!=', $userType)
                                ->orWhere('sender_id', '!=', $userId);
                        });
                },
            ])
            ->orderByDesc('last_message_at')
            ->get();

        $result = $conversations->map(function (Conversation $conv) use ($authUser) {
            $other = $conv->otherParticipant($authUser);

            // Last message preview — quick subquery via relationship
            $lastMsg = $conv->messages()->orderByDesc('created_at')->first();

            // Thumbnail from primary photo of the listing subject
            $thumbnailUrl = null;
            if ($conv->subject instanceof Listing && $conv->subject->primaryPhoto) {
                $thumbnailUrl = $conv->subject->primaryPhoto->path;
            }

            // Other participant role (user_type enum value)
            $otherRole = null;
            if ($other !== null) {
                $otherRole = $other->user_type instanceof \BackedEnum
                    ? $other->user_type->value
                    : (string) $other->user_type;
            }

            return [
                'id' => $conv->id,
                'title' => $conv->title,
                'status' => $conv->status,
                'last_message_at' => $conv->last_message_at?->toIso8601String(),
                'unread_count' => (int) $conv->unread_count,
                'thumbnail_url' => $thumbnailUrl,
                'other_participant' => $other ? [
                    'id' => $other->id,
                    'name' => $other->full_name,
                    'role' => $otherRole,
                    'avatar_url' => $other->avatar_url,
                ] : null,
                'last_message_preview' => $lastMsg
                    ? mb_strimwidth($lastMsg->body, 0, 120, '…')
                    : null,
            ];
        });

        return response()->json($result);
    }

    // -------------------------------------------------------------------------
    // store — start (or reuse) a conversation with a listing's landlord
    // -------------------------------------------------------------------------

    public function store(StartConversationRequest $request): JsonResponse
    {
        /** @var User $tenant */
        $tenant = $request->user();
        $listing = Listing::findOrFail($request->validated('listing_id'));

        // Resolve landlord from listing
        $landlord = $listing->landlord;

        if (! $landlord instanceof User) {
            return response()->json(['message' => 'Listing has no associated landlord.'], 422);
        }

        // Prevent self-messaging
        if ((int) $landlord->id === (int) $tenant->id) {
            return response()->json(['message' => 'You cannot start a conversation with yourself.'], 422);
        }

        DB::beginTransaction();
        try {
            // Reuse existing active conversation between this tenant and landlord for this listing
            $conversation = Conversation::where('status', 'active')
                ->where('subject_type', Listing::class)
                ->where('subject_id', $listing->id)
                ->where(function ($q) use ($tenant, $landlord) {
                    $type = User::class;
                    // Either arrangement of participants
                    $q->where(function ($i) use ($type, $tenant, $landlord) {
                        $i->where('participant_one_type', $type)
                            ->where('participant_one_id', $tenant->id)
                            ->where('participant_two_type', $type)
                            ->where('participant_two_id', $landlord->id);
                    })->orWhere(function ($i) use ($type, $tenant, $landlord) {
                        $i->where('participant_one_type', $type)
                            ->where('participant_one_id', $landlord->id)
                            ->where('participant_two_type', $type)
                            ->where('participant_two_id', $tenant->id);
                    });
                })
                ->first();

            $isNew = false;
            if (! $conversation) {
                $isNew = true;
                $conversation = Conversation::create([
                    'participant_one_type' => User::class,
                    'participant_one_id' => $tenant->id,
                    'participant_two_type' => User::class,
                    'participant_two_id' => $landlord->id,
                    'subject_type' => Listing::class,
                    'subject_id' => $listing->id,
                    'title' => $listing->title,
                    'status' => 'active',
                    'last_message_at' => now(),
                    'last_message_by' => $tenant->id,
                ]);
            }

            // Create the message
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => User::class,
                'sender_id' => $tenant->id,
                'body' => $request->validated('body') ?? '',
                'is_read' => false,
                'is_system_message' => false,
                'has_attachments' => false,
            ]);

            // Store any uploaded attachments
            if ($request->hasFile('attachments')) {
                app(MessageAttachmentService::class)->storeFor($message, $request->file('attachments'));
            }

            // Update conversation activity tracking
            $conversation->update([
                'last_message_at' => now(),
                'last_message_by' => $tenant->id,
            ]);

            app(AuditService::class)->log(
                actor: $tenant,
                action: 'conversation_started',
                subject: $conversation,
                description: "Tenant started conversation about listing: {$listing->title}",
                metadata: [
                    'listing_id' => $listing->id,
                    'landlord_id' => $landlord->id,
                    'reused' => ! $isNew,
                ],
            );

            $notificationService = app(NotificationService::class);
            $eventId = "message-received:{$message->id}";
            if (! $notificationService->exists($landlord, $eventId)) {
                $notificationService->create(
                    user: $landlord,
                    type: NotificationType::MESSAGE_RECEIVED,
                    title: 'New message',
                    message: "{$tenant->full_name} sent you a message about \"{$listing->title}\".",
                    data: [
                        'event_id' => $eventId,
                        'listing_id' => $listing->id,
                        'message_id' => $message->id,
                    ]
                );
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $conversation->load('messagesLatest.attachments');

        return response()->json([
            'conversation' => $this->formatConversation($conversation, $tenant),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // show — view a conversation and mark unread messages read
    // -------------------------------------------------------------------------

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        /** @var User $authUser */
        $authUser = $request->user();

        // Mark all unread messages sent by the OTHER party as read
        Message::where('conversation_id', $conversation->id)
            ->where('is_read', false)
            ->where(function ($q) use ($authUser) {
                // sender is NOT the current user
                $q->where('sender_type', '!=', User::class)
                    ->orWhere('sender_id', '!=', $authUser->id);
            })
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $conversation->load(['participantOne', 'participantTwo', 'subject.primaryPhoto']);
        $messages = $conversation->messages()->with('attachments')->orderBy('created_at', 'asc')->get();
        $other = $conversation->otherParticipant($authUser);

        // Thumbnail from primary photo of the listing subject
        $thumbnailUrl = null;
        if ($conversation->subject instanceof Listing && $conversation->subject->primaryPhoto) {
            $thumbnailUrl = $conversation->subject->primaryPhoto->path;
        }

        // Other participant role
        $otherRole = null;
        if ($other !== null) {
            $otherRole = $other->user_type instanceof \BackedEnum
                ? $other->user_type->value
                : (string) $other->user_type;
        }

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'status' => $conversation->status,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'thumbnail_url' => $thumbnailUrl,
                'other_participant' => $other ? [
                    'id' => $other->id,
                    'name' => $other->full_name,
                    'role' => $otherRole,
                    'avatar_url' => $other->avatar_url,
                ] : null,
            ],
            'messages' => $messages->map(fn (Message $m) => $this->formatMessage($m, $authUser)),
        ]);
    }

    // -------------------------------------------------------------------------
    // sendMessage — append a message to a conversation
    // -------------------------------------------------------------------------

    public function sendMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        /** @var User $authUser */
        $authUser = $request->user();

        DB::beginTransaction();
        try {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => User::class,
                'sender_id' => $authUser->id,
                'body' => $request->validated('body') ?? '',
                'is_read' => false,
                'is_system_message' => false,
                'has_attachments' => false,
            ]);

            // Store any uploaded attachments
            if ($request->hasFile('attachments')) {
                app(MessageAttachmentService::class)->storeFor($message, $request->file('attachments'));
            }

            $conversation->update([
                'last_message_at' => now(),
                'last_message_by' => $authUser->id,
            ]);

            app(AuditService::class)->log(
                actor: $authUser,
                action: 'message_sent',
                subject: $conversation,
                description: "Message sent in conversation #{$conversation->id}",
                metadata: ['message_id' => $message->id],
            );

            $recipient = $conversation->otherParticipant($authUser);
            if ($recipient instanceof User) {
                $notificationService = app(NotificationService::class);
                $eventId = "message-received:{$message->id}";
                if (! $notificationService->exists($recipient, $eventId)) {
                    $notificationService->create(
                        user: $recipient,
                        type: NotificationType::MESSAGE_RECEIVED,
                        title: 'New message',
                        message: "{$authUser->full_name} sent you a message.",
                        data: [
                            'event_id' => $eventId,
                            'conversation_id' => $conversation->id,
                            'message_id' => $message->id,
                        ]
                    );
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Reload to pick up has_attachments update and eager-load attachments
        $message->load('attachments');

        return response()->json([
            'message' => $this->formatMessage($message, $authUser),
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Format a Conversation for the store response.
     */
    private function formatConversation(Conversation $conv, User $viewer): array
    {
        $other = $conv->otherParticipant($viewer);

        return [
            'id' => $conv->id,
            'title' => $conv->title,
            'status' => $conv->status,
            'last_message_at' => $conv->last_message_at?->toIso8601String(),
            'other_participant' => $other ? [
                'id' => $other->id,
                'name' => $other->full_name,
                'avatar_url' => $other->avatar_url,
            ] : null,
            'messages' => $conv->messagesLatest->map(fn (Message $m) => $this->formatMessage($m, $viewer))->values(),
        ];
    }

    /**
     * Format a Message, annotating whether the viewer is the sender.
     * Includes has_attachments and attachments metadata (never stored_path/disk).
     */
    private function formatMessage(Message $message, User $viewer): array
    {
        $isMe = $message->sender_type === User::class
             && (int) $message->sender_id === (int) $viewer->id;

        // Load sender lazily only when needed, and only for User senders.
        $senderName = null;
        $senderAvatar = null;
        if ($message->sender_type === User::class) {
            $sender = $message->relationLoaded('sender')
                ? $message->sender
                : User::find($message->sender_id);
            $senderName = $sender?->full_name;
            $senderAvatar = $sender?->avatar_url;
        }

        // Ensure attachments relation is loaded
        if (! $message->relationLoaded('attachments')) {
            $message->load('attachments');
        }

        $attachments = $message->attachments->map(fn ($a) => $a->toMetaArray())->values()->all();

        return [
            'id' => $message->id,
            'body' => $message->body,
            'is_read' => (bool) $message->is_read,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'has_attachments' => (bool) $message->has_attachments,
            'sender' => [
                'id' => $message->sender_id,
                'name' => $senderName,
                'avatar_url' => $senderAvatar,
                'is_me' => $isMe,
            ],
            'attachments' => $attachments,
        ];
    }
}
