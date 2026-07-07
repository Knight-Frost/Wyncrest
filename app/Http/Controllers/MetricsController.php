<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MetricsController - Phase 7.5 Task 2
 *
 * Provides API endpoints to access application metrics.
 * Only accessible to authenticated admin users.
 */
class MetricsController extends Controller
{
    public function __construct(
        private MetricsService $metricsService
    ) {}

    /**
     * Get comprehensive metrics summary
     *
     * GET /api/admin/metrics
     */
    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->metricsService->getSummary(),
        ]);
    }

    /**
     * Get latency metrics
     *
     * GET /api/admin/metrics/latency
     */
    public function latency(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->metricsService->getLatencyPercentiles(),
        ]);
    }

    /**
     * Get error rate metrics
     *
     * GET /api/admin/metrics/errors?minutes=5
     */
    public function errors(Request $request): JsonResponse
    {
        $minutes = $request->query('minutes', 5);

        return response()->json([
            'success' => true,
            'data' => $this->metricsService->getErrorRate($minutes),
        ]);
    }

    /**
     * Get request rate metrics
     *
     * GET /api/admin/metrics/requests?minutes=5
     */
    public function requests(Request $request): JsonResponse
    {
        $minutes = $request->query('minutes', 5);

        return response()->json([
            'success' => true,
            'data' => $this->metricsService->getRequestRate($minutes),
        ]);
    }

    /**
     * Get queue depth metrics
     *
     * GET /api/admin/metrics/queue
     */
    public function queue(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->metricsService->getQueueDepth(),
        ]);
    }

    /**
     * Get recent requests
     *
     * GET /api/admin/metrics/recent?limit=20
     */
    public function recent(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 20);

        $requests = $this->metricsService->getRecentRequests($limit);

        // why: /admin/metrics/* is open to admin.or.landlord, but recent_requests
        // records every user's activity (user_id/user_type). A landlord is not
        // entitled to enumerate other users' identities, only the operational
        // shape of traffic — so redact identity fields unless the caller is an
        // Admin. MetricsService stays a pure recorder; redaction is a read-time,
        // caller-scoped concern that belongs in the controller.
        if (! $request->user() instanceof Admin) {
            $requests = array_map(static function (array $entry) {
                $entry['user_id'] = null;
                $entry['user_type'] = null;

                return $entry;
            }, $requests);
        }

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }
}
