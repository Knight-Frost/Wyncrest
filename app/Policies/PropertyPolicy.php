<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Property;
use App\Models\User;

/**
 * PropertyPolicy
 *
 * Authorization rules for property management.
 * Landlords can only manage their own properties.
 * SECURITY: Uses strict type comparisons (===) throughout.
 */
class PropertyPolicy
{
    /**
     * Determine if user can view any properties.
     */
    public function viewAny(User $user): bool
    {
        return $user->user_type === UserType::LANDLORD;
    }

    /**
     * Determine if user can view the property.
     */
    public function view(User $user, Property $property): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $property->landlord_id;

        return $userId === $landlordId;
    }

    /**
     * Determine if user can create properties.
     */
    public function create(User $user): bool
    {
        return $user->user_type === UserType::LANDLORD;
    }

    /**
     * Determine if user can update the property.
     */
    public function update(User $user, Property $property): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $property->landlord_id;

        return $userId === $landlordId;
    }

    /**
     * Determine if user can delete the property.
     */
    public function delete(User $user, Property $property): bool
    {
        // Can only delete if no active units or listings
        if ($property->units()->count() > 0) {
            return false;
        }

        $userId = (int) $user->id;
        $landlordId = (int) $property->landlord_id;

        return $userId === $landlordId;
    }

    /**
     * Determine if user can restore the property.
     */
    public function restore(User $user, Property $property): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $property->landlord_id;

        return $userId === $landlordId;
    }

    /**
     * Determine if user can permanently delete the property.
     */
    public function forceDelete(User $user, Property $property): bool
    {
        // Never allow force delete (compliance requirement)
        return false;
    }
}
