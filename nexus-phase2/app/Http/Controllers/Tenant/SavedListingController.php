<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Enums\ListingStatus;

/**
 * SavedListingController
 * 
 * Handles tenant saved listings (favorites).
 */
class SavedListingController extends Controller
{
    /**
     * Get all saved listings for the authenticated tenant.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $savedListings = $request->user()
            ->savedListings()
            ->with(['unit.property', 'primaryPhoto', 'landlord'])
            ->orderBy('saved_listings.created_at', 'desc')
            ->get();

        return response()->json($savedListings);
    }

    /**
     * Save a listing to favorites.
     * 
     * @param Request $request
     * @param Listing $listing
     * @return JsonResponse
     */
    public function store(Request $request, Listing $listing): JsonResponse
    {
        // Validate listing is publicly available
        if (!$listing->isPublic()) {
            return response()->json([
                'message' => 'This listing is not available to save'
            ], 422);
        }

        // Check if already saved
        if ($request->user()->savedListings()->where('listing_id', $listing->id)->exists()) {
            return response()->json([
                'message' => 'Listing already saved'
            ], 422);
        }

        // Validate optional notes
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500']
        ]);

        // Save listing
        $request->user()->savedListings()->attach($listing->id, [
            'notes' => $validated['notes'] ?? null
        ]);

        return response()->json([
            'message' => 'Listing saved successfully',
            'listing' => $listing->load(['unit.property', 'primaryPhoto'])
        ], 201);
    }

    /**
     * Remove a listing from favorites.
     * 
     * @param Request $request
     * @param Listing $listing
     * @return JsonResponse
     */
    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        $removed = $request->user()->savedListings()->detach($listing->id);

        if (!$removed) {
            return response()->json([
                'message' => 'Listing was not in your saved listings'
            ], 404);
        }

        return response()->json([
            'message' => 'Listing removed from saved listings'
        ], 200);
    }
}
