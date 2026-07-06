<?php

namespace App\Http\Controllers\Analytics\Concerns;

use App\Models\Admin;
use Illuminate\Http\JsonResponse;

/**
 * ResolvesAnalyticsScope
 *
 * Shared role/ownership scoping for the analytics controllers. These
 * endpoints are reachable by tenants and landlords (bearer pipeline) AND by
 * admins (cookie session via /admin/analytics/*), so the role must be
 * resolved without assuming a `user_type` attribute, and any
 * request-supplied property filter must be proven to belong to the
 * requesting landlord before it is trusted.
 */
trait ResolvesAnalyticsScope
{
    /**
     * Resolve the analytics role for the authenticated principal.
     * Admin models have no user_type; they get the unscoped platform view.
     */
    protected function resolveAnalyticsRole(mixed $user): string
    {
        return $user instanceof Admin ? 'admin' : $user->user_type->value;
    }

    /**
     * Constrain a landlord's property filter to properties they own.
     *
     * Returns a 403 response when the landlord requested somebody else's
     * property; null when the filter set is safe to pass to the service.
     */
    protected function applyLandlordPropertyScope(mixed $user, array &$filters): ?JsonResponse
    {
        if (isset($filters['property_id'])) {
            if (! $user->properties()->whereKey($filters['property_id'])->exists()) {
                return response()->json([
                    'message' => 'Unauthorized. You do not own this property.',
                ], 403);
            }

            return null;
        }

        $property = $user->properties()->first();

        // why: a landlord with zero properties must never fall through to an
        // unscoped (platform-wide) query; property_id 0 matches nothing.
        $filters['property_id'] = $property?->id ?? 0;

        return null;
    }
}
