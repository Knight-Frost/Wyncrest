<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TenantDashboardController
 *
 * Provides tenant dashboard data and statistics.
 */
class TenantDashboardController extends Controller
{
    /**
     * Get tenant dashboard data.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $savedListingsCount = $user->savedListings()->count();

        // Get recent saved listings
        $recentSavedListings = $user->savedListings()
            ->with(['unit.property', 'primaryPhoto'])
            ->orderBy('saved_listings.created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'user' => [
                'name' => $user->full_name,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
            ],
            'statistics' => [
                'saved_listings_count' => $savedListingsCount,
            ],
            'recent_saved_listings' => $recentSavedListings,
        ]);
    }
}
