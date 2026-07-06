<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\MaintenanceOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminMaintenanceController
 *
 * Read-only admin maintenance queue. Viewing maintenance requests is a
 * baseline admin privilege (no admin.can: gate), matching the existing
 * Users/Contracts/Ledger convention — only mutating admin actions would
 * require a capability, and none exist yet.
 */
class AdminMaintenanceController extends Controller
{
    public function __construct(private readonly MaintenanceOverviewService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,urgent,overdue,waiting,all'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json(['data' => $this->service->cases($filters)]);
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->service->summary());
    }
}
