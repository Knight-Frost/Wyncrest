<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Analytics\Concerns\ResolvesAnalyticsScope;
use App\Http\Controllers\Controller;
use App\Services\Analytics\FinancialAnalyticsService;
use App\Support\Cache\AnalyticsCache;
use App\Support\Cache\AnalyticsCacheKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * FinancialAnalyticsController
 *
 * Phase 4.0b: API endpoint for financial analytics
 * Phase 5.1: Added Redis caching with 300s TTL
 */
class FinancialAnalyticsController extends Controller
{
    use ResolvesAnalyticsScope;

    protected FinancialAnalyticsService $analyticsService;

    public function __construct(FinancialAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get financial analytics
     */
    public function index(Request $request): JsonResponse
    {
        // Validate filters
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'property_id' => 'nullable|integer|exists:properties,id',
            'group_by' => 'nullable|in:month,property',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Build filters
        $filters = [];

        if ($request->has('start_date')) {
            $filters['start_date'] = Carbon::parse($request->input('start_date'));
        }

        if ($request->has('end_date')) {
            $filters['end_date'] = Carbon::parse($request->input('end_date'));
        }

        if ($request->has('property_id')) {
            $filters['property_id'] = $request->input('property_id');
        }

        if ($request->has('group_by')) {
            $filters['group_by'] = $request->input('group_by');
        }

        // Apply role-based scoping
        $user = $request->user();
        $role = $this->resolveAnalyticsRole($user);
        $scopedTo = 'all';

        if ($role === 'tenant') {
            // Tenants see only their own financial data
            $filters['user_id'] = $user->id;
            $scopedTo = 'personal';
        } elseif ($role === 'landlord') {
            // Landlords see only their properties
            if ($denied = $this->applyLandlordPropertyScope($user, $filters)) {
                return $denied;
            }
            $scopedTo = 'landlord';
        }

        // Generate cache key
        $cacheKey = AnalyticsCacheKey::generate('financial', $request);

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
