<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Events\ListingPublished;
use App\Models\Listing;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ListingService
 *
 * Handles all listing business logic.
 * Controllers should delegate to this service.
 */
class ListingService
{
    /**
     * Search and filter public listings
     */
    public function searchPublicListings(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Listing::query()
            ->public()
            // landlord.avatarAsset is eager-loaded so the appended avatar_url does
            // not trigger an N+1 across the public results.
            ->with(['unit.property', 'primaryPhoto', 'landlord.avatarAsset']);

        // Apply filters
        if (! empty($filters['keyword'])) {
            $query->search($filters['keyword']);
        }

        if (! empty($filters['city']) || ! empty($filters['state']) || ! empty($filters['zip_code'])) {
            $query->inLocation(
                $filters['city'] ?? null,
                $filters['state'] ?? null,
                $filters['zip_code'] ?? null
            );
        }

        if (isset($filters['min_price']) || isset($filters['max_price'])) {
            $query->priceRange(
                $filters['min_price'] ?? null,
                $filters['max_price'] ?? null
            );
        }

        if (! empty($filters['bedrooms'])) {
            $query->withBedrooms((int) $filters['bedrooms']);
        }

        if (! empty($filters['property_type'])) {
            $query->ofPropertyType($filters['property_type']);
        }

        if (! empty($filters['pets_allowed'])) {
            $query->where('pets_allowed', true);
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'newest';
        switch ($sortBy) {
            case 'price_low':
                $query->join('units', 'listings.unit_id', '=', 'units.id')
                    ->orderBy('units.rent_amount', 'asc')
                    ->select('listings.*');
                break;
            case 'price_high':
                $query->join('units', 'listings.unit_id', '=', 'units.id')
                    ->orderBy('units.rent_amount', 'desc')
                    ->select('listings.*');
                break;
            case 'featured':
                $query->orderBy('featured', 'desc')
                    ->orderBy('published_at', 'desc');
                break;
            case 'newest':
            default:
                $query->orderBy('published_at', 'desc');
                break;
        }

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->each(fn (Listing $listing) => $this->maskPropertyAddress($listing));

        return $paginated;
    }

    /**
     * Get single public listing with view tracking.
     * Includes the listing's own gallery, approved reviews (reviewer limited to
     * safe public columns — never leak email/phone/etc. to anonymous visitors),
     * and rating aggregates from the property.
     */
    public function getPublicListing(int $listingId): ?Listing
    {
        $listing = Listing::query()
            ->public()
            ->with([
                'unit.property.approvedReviews' => fn ($q) => $q->latest()->limit(5),
                // Reviewer columns are select-constrained to safe public fields; avatar_url
                // is an appended accessor (App\Models\User::$appends), not a raw column, so
                // it can't be listed here — its backing avatarAsset relation is eager-loaded
                // instead to avoid an N+1 when the accessor runs (App\Services\ListingService).
                'unit.property.approvedReviews.reviewer:id,first_name,last_name',
                'unit.property.approvedReviews.reviewer.avatarAsset',
                'mediaAssets',
                'photos',
                // Same column constraint as the reviewer above — the pre-existing
                // unconstrained load leaked the landlord's raw email/phone/etc. to
                // every anonymous visitor of a public listing.
                'landlord:id,first_name,last_name,identity_verified',
                'landlord.avatarAsset',
            ])
            ->find($listingId);

        if ($listing) {
            $listing->incrementViews();
            $this->maskPropertyAddress($listing);
            $listing->unit?->property?->append(['average_rating', 'review_count']);
        }

        return $listing;
    }

    /**
     * Strip the street address from a listing's property (in-memory only,
     * never persisted) unless the landlord has opted the property into
     * 'public' address_visibility. Anonymous/public listing responses must
     * never leak the raw street address for area_only/full_after_approval
     * properties.
     */
    private function maskPropertyAddress(Listing $listing): Listing
    {
        $property = $listing->unit?->property;

        if ($property && $property->address_visibility !== 'public') {
            $property->street_address = null;
            $property->street_address_2 = null;
        }

        return $listing;
    }

    /**
     * Create listing for admin (Phase 1 testing)
     */
    public function createListingAsAdmin(Unit $unit, User $landlord, array $data): Listing
    {
        $listing = new Listing([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'status' => ListingStatus::DRAFT,
            'pets_allowed' => $data['pets_allowed'] ?? false,
            'pet_policy' => $data['pet_policy'] ?? null,
            'lease_duration_months' => $data['lease_duration_months'] ?? null,
            'move_in_date' => $data['move_in_date'] ?? null,
        ]);

        $listing->save();

        return $listing;
    }

    /**
     * Publish listing (admin action in Phase 1)
     */
    public function publishListing(Listing $listing): Listing
    {
        if (! in_array($listing->status, [ListingStatus::DRAFT, ListingStatus::PENDING_REVIEW], true)) {
            throw new \Exception('Only draft or pending review listings can be published');
        }

        $listing->update([
            'status' => ListingStatus::ACTIVE,
            'published_at' => now(),
        ]);

        event(new ListingPublished($listing));

        return $listing->fresh();
    }

    /**
     * Get listings pending admin review
     */
    public function getPendingReviewListings(): Collection
    {
        return Listing::query()
            ->pendingReview()
            ->with(['unit.property', 'landlord'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get featured listings
     */
    public function getFeaturedListings(int $limit = 6): Collection
    {
        $listings = Listing::query()
            ->public()
            ->featured()
            ->with(['unit.property', 'primaryPhoto'])
            ->limit($limit)
            ->get();

        $listings->each(fn (Listing $listing) => $this->maskPropertyAddress($listing));

        return $listings;
    }
}
