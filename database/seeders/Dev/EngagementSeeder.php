<?php

namespace Database\Seeders\Dev;

use App\Enums\ListingStatus;
use App\Enums\MediaCollection;
use App\Enums\MediaVisibility;
use App\Models\EmailLog;
use App\Models\Listing;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * EngagementSeeder — supporting demo data: saved listings, email-delivery logs
 * and listing gallery media.
 *
 * NOTE on media: listing galleries are backed by REAL image binaries copied from
 * the bundled `Homes_Photos/` folder into the public disk, so the moderation
 * queue, browse surface and detail pages show actual photos (not broken links).
 * If that folder is absent (e.g. a lean prod checkout) we fall back to metadata-
 * only rows so counts still populate. Avatars stay metadata-only — the Avatar
 * component falls back to initials, so a missing binary is invisible there.
 */
class EngagementSeeder extends DevSeeder
{
    /** Statuses whose listings should carry a real photo gallery. */
    private const GALLERY_STATUSES = [
        ListingStatus::ACTIVE,
        ListingStatus::PENDING_REVIEW,
        ListingStatus::INACTIVE,
        ListingStatus::REJECTED,
    ];

    /** Cached public-disk paths of the copied demo images (null until prepared). */
    protected ?array $seedImages = null;

    public function run(): void
    {
        $saved = $this->seedSavedListings();
        $emails = $this->seedEmailLogs();
        $media = $this->seedMedia();

        $this->command?->info("  ✓ Engagement: {$saved} saved listings, {$emails} email logs, {$media} gallery/avatar media rows.");
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

    /** Real listing galleries (photos from Homes_Photos) + avatar metadata. */
    protected function seedMedia(): int
    {
        $count = 0;
        $images = $this->prepareSeedImages();

        $listings = Listing::whereIn('status', array_map(fn ($s) => $s->value, self::GALLERY_STATUSES))
            ->orderBy('id')->get();

        $offset = 0;
        foreach ($listings as $listing) {
            // Idempotent: skip a listing that already has a real seeded gallery.
            $already = MediaAsset::where('attachable_type', Listing::class)
                ->where('attachable_id', $listing->id)
                ->where('collection', MediaCollection::ListingGallery->value)
                ->where('status', 'active')
                ->where('path', 'like', 'media/seed/%')
                ->exists();
            if ($already) {
                continue;
            }

            if (empty($images)) {
                // No bundled photos available — keep counts populated (metadata only).
                for ($n = 1; $n <= 2; $n++) {
                    MediaAsset::create($this->mediaRow(
                        ownerId: $listing->landlord_id,
                        attachable: $listing,
                        collection: MediaCollection::ListingGallery,
                        index: $n,
                        alt: "Photo {$n} of {$listing->title}",
                    ));
                    $count++;
                }

                continue;
            }

            // 3–5 real photos per listing, rotating through the set so galleries differ.
            $howMany = 3 + ($listing->id % 3);
            for ($n = 0; $n < $howMany; $n++) {
                $img = $images[($offset + $n) % count($images)];
                MediaAsset::create($this->realMediaRow($listing, $img, $n));
                $count++;
            }
            $offset += $howMany;
        }

        // Avatars stay metadata-only: the Avatar component falls back to initials.
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
     * Copy the bundled Homes_Photos into the public disk once and return their
     * disk-relative paths. Returns [] when the folder is absent so callers can
     * fall back to metadata-only rows.
     *
     * @return array<int,array{path:string,ext:string,size:int,source:string}>
     */
    protected function prepareSeedImages(): array
    {
        if ($this->seedImages !== null) {
            return $this->seedImages;
        }

        $sourceDir = base_path('Homes_Photos');
        $paths = [];

        if (is_dir($sourceDir)) {
            $files = glob($sourceDir.'/*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE) ?: [];
            sort($files);
            foreach ($files as $i => $src) {
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                $rel = "media/seed/home-{$i}.{$ext}";
                if (! Storage::disk('public')->exists($rel)) {
                    Storage::disk('public')->put($rel, (string) file_get_contents($src));
                }
                $paths[] = [
                    'path' => $rel,
                    'ext' => $ext,
                    'size' => (int) filesize($src),
                    'source' => basename($src),
                ];
            }
        }

        return $this->seedImages = $paths;
    }

    /**
     * A media_assets row backed by a real image file on the public disk.
     *
     * @param  array{path:string,ext:string,size:int,source:string}  $img
     * @return array<string,mixed>
     */
    protected function realMediaRow(Listing $listing, array $img, int $index): array
    {
        return [
            'owner_user_id' => $listing->landlord_id,
            'uploaded_by_id' => $listing->landlord_id,
            'attachable_type' => Listing::class,
            'attachable_id' => $listing->id,
            'collection' => MediaCollection::ListingGallery->value,
            'disk' => 'public',
            'path' => $img['path'],
            'original_filename' => $img['source'],
            'stored_filename' => basename($img['path']),
            'mime_type' => $img['ext'] === 'png' ? 'image/png' : 'image/jpeg',
            'extension' => $img['ext'],
            'size_bytes' => $img['size'],
            // Path-based checksum: the same binary is shared across listings by design.
            'checksum' => hash('sha256', $img['path']),
            'visibility' => MediaVisibility::Public->value,
            'sort_order' => $index,
            'alt_text' => $listing->title.' — photo '.($index + 1),
            'caption' => null,
            'status' => 'active',
        ];
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
