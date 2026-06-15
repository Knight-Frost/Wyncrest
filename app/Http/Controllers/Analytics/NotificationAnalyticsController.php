<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\NotificationAnalyticsService;
use App\Support\Cache\AnalyticsCache;
use App\Support\Cache\AnalyticsCacheKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * NotificationAnalyticsController
 *
 * Phase 4.0a: API endpoint for notification analytics
 * Phase 5.1: Added Redis caching with 300s TTL
 * Phase 5.1 Fix: Normalize 'type' parameter to service expectation
 */
class NotificationAnalyticsController extends Controller
{
    protected NotificationAnalyticsService $analyticsService;

    public function __construct(NotificationAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get notification analytics
     */
    public function index(Request $request): JsonResponse
    {
        // Validate filters (accept 'type' parameter as service expects)
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'type' => 'nullable|string',
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

        // Pass 'type' directly (service expects 'type', not 'notification_type')
        if ($request->has('type')) {
            $filters['type'] = $request->input('type');
        }

        // Apply role-based scoping
        $user = $request->user();
        $role = $user->user_type->value;
        $scopedTo = 'all';

        if ($user->user_type->value === 'tenant') {
            // Tenants see only their own notifications
            $filters['user_id'] = $user->id;
            $scopedTo = 'personal';
        } elseif ($user->user_type->value === 'landlord') {
            // Landlords see only their notifications
            $filters['user_id'] = $user->id;
            $scopedTo = 'personal';
        }

        // Generate cache key
        $cacheKey = AnalyticsCacheKey::generate('notifications', $request);

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
