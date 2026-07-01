<?php

namespace Database\Seeders\Dev;

use App\Enums\ListingStatus;
use App\Enums\MediaCollection;
use App\Enums\MediaVisibility;
use App\Models\EmailLog;
use App\Models\Listing;
use App\Models\MediaAsset;
use App\Models\User;

/**
 * EngagementSeeder — supporting demo data: saved listings, email-delivery logs
 * and media metadata.
 *
 * NOTE on media: only the media_assets METADATA rows are seeded (so galleries,
 * counts and admin media views are populated). The underlying image binaries are
 * NOT seeded — streaming a seeded asset would 404. Upload real files through the
 * gallery UI to back them. This is called out in the seeding docs.
 */
class EngagementSeeder extends DevSeeder
{
    public function run(): void
    {
        $saved = $this->seedSavedListings();
        $emails = $this->seedEmailLogs();
        $media = $this->seedMedia();

        $this->command?->info("  ✓ Engagement: {$saved} saved listings, {$emails} email logs, {$media} media metadata rows.");
    }

    /** A few tenants bookmark available listings they're interested in. */
    protected function seedSavedListings(): int
    {
        $active = Listing::where('status', ListingStatus::ACTIVE)->orderBy('id')->get();
        if ($active->isEmpty()) {
            return 0;
        }

        $tenantKeys = ['tenant.good1', 'tenant.good2', 'tenant.good4'];
        $notes = ['Love the location!', 'Great value', 'Shortlisted for viewing'];

        $count = 0;
        foreach ($tenantKeys as $i => $key) {
            $tenant = $this->user($key);
            if (! $tenant) {
                continue;
            }

            // One saved listing each (deterministic, no duplicates via the unique pivot).
            $listing = $active[$i % $active->count()];
            $tenant->savedListings()->syncWithoutDetaching([
                $listing->id => ['notes' => $notes[$i % count($notes)]],
            ]);
            $count++;
        }

        return $count;
    }

    /** A handful of email logs across types and delivery statuses. */
    protected function seedEmailLogs(): int
    {
        $listing = Listing::orderBy('id')->first();
        if (! $listing) {
            return 0;
        }

        $rows = [
            ['tenant.good1', 'Payment received: GH₵2,800', 'transaction', 'sent'],
            ['tenant.owing', 'Your rent is overdue', 'notification', 'sent'],
            ['landlord.1', 'Your listing is live', 'notification', 'sent'],
            ['landlord.3', 'New application received', 'notification', 'sent'],
            ['landlord.4', 'Your listing is live', 'notification', 'sent'],
        ];

        $count = 0;
        foreach ($rows as $i => [$userKey, $subject, $type, $status]) {
            $user = $this->user($userKey);
            if (! $user) {
                continue;
            }

            EmailLog::create([
                'recipient_type' => User::class,
                'recipient_id' => $user->id,
                'recipient_email' => $user->email,
                'subject' => $subject,
                'mailable_class' => 'App\\Mail\\NotificationDigestEmail',
                'email_type' => $type,
                'related_type' => Listing::class,
                'related_id' => $listing->id,
                'status' => $status,
                'sent_at' => in_array($status, ['sent', 'bounced'], true) ? now()->subDays($i)->subHours(2) : null,
                'error_message' => $status === 'failed' ? 'SMTP connection timeout' : ($status === 'bounced' ? 'Recipient address rejected' : null),
            ]);
            $count++;
        }

        return $count;
    }

    /** Gallery + avatar media metadata (no binaries — see class note). */
    protected function seedMedia(): int
    {
        $count = 0;

        // 2 gallery images for each active listing.
        $active = Listing::where('status', ListingStatus::ACTIVE)->orderBy('id')->get();
        foreach ($active as $listing) {
            for ($n = 1; $n <= 2; $n++) {
                MediaAsset::create($this->mediaRow(
                    ownerId: $listing->landlord_id,
                    attachable: $listing,
                    collection: MediaCollection::ListingGallery,
                    index: $n,
                    alt: "Demo photo {$n} of listing #{$listing->id}",
                ));
                $count++;
            }
        }

        // Avatars for a few verified users.
        foreach (['landlord.1', 'tenant.good1', 'tenant.good3'] as $key) {
            $user = $this->user($key);
            if (! $user) {
                continue;
            }
            MediaAsset::create($this->mediaRow(
                ownerId: $user->id,
                attachable: $user,
                collection: MediaCollection::Avatar,
                index: 1,
                alt: "{$user->first_name}'s avatar",
            ));
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    protected function mediaRow(int $ownerId, object $attachable, MediaCollection $collection, int $index, string $alt): array
    {
        $name = $collection->value."-{$index}.jpg";

        return [
            'owner_user_id' => $ownerId,
            'uploaded_by_id' => $ownerId,
            'attachable_type' => $attachable::class,
            'attachable_id' => $attachable->id,
            'collection' => $collection->value,
            'disk' => 'public',
            'path' => "media/demo/{$collection->value}/{$ownerId}/{$name}",
            'original_filename' => $name,
            'stored_filename' => $name,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 256000,
            'checksum' => hash('sha256', $attachable::class.$ownerId.$collection->value.$index),
            'visibility' => MediaVisibility::Public->value,
            'sort_order' => $index,
            'alt_text' => $alt,
            'caption' => 'Demo media (metadata only, no binary seeded).',
            'status' => 'active',
        ];
    }
}
