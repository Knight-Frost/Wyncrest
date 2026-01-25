<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Enums\UserType;
use App\Enums\ContractStatus;

/**
 * ContractPolicy
 *
 * Authorizes contract actions based on user role and ownership.
 * SECURITY: Uses strict type comparisons (===) throughout.
 */
class ContractPolicy
{
    /**
     * Determine whether the user can view any contracts.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->user_type, [UserType::LANDLORD, UserType::TENANT], true);
    }

    /**
     * Determine whether the user can view the contract.
     */
    public function view(User $user, Contract $contract): bool
    {
        // Cast IDs to same type for comparison to handle int/string mismatch
        $userId = (int) $user->id;
        $landlordId = (int) $contract->landlord_id;
        $tenantId = (int) $contract->tenant_id;

        return $userId === $landlordId || $userId === $tenantId;
    }

    /**
     * Determine whether the user can create contracts.
     */
    public function create(User $user): bool
    {
        return $user->user_type === UserType::LANDLORD;
    }

    /**
     * Determine whether the user can send the contract to tenant.
     */
    public function send(User $user, Contract $contract): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $contract->landlord_id;

        return $userId === $landlordId
            && $contract->status === ContractStatus::DRAFT;
    }

    /**
     * Determine whether the user can accept the contract.
     */
    public function accept(User $user, Contract $contract): bool
    {
        $userId = (int) $user->id;
        $tenantId = (int) $contract->tenant_id;

        return $userId === $tenantId
            && $contract->canBeAccepted();
    }

    /**
     * Determine whether the user can terminate the contract.
     */
    public function terminate(User $user, Contract $contract): bool
    {
        if (!$contract->canBeTerminated()) {
            return false;
        }

        $userId = (int) $user->id;
        $landlordId = (int) $contract->landlord_id;
        $tenantId = (int) $contract->tenant_id;

        return $userId === $landlordId || $userId === $tenantId;
    }

    /**
     * Determine whether the user can update the contract.
     */
    public function update(User $user, Contract $contract): bool
    {
        // Contracts cannot be directly updated after creation
        return false;
    }

    /**
     * Determine whether the user can delete the contract.
     */
    public function delete(User $user, Contract $contract): bool
    {
        // Contracts cannot be deleted (audit trail)
        return false;
    }
}
