<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Admin\AdminOperationsDashboardService;
use Illuminate\Http\JsonResponse;

/**
 * AdminDashboardController
 *
 * Provides the platform command-center overview. Everything below the
 * headline counts is delegated to AdminOperationsDashboardService — the
 * attention queue, cross-domain priority cases, platform snapshot, rent
 * risk monitor, review queues, system health, and recent activity all live
 * there so the dashboard, the ledger page, and the moderation queues can
 * never disagree about the same underlying numbers.
 */
class AdminDashboardController extends Controller
{
    public function index(AdminOperationsDashboardService $service): JsonResponse
    {
        return response()->json(array_merge([
            'properties' => Property::count(),
            'units' => Unit::count(),
        ], $service->overview()));
    }
}
