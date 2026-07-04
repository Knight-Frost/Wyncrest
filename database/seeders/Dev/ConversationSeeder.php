<?php

namespace Database\Seeders\Dev;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;

/**
 * ConversationSeeder — real tenant↔landlord messaging threads.
 *
 * Messaging is fully built in the platform (Conversation/Message models +
 * endpoints + MessagesPage), but nothing seeded it, so every inbox landed empty.
 * This seeds a small, strictly truthful set of conversations, each anchored to a
 * REAL listing the two people are genuinely connected to:
 *
 *   1. tenant.good1 ↔ landlord.1  — a settled thread about their occupied unit,
 *      every message READ (tests a normal, fully-read inbox + thread view).
 *   2. tenant.owing ↔ landlord.2  — the landlord's unread rent reminder, tied to
 *      the tenant's REAL overdue balance (tests an unread badge on the TENANT side).
 *   3. tenant.good2 ↔ landlord.1  — a question from a live applicant about the
 *      available unit they applied to, unread by the landlord (tests an unread
 *      badge on the LANDLORD side, tied to a real application).
 *
 * Unread is DERIVED by the app (messages where is_read=false AND sender != viewer),
 * so we seed the underlying rows truthfully rather than any fabricated count.
 * tenant.good3 / tenant.good4 are left with NO conversations on purpose so the
 * empty-inbox state is testable. Idempotent: a conversation that already carries
 * seeded messages is left untouched on re-seed.
 */
class ConversationSeeder extends DevSeeder
{
    public function run(): void
    {
        $conversations = 0;
        $messages = 0;

        // 1. Settled thread — everything read.
        [$c, $m] = $this->thread('tenant.good1', 'landlord.1', 'ridge-court', '2B-04', [
            ['tenant', 'Hi, is there a second parking space available for the unit?', true, 6, 0],
            ['landlord', 'Hello! Yes, one visitor bay can be reserved for you. I\'ll arrange it.', true, 6, 3],
            ['tenant', 'Wonderful, thank you so much!', true, 5, 0],
        ]);
        $conversations += $c;
        $messages += $m;

        // 2. Overdue rent reminder — the landlord's latest message is UNREAD by the
        //    tenant (tenant sees an unread badge). Tied to a real overdue balance.
        [$c, $m] = $this->thread('tenant.owing', 'landlord.2', 'harbour-view', 'GA-05', [
            ['tenant', 'Apologies, I\'ve had a delay this month. I\'ll settle the balance shortly.', true, 4, 0],
            ['landlord', 'Understood, thank you for letting me know. Just a friendly reminder the rent is now overdue.', false, 1, 0],
        ]);
        $conversations += $c;
        $messages += $m;

        // 3. Applicant question — the tenant's message is UNREAD by the landlord
        //    (landlord sees an unread badge). Tied to a real live application.
        [$c, $m] = $this->thread('tenant.good2', 'landlord.1', 'ridge-court', '1B-07', [
            ['tenant', 'Hi, I submitted an application for this unit yesterday — is it still available for viewing this week?', false, 1, 0],
        ]);
        $conversations += $c;
        $messages += $m;

        $this->command?->info("  ✓ Conversations: {$conversations} threads, {$messages} messages (read + unread; two inboxes left empty).");
    }

    /**
     * Create one conversation between a tenant and a landlord about a specific
     * unit's listing, with an ordered set of messages. Returns [conversationsAdded,
     * messagesAdded]. Idempotent — skips message seeding if the thread already exists.
     *
     * @param  array<int,array{0:string,1:string,2:bool,3:int,4:int}>  $script
     *                                                                          [senderRole, body, isRead, daysAgo, hourOffset]
     * @return array{0:int,1:int}
     */
    protected function thread(string $tenantKey, string $landlordKey, string $propertyKey, string $unitNumber, array $script): array
    {
        $tenant = $this->user($tenantKey);
        $landlord = $this->user($landlordKey);
        $property = $this->property($propertyKey);
        if (! $tenant || ! $landlord || ! $property) {
            return [0, 0];
        }

        $unit = $property->units()->where('unit_number', $unitNumber)->first();
        $listing = $unit ? $this->listingForUnit($unit) : null;
        if (! $listing) {
            return [0, 0];
        }

        $conversation = Conversation::firstOrCreate(
            [
                'participant_one_type' => User::class,
                'participant_one_id' => $tenant->id,
                'participant_two_type' => User::class,
                'participant_two_id' => $landlord->id,
                'subject_type' => Listing::class,
                'subject_id' => $listing->id,
            ],
            [
                'title' => $listing->title,
                'status' => 'active',
            ],
        );

        // Already seeded on a prior run — don't stack duplicate messages.
        if (! $conversation->wasRecentlyCreated && $conversation->messages()->exists()) {
            return [0, 0];
        }

        $messages = 0;
        $lastSenderId = null;
        $lastAt = null;

        foreach ($script as [$role, $body, $isRead, $daysAgo, $hourOffset]) {
            $sender = $role === 'tenant' ? $tenant : $landlord;
            $at = now()->subDays($daysAgo)->addHours($hourOffset);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => User::class,
                'sender_id' => $sender->id,
                'body' => $body,
                'is_read' => $isRead,
                'read_at' => $isRead ? $at->copy()->addHours(1) : null,
                'is_system_message' => false,
                'has_attachments' => false,
            ]);
            $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

            $lastSenderId = $sender->id;
            $lastAt = $at;
            $messages++;
        }

        $conversation->forceFill([
            'last_message_at' => $lastAt,
            'last_message_by' => $lastSenderId,
        ])->save();

        return [1, $messages];
    }
}
