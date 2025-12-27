<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Property;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;

/**
 * AdminDashboardController
 * 
 * Provides admin dashboard statistics and overview.
 */
class AdminDashboardController extends Controller
{
    /**
     * Get admin dashboard data.
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $landlordCount = User::landlords()->count();
        $tenantCount = User::tenants()->count();
        $propertyCount = Property::count();
        $pendingListingsCount = Listing::pendingReview()->count();
        $activeListingsCount = Listing::public()->count();

        // Recent activity
        $recentListings = Listing::with(['landlord', 'unit.property'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'statistics' => [
                'landlords' => $landlordCount,
                'tenants' => $tenantCount,
                'properties' => $propertyCount,
                'pending_listings' => $pendingListingsCount,
                'active_listings' => $activeListingsCount,
            ],
            'recent_listings' => $recentListings,
        ]);
    }
}
