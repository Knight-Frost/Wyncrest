<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PublicListingController
 *
 * Handles public listing discovery (no authentication required).
 * Provides search, filtering, and listing detail views.
 */
class PublicListingController extends Controller
{
    public function __construct(
        protected ListingService $listingService
    ) {}

    /**
     * Display a listing of active listings with search and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2'],
            'zip_code' => ['nullable', 'string', 'max:10'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'bedrooms' => ['nullable', 'integer', 'min:0'],
            'bathrooms' => ['nullable', 'numeric', 'min:0'],
            'property_type' => ['nullable', 'string'],
            'pets_allowed' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'in:newest,price_low,price_high,featured'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $filters['per_page'] ?? 20;
        unset($filters['per_page']);

        $listings = $this->listingService->searchPublicListings($filters, $perPage);

        return response()->json($listings);
    }

    /**
     * Display the specified listing.
     */
    public function show(int $id): JsonResponse
    {
        $listing = $this->listingService->getPublicListing($id);

        if (! $listing) {
            return response()->json([
                'message' => 'Listing not found or is not publicly available',
            ], 404);
        }

        return response()->json($listing);
    }

    /**
     * Get featured listings for homepage.
     */
    public function featured(Request $request): JsonResponse
    {
        $limit = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ])['limit'] ?? 6;

        $listings = $this->listingService->getFeaturedListings($limit);

        return response()->json($listings);
    }
}
