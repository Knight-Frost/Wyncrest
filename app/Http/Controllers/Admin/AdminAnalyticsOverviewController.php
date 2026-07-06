<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\SuperAdminAnalyticsService;
use App\Support\Cache\AnalyticsCache;
use App\Support\Cache\AnalyticsCacheKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminAnalyticsOverviewController
 *
 * GET /api/admin/analytics/overview — the Super Admin "Platform Analytics"
 * page. Gated by admin.can:view_analytics (route middleware); super admins
 * hold every capability implicitly, so this stays reachable exactly where
 * the existing /admin/analytics/{financial,contracts,platform,notifications}
 * endpoints already are.
 */
class AdminAnalyticsOverviewController extends Controller
{
    /** Named ranges the frontend's period selector maps to. */
    private const RANGES = ['7d', '30d', '90d', 'this_month', 'last_month', 'ytd', 'custom'];

    public function __construct(private readonly SuperAdminAnalyticsService $service) {}

    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:'.implode(',', self::RANGES)],
            'start_date' => ['nullable', 'date', 'required_if:range,custom'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        [$rangeKey, $dateFrom, $dateTo] = $this->resolveRange($validated);

        $cacheKey = AnalyticsCacheKey::generate('super_admin_overview', $request);

        $analytics = AnalyticsCache::remember(
            $cacheKey,
            300,
            fn () => $this->service->getAnalytics(['date_from' => $dateFrom, 'date_to' => $dateTo]),
            'admin',
            ['range' => $rangeKey]
        );

        return response()->json([
            'range' => [
                'key' => $rangeKey,
                'start_date' => $dateFrom?->toDateString(),
                'end_date' => $dateTo?->toDateString(),
            ],
            'analytics' => $analytics,
        ]);
    }

    /**
     * @return array{0:string,1:?Carbon,2:?Carbon}
     */
    private function resolveRange(array $validated): array
    {
        $range = $validated['range'] ?? '30d';

        if ($range === 'custom') {
            return [$range, Carbon::parse($validated['start_date']), Carbon::parse($validated['end_date'] ?? now())];
        }

        return match ($range) {
            '7d' => [$range, now()->subDays(7)->startOfDay(), now()->endOfDay()],
            '90d' => [$range, now()->subDays(90)->startOfDay(), now()->endOfDay()],
            'this_month' => [$range, now()->startOfMonth(), now()->endOfMonth()],
            'last_month' => [$range, now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'ytd' => [$range, now()->startOfYear(), now()->endOfDay()],
            default => [$range, now()->subDays(30)->startOfDay(), now()->endOfDay()],
        };
    }
}
