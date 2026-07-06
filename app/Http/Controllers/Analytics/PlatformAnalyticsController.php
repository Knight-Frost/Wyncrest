<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ResolvesAnalyticsScope;
use App\Http\Controllers\Controller;
use App\Services\Analytics\PlatformAnalyticsService;
use App\Support\Cache\AnalyticsCache;
use App\Support\Cache\AnalyticsCacheKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * PlatformAnalyticsController
 *
 * Phase 4.0c: API endpoint for platform health analytics
 * Phase 5.1: Added Redis caching with 300s TTL
 */
class PlatformAnalyticsController extends Controller
{
    use ResolvesAnalyticsScope;

    protected PlatformAnalyticsService $analyticsService;

    public function __construct(PlatformAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get platform analytics
     */
    public function index(Request $request): JsonResponse
    {
        // Validate filters
        $validator = Validator::make($request->all(), [
            'property_id' => 'nullable|integer|exists:properties,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Build filters
        $filters = [];

        if ($request->has('property_id')) {
            $filters['property_id'] = $request->input('property_id');
        }

        // Apply role-based scoping
        $user = $request->user();
        $role = $this->resolveAnalyticsRole($user);
        $scopedTo = 'all';

        if ($role === 'tenant') {
            // Tenants cannot access platform-wide analytics
            return response()->json([
                'message' => 'Unauthorized. Tenants cannot access platform analytics.',
            ], 403);
        } elseif ($role === 'landlord') {
            // Landlords see only their properties
            if ($denied = $this->applyLandlordPropertyScope($user, $filters)) {
                return $denied;
            }
            $scopedTo = 'landlord';
        }

        // Generate cache key
        $cacheKey = AnalyticsCacheKey::generate('platform', $request);

        // Get analytics with caching (TTL: 300 seconds)
        $analytics = AnalyticsCache::remember(
            $cacheKey,
            300,
            fn () => $this->analyticsService->getAnalytics($filters),
            $role,
            $filters
        );

        return response()->json([
            'analytics' => $analytics,
            'scoped_to' => $scopedTo,
        ]);
    }
}
