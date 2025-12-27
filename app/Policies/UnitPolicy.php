<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;
use App\Enums\UserType;

/**
 * UnitPolicy
 * 
 * Authorization rules for unit management.
 * Landlords can only manage units in their properties.
 */
class UnitPolicy
{
    /**
     * Determine if user can view any units.
     */
    public function viewAny(User $user): bool
    {
        return $user->user_type === UserType::LANDLORD;
    }

    /**
     * Determine if user can view the unit.
     */
    public function view(User $user, Unit $unit): bool
    {
        return $user->id === $unit->property->landlord_id;
    }

    /**
     * Determine if user can create units.
     */
    public function create(User $user): bool
    {
        return $user->user_type === UserType::LANDLORD;
    }

    /**
     * Determine if user can update the unit.
     */
    public function update(User $user, Unit $unit): bool
    {
        return $user->id === $unit->property->landlord_id;
    }

    /**
     * Determine if user can delete the unit.
     */
    public function delete(User $user, Unit $unit): bool
    {
        // Can only delete if no active listings
        if ($unit->listings()->where('status', 'active')->count() > 0) {
            return false;
        }

        return $user->id === $unit->property->landlord_id;
    }

    /**
     * Determine if user can restore the unit.
     */
    public function restore(User $user, Unit $unit): bool
    {
        return $user->id === $unit->property->landlord_id;
    }

    /**
     * Determine if user can permanently delete the unit.
     */
    public function forceDelete(User $user, Unit $unit): bool
    {
        // Never allow force delete (compliance requirement)
        return false;
    }
}
