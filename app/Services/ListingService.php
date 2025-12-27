<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Unit;
use App\Models\User;
use App\Events\ListingPublished;
use App\Events\ListingSubmittedForReview;
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
            ->with(['unit.property', 'primaryPhoto', 'landlord']);

        // Apply filters
        if (!empty($filters['keyword'])) {
            $query->search($filters['keyword']);
        }

        if (!empty($filters['city']) || !empty($filters['state']) || !empty($filters['zip_code'])) {
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

        if (!empty($filters['bedrooms'])) {
            $query->withBedrooms((int) $filters['bedrooms']);
        }

        if (!empty($filters['property_type'])) {
            $query->ofPropertyType($filters['property_type']);
        }

        if (!empty($filters['pets_allowed'])) {
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

        return $query->paginate($perPage);
    }

    /**
     * Get single public listing with view tracking
     */
    public function getPublicListing(int $listingId): ?Listing
    {
        $listing = Listing::query()
            ->public()
            ->with(['unit.property', 'photos', 'landlord'])
            ->find($listingId);

        if ($listing) {
            $listing->incrementViews();
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
        
    if (!in_array($listing->status, [ListingStatus::DRAFT, ListingStatus::PENDING_REVIEW])) {
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
        return Listing::query()
            ->public()
            ->featured()
            ->with(['unit.property', 'primaryPhoto'])
            ->limit($limit)
            ->get();
    }
}
