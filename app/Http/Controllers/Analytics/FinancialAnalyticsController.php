<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\FinancialAnalyticsService;
use App\Support\Cache\AnalyticsCacheKey;
use App\Support\Cache\AnalyticsCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * FinancialAnalyticsController
 * 
 * Phase 4.0b: API endpoint for financial analytics
 * Phase 5.1: Added Redis caching with 300s TTL
 */
class FinancialAnalyticsController extends Controller
{
    protected FinancialAnalyticsService $analyticsService;

    public function __construct(FinancialAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get financial analytics
     * 
     * @param Request $request
     * @return JsonResponse
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
        $role = $user->user_type->value;
        $scopedTo = 'all';

        if ($user->user_type->value === 'tenant') {
            // Tenants see only their own financial data
            $filters['user_id'] = $user->id;
            $scopedTo = 'personal';
        } elseif ($user->user_type->value === 'landlord') {
            // Landlords see only their properties
            if (!isset($filters['property_id'])) {
                // Get first property owned by landlord
                $property = $user->properties()->first();
                if ($property) {
                    $filters['property_id'] = $property->id;
                }
            }
            $scopedTo = 'landlord';
        }

        // Generate cache key
        $cacheKey = AnalyticsCacheKey::generate('financial', $request);

        // Get analytics with caching (TTL: 300 seconds)
        $analytics = AnalyticsCache::remember(
            $cacheKey,
            300,
            fn() => $this->analyticsService->getAnalytics($filters),
            $role,
            $filters
        );

        return response()->json([
            'analytics' => $analytics,
            'scoped_to' => $scopedTo,
        ]);
    }
}
