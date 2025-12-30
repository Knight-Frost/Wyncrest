<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\ContractAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * ContractAnalyticsController
 * 
 * Phase 4.0c: API endpoint for contract lifecycle analytics
 */
class ContractAnalyticsController extends Controller
{
    protected ContractAnalyticsService $analyticsService;

    public function __construct(ContractAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get contract analytics
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

        // Apply role-based scoping
        $user = $request->user();
        $scopedTo = 'all';

        if ($user->user_type->value === 'tenant') {
            // Tenants see only their own contracts
            $filters['user_id'] = $user->id;
            $scopedTo = 'personal';
        } elseif ($user->user_type->value === 'landlord') {
            // Landlords see only their properties
            // If property_id not specified, we'd need to filter by all their properties
            // For now, require property_id or they get nothing
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
