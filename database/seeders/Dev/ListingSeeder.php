<?php

namespace Database\Seeders\Dev;

use App\Enums\ListingStatus;
use App\Models\Listing;

/**
 * ListingSeeder — one public listing per unit, spanning EVERY listing status.
 *
 * Distribution comes from the catalog so the moderation queue (pending_review),
 * the public browse surface (active), and the draft/inactive/rejected/archived
 * states are all represented and coherent with each unit's availability.
 */
class ListingSeeder extends DevSeeder
{
    public function run(): void
    {
        $admin = $this->superAdmin();
        $counts = [];

        foreach (SeedCatalog::UNITS as $u) {
            $unit = $this->unitFromCatalog($u);
            if (! $unit) {
                continue;
            }

            $status = ListingStatus::from($u['listing']);
            $property = $unit->property;
            $attributes = [
                'landlord_id' => $property->landlord_id,
                'title' => $u['type'].', '.$property->city,
                'description' => $this->describe($u, $property->city),
                'status' => $status,
                'pets_allowed' => in_array('Pet friendly', $u['amenities'], true),
                'lease_duration_months' => 12,
                'featured' => false,
            ];

            $this->applyStatusFields($attributes, $status, $admin?->id);

            Listing::updateOrCreate(['unit_id' => $unit->id], $attributes);
            $counts[$u['listing']] = ($counts[$u['listing']] ?? 0) + 1;
        }

        // Feature two strong active listings on the browse surface.
        Listing::where('status', ListingStatus::ACTIVE)->orderBy('id')->limit(2)->update(['featured' => true]);

        $summary = collect($counts)->map(fn ($n, $s) => "{$s}:{$n}")->implode(', ');
        $this->command?->info("  ✓ Listings: {$summary}.");
    }

    protected function describe(array $u, string $city): string
    {
        $amenities = implode(', ', $u['amenities']);

        return "{$u['type']} in {$city}. {$u['bedrooms']} bed / {$u['bathrooms']} bath, "
            ."{$u['sqft']} sq ft. Features: {$amenities}. Rent GH₵{$u['rent']}/month.";
    }

    protected function applyStatusFields(array &$attributes, ListingStatus $status, ?int $adminId): void
    {
        match ($status) {
            ListingStatus::ACTIVE => $attributes = array_merge($attributes, [
                'published_at' => now()->subDays(7),
                'reviewed_by' => $adminId,
                'reviewed_at' => now()->subDays(8),
            ]),
            ListingStatus::REJECTED => $attributes = array_merge($attributes, [
                'reviewed_by' => $adminId,
                'reviewed_at' => now()->subDays(4),
                'rejection_reason' => 'Photos did not match the listed unit. Please re-upload and resubmit.',
            ]),
            ListingStatus::INACTIVE => $attributes = array_merge($attributes, [
                'published_at' => now()->subMonths(3),
                'reviewed_by' => $adminId,
                'reviewed_at' => now()->subMonths(3),
            ]),
            default => null, // draft / pending_review / archived need no extra fields
        };
    }
}
