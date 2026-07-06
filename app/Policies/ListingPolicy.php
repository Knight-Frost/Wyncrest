<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Enums\UserType;
use App\Models\Listing;
use App\Models\User;

/**
 * ListingPolicy
 *
 * Authorization rules for listing management.
 * Landlords can only manage their own listings.
 * Editing restrictions based on status.
 * SECURITY: Uses strict type comparisons (===) throughout.
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
        $userId = (int) $user->id;
        $landlordId = (int) $listing->landlord_id;

        return $userId === $landlordId;
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
        $userId = (int) $user->id;
        $landlordId = (int) $listing->landlord_id;

        if ($userId !== $landlordId) {
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
        $userId = (int) $user->id;
        $landlordId = (int) $listing->landlord_id;

        if ($userId !== $landlordId) {
            return false;
        }

        // Drafts submit for the first time; rejected listings resubmit after the
        // landlord has addressed the admin's feedback.
        return in_array($listing->status, [ListingStatus::DRAFT, ListingStatus::REJECTED], true);
    }

    /**
     * Determine if user can withdraw a pending submission back to draft.
     */
    public function withdraw(User $user, Listing $listing): bool
    {
        return (int) $user->id === (int) $listing->landlord_id
            && $listing->status === ListingStatus::PENDING_REVIEW;
    }

    /**
     * Determine if user can deactivate an active listing.
     */
    public function deactivate(User $user, Listing $listing): bool
    {
        return (int) $user->id === (int) $listing->landlord_id
            && $listing->status === ListingStatus::ACTIVE;
    }

    /**
     * Determine if user can reactivate an inactive listing.
     */
    public function reactivate(User $user, Listing $listing): bool
    {
        return (int) $user->id === (int) $listing->landlord_id
            && $listing->status === ListingStatus::INACTIVE;
    }

    /**
     * Determine if user can archive the listing. Not allowed while pending
     * review (withdraw first) or already active (deactivate first) or already archived.
     */
    public function archive(User $user, Listing $listing): bool
    {
        return (int) $user->id === (int) $listing->landlord_id
            && in_array($listing->status, [ListingStatus::DRAFT, ListingStatus::REJECTED, ListingStatus::INACTIVE], true);
    }

    /**
     * Determine if user can restore an archived listing back to draft.
     */
    public function restoreArchived(User $user, Listing $listing): bool
    {
        return (int) $user->id === (int) $listing->landlord_id
            && $listing->status === ListingStatus::ARCHIVED;
    }

    /**
     * Determine if user can delete the listing.
     */
    public function delete(User $user, Listing $listing): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $listing->landlord_id;

        if ($userId !== $landlordId) {
            return false;
        }

        // Cannot delete active or pending listings
        return ! in_array($listing->status, [
            ListingStatus::ACTIVE,
            ListingStatus::PENDING_REVIEW,
        ], true);
    }

    /**
     * Determine if user can restore the listing.
     */
    public function restore(User $user, Listing $listing): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $listing->landlord_id;

        return $userId === $landlordId;
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
