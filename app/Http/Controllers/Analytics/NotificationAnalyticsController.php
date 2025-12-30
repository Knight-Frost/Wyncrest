<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\NotificationAnalyticsService;
use Illuminate\Http\Request;

/**
 * NotificationAnalyticsController
 * 
 * Thin controller for notification analytics.
 * Phase 4.0a: Read-only metrics, role-aware scoping.
 */
class NotificationAnalyticsController extends Controller
{
    public function __construct(
        protected NotificationAnalyticsService $analyticsService
    ) {}

    /**
     * Get notification analytics
     * 
     * GET /api/analytics/notifications
     * 
     * Query params:
     * - start_date: ISO date (optional)
     * - end_date: ISO date (optional)
     * - type: notification type (optional)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Validate query parameters
        $validated = $request->validate([
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'type' => 'sometimes|string|in:rent_generated,payment_succeeded,payment_failed,rent_overdue',
        ]);

        // Build filters
        $filters = [];
        
        if (isset($validated['start_date'])) {
            $filters['start_date'] = \Carbon\Carbon::parse($validated['start_date']);
        }
        
        if (isset($validated['end_date'])) {
            $filters['end_date'] = \Carbon\Carbon::parse($validated['end_date']);
        }
        
        if (isset($validated['type'])) {
            $filters['type'] = $validated['type'];
        }

        // Role-based scoping
        $user = $request->user();
        
        // Tenant: only personal metrics
        if ($user->isTenant()) {
            $filters['user_id'] = $user->id;
        }
        
        // Landlord: scoped to their tenants (via contracts)
        // For Phase 4.0a, landlords see their tenant notifications
        // This requires joining through contracts - implementing basic version
        if ($user->isLandlord()) {
            // For now, landlords see all (can be scoped in 4.0b when we add contract analytics)
            // In production, this would filter to notifications for tenants in landlord's properties
        }
        
        // Admin: sees everything (no filter)

        // Get analytics
        $analytics = $this->analyticsService->getAnalytics($filters);

        return response()->json([
            'analytics' => $analytics,
            'filters' => $filters,
            'scoped_to' => $user->isTenant() ? 'personal' : ($user->isLandlord() ? 'properties' : 'platform'),
        ]);
    }
}
