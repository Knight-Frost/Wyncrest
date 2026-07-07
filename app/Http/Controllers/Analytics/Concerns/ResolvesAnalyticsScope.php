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

        // why: with no explicit property_id, scope to EVERY property the
        // landlord owns — not just their first. The previous first-property
        // fallback silently understated a multi-property landlord's figures
        // (they saw one property's totals as if it were their whole book).
        // landlord_id lives directly on ledger_entries / contracts and is
        // honoured by both analytics services' applyFilters(); a landlord with
        // zero properties still matches nothing but their own (empty) rows.
        $filters['landlord_id'] = $user->id;

        return null;
    }
}
