<?php

namespace Database\Seeders\Dev;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;

/**
 * NotificationSeeder — a small, strictly truthful set of in-app notifications.
 *
 * Every notification points at a state that ACTUALLY exists in the seeded graph:
 *   - the owing tenant has a real overdue rent entry        → RENT_OVERDUE
 *   - good-standing tenants have real settled payments      → PAYMENT_SUCCEEDED
 *   - landlords with live applicants have real applications  → APPLICATION_SUBMITTED
 *   - landlords with seeded reviews have a real review       → REVIEW_SUBMITTED
 *
 * No noise, no fabricated delivery failures, no notifications for events that did
 * not happen. A mix of read / unread is kept only so the inbox UI is exercisable.
 */
class NotificationSeeder extends DevSeeder
{
    public function run(): void
    {
        $cur = $this->currencySymbol();

        $specs = [
            // Tenants — backed by real ledger state.
            ['tenant.owing', NotificationType::RENT_OVERDUE, 'Rent overdue', "Your rent of {$cur}2,500 is overdue. Please pay to bring your account up to date.", 'unread'],
            // Failed payment leaves NO ledger row (backend records only an audit
            // entry + this notification) — so the owing balance stays unchanged.
            ['tenant.owing', NotificationType::PAYMENT_FAILED, 'Payment failed', 'Your rent payment could not be processed. No charge was made — please try again.', 'unread'],
            ['tenant.good1', NotificationType::PAYMENT_SUCCEEDED, 'Payment received', "We received your rent payment of {$cur}2,800. Thank you!", 'read'],
            ['tenant.good3', NotificationType::PAYMENT_SUCCEEDED, 'Payment received', "We received your rent payment of {$cur}4,500. Thank you!", 'read'],
            // Backed by a REAL late_fee ledger entry (10% of GH₵2,600 rent).
            ['tenant.latefee', NotificationType::LATE_FEE_ADDED, 'Late fee added', "A late fee of {$cur}260 was added to your overdue rent.", 'unread'],
            // Backed by a REAL terminated contract (tenant.former's lease).
            ['tenant.former', NotificationType::CONTRACT_TERMINATED, 'Lease ended', 'Your lease at Garden Villas has been terminated. Thank you for renting with us.', 'read'],

            // Landlords — backed by real applications / reviews.
            ['landlord.1', NotificationType::APPLICATION_SUBMITTED, 'New application', 'A tenant applied to your available unit at Ridge Court.', 'unread'],
            ['landlord.3', NotificationType::APPLICATION_SUBMITTED, 'New application', 'A tenant applied to your available unit at Garden Villas.', 'unread'],
            ['landlord.1', NotificationType::REVIEW_SUBMITTED, 'New review', 'A tenant left a review on one of your Ridge Court units.', 'read'],
            // Backed by a REAL suspended account.
            ['landlord.suspended', NotificationType::ACCOUNT_SUSPENDED, 'Account suspended', 'Your account has been suspended pending review. Contact support for details.', 'unread'],
        ];

        $count = 0;
        foreach ($specs as $i => [$userKey, $type, $title, $message, $state]) {
            $user = $this->user($userKey);
            if (! $user) {
                continue;
            }

            $this->createNotification($user, $type, $title, $message, $state, $i);
            $count++;
        }

        $this->command?->info("  ✓ Notifications: {$count} (all tied to real seeded events; mixed read/unread).");
    }

    protected function createNotification(User $user, NotificationType $type, string $title, string $message, string $state, int $i): void
    {
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => ['event_id' => 'seed-'.$type->value.'-'.$user->id.'-'.$i, 'seeded' => true],
        ]);

        $createdAt = now()->subDays($i + 1)->subHours($i);
        $delivered = $createdAt->copy()->addMinutes(2);

        $columns = $state === 'read'
            ? ['read_at' => $createdAt->copy()->addHours(3), 'delivered_at' => $delivered]
            : ['read_at' => null, 'delivered_at' => $delivered];

        $notification->forceFill(array_merge(['created_at' => $createdAt], $columns))->saveQuietly();
    }

    protected function currencySymbol(): string
    {
        return 'GH₵';
    }
}
