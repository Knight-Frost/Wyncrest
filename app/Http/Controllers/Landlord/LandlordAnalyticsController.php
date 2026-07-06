<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Services\LandlordAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordAnalyticsController
 *
 * Portfolio-wide analytics for the authenticated landlord: financial and
 * occupancy trends, the listings/applications funnel, tenant payment
 * behaviour, maintenance aggregates, a "needs attention" digest, and a
 * property performance table. Always scoped to the whole portfolio (never a
 * single property) — per-property drill-down reuses the existing Property
 * Detail page rather than a second, overlapping analytics view.
 */
class LandlordAnalyticsController extends Controller
{
    public function index(Request $request, LandlordAnalyticsService $service): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'string', 'in:this,last,90,ytd'],
            'property_id' => ['sometimes', 'integer'],
        ]);

        // property_id is applied alongside the landlord's own id everywhere
        // in the service, so a foreign/nonexistent id simply yields empty
        // results — it can never widen scope beyond this landlord's data.
        $payload = $service->build(
            $request->user()->id,
            $validated['range'] ?? 'this',
            $validated['property_id'] ?? null
        );

        return response()->json($payload);
    }
}
