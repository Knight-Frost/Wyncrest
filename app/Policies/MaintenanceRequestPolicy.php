<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Contract;
use App\Models\MaintenanceRequest;
use App\Models\User;

/**
 * MaintenanceRequestPolicy
 *
 * Authorizes maintenance request actions.
 * SECURITY: Uses strict type comparisons (===) with (int) casts throughout
 * to guard against int/string comparison pitfalls.
 */
class MaintenanceRequestPolicy
{
    /**
     * Determine whether the user can list maintenance requests.
     * Both tenants and landlords may list (scoped in the controller).
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->user_type, [UserType::TENANT, UserType::LANDLORD], true);
    }

    /**
     * Determine whether the user can view a specific maintenance request.
     * Accessible by the filing tenant or the responsible landlord.
     */
    public function view(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        $userId = (int) $user->id;

        return $userId === (int) $maintenanceRequest->tenant_id
            || $userId === (int) $maintenanceRequest->landlord_id;
    }

    /**
     * Determine whether the user can create a maintenance request.
     * Only tenants may file requests; the active-lease check is enforced
     * in the controller (not here) so the policy stays simple.
     */
    public function create(User $user): bool
    {
        return $user->user_type === UserType::TENANT;
    }

    /**
     * Determine whether the tenant can cancel their own request.
     * Only allowed when the request is still in OPEN status.
     */
    public function cancel(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        return (int) $user->id === (int) $maintenanceRequest->tenant_id
            && $maintenanceRequest->status->canBeCancelledByTenant();
    }

    /**
     * Determine whether the landlord can update the status of a request.
     * Only the landlord named on the request may do so. Also governs
     * assignment, reopening, and cost edits — all landlord-owns-record.
     */
    public function updateStatus(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        return (int) $user->id === (int) $maintenanceRequest->landlord_id;
    }

    /**
     * Determine whether the landlord can log a maintenance request themselves
     * against a given contract. Only the landlord who owns that contract may.
     */
    public function createAsLandlord(User $user, Contract $contract): bool
    {
        return $user->user_type === UserType::LANDLORD
            && (int) $user->id === (int) $contract->landlord_id;
    }
}
