<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\PlatformAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * PlatformAnalyticsController
 * 
 * Phase 4.0c: API endpoint for platform health analytics
 */
class PlatformAnalyticsController extends Controller
{
    protected PlatformAnalyticsService $analyticsService;

    public function __construct(PlatformAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get platform analytics
     * 
     * @param Request $request
     * @return JsonResponse
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
        $scopedTo = 'all';

        if ($user->user_type->value === 'tenant') {
            // Tenants cannot access platform-wide analytics
            return response()->json([
                'message' => 'Unauthorized. Tenants cannot access platform analytics.',
            ], 403);
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

        // Get analytics
        $analytics = $this->analyticsService->getAnalytics($filters);

        return response()->json([
            'analytics' => $analytics,
            'scoped_to' => $scopedTo,
        ]);
    }
}
