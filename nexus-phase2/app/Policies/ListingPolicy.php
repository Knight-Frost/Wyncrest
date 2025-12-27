<?php

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;
use App\Enums\UserType;
use App\Enums\ListingStatus;

/**
 * ListingPolicy
 * 
 * Authorization rules for listing management.
 * Landlords can only manage their own listings.
 * Editing restrictions based on status.
 */
class ListingPolicy
{
    /**
     * Determine if user can view any listings.
     */
    public function viewAny(User $user): bool
    {
        return $user->user_type === UserType::LANDLORD;
    }

    /**
     * Determine if user can view the listing.
     */
    public function view(User $user, Listing $listing): bool
    {
        return $user->id === $listing->landlord_id;
    }

    /**
     * Determine if user can create listings.
     */
    public function create(User $user): bool
    {
        return $user->user_type === UserType::LANDLORD;
    }

    /**
     * Determine if user can update the listing.
     */
    public function update(User $user, Listing $listing): bool
    {
        if ($user->id !== $listing->landlord_id) {
            return false;
        }

        // Can only edit if status allows it
        return $listing->status->isEditable();
    }

    /**
     * Determine if user can submit listing for review.
     */
    public function submit(User $user, Listing $listing): bool
    {
        if ($user->id !== $listing->landlord_id) {
            return false;
        }

        // Can only submit drafts
        return $listing->status === ListingStatus::DRAFT;
    }

    /**
     * Determine if user can delete the listing.
     */
    public function delete(User $user, Listing $listing): bool
    {
        if ($user->id !== $listing->landlord_id) {
            return false;
        }

        // Cannot delete active or pending listings
        return !in_array($listing->status, [
            ListingStatus::ACTIVE,
            ListingStatus::PENDING_REVIEW
        ]);
    }

    /**
     * Determine if user can restore the listing.
     */
    public function restore(User $user, Listing $listing): bool
    {
        return $user->id === $listing->landlord_id;
    }

    /**
     * Determine if user can permanently delete the listing.
     */
    public function forceDelete(User $user, Listing $listing): bool
    {
        // Never allow force delete (compliance requirement)
        return false;
    }
}
