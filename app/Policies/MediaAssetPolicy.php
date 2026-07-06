<?php

namespace App\Policies;

use App\Enums\MediaCollection;
use App\Enums\MediaVisibility;
use App\Models\Admin;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;

/**
 * MediaAssetPolicy
 *
 * Authorises upload, view, and deletion of MediaAssets.
 *
 * SECURITY:
 * - All comparisons use explicit (int) casts to prevent type-juggling attacks.
 * - Tenants may NOT upload to property/unit/listing galleries.
 * - Public assets are viewable by anyone (no-auth needed; handled at controller).
 * - Private/restricted assets are viewable only by owner, resource landlord, or admin.
 */
class MediaAssetPolicy
{
    /**
     * Determine whether a user may upload a new MediaAsset to a given resource.
     *
     * $resource is the attachable model (Property, Unit, Listing, User,
     * MaintenanceRequest) passed from the controller.
     */
    public function upload(User $user, string $collection, ?object $resource = null): bool
    {
        $col = MediaCollection::tryFrom($collection);

        if ($col === null) {
            return false;
        }

        // Avatar: any authenticated user, but only for themselves.
        if ($col === MediaCollection::Avatar) {
            // resource is the User model being updated
            if ($resource instanceof User) {
                return (int) $user->id === (int) $resource->id;
            }

            return false;
        }

        // Maintenance evidence: the tenant who owns the request, or its landlord.
        if ($col === MediaCollection::MaintenanceEvidence) {
            if ($resource instanceof MaintenanceRequest) {
                return (int) $user->id === (int) $resource->tenant_id
                    || (int) $user->id === (int) $resource->landlord_id;
            }

            return false;
        }

        // Gallery collections: only landlords may upload; tenants are blocked.
        if ($col->isGallery()) {
            if (! $user->isLandlord()) {
                return false;
            }

            if ($resource instanceof Property) {
                return (int) $user->id === (int) $resource->landlord_id;
            }

            if ($resource instanceof Unit) {
                // Ownership is through the property
                return (int) $user->id === (int) $resource->property->landlord_id;
            }

            if ($resource instanceof Listing) {
                return (int) $user->id === (int) $resource->landlord_id;
            }

            return false;
        }

        return false;
    }

    /**
     * Determine whether the user may view/stream an existing MediaAsset.
     *
     * Public assets are accessible without authentication (controller skips policy).
     * Private/restricted: owner, resource landlord, or admin only.
     */
    public function view(User $user, MediaAsset $asset): bool
    {
        // Public assets are served directly via Storage URL, not this route.
        // But if the check is called, allow it.
        if ($asset->visibility === MediaVisibility::Public) {
            return true;
        }

        // Owner may always view their own media
        if ((int) $user->id === (int) $asset->owner_user_id) {
            return true;
        }

        // Uploader may view (e.g. admin who uploaded on behalf of someone)
        if ((int) $user->id === (int) $asset->uploaded_by_id) {
            return true;
        }

        // Landlord of the resource can view evidence on their maintenance requests
        $attachable = $asset->attachable;
        if ($attachable instanceof MaintenanceRequest) {
            if ((int) $user->id === (int) $attachable->landlord_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin override for view — called via Gate::before / policy check with Admin guard.
     */
    public function viewAsAdmin(Admin $admin, MediaAsset $asset): bool
    {
        return $admin->is_active;
    }

    /**
     * Determine whether the user may delete a MediaAsset.
     */
    public function delete(User $user, MediaAsset $asset): bool
    {
        // Owner of the media
        if ((int) $user->id === (int) $asset->owner_user_id) {
            return true;
        }

        // Uploader may delete what they uploaded
        if ((int) $user->id === (int) $asset->uploaded_by_id) {
            return true;
        }

        // Landlord of the resource (e.g. they want to remove a photo from their listing)
        $attachable = $asset->attachable;

        if ($attachable instanceof Property && (int) $user->id === (int) $attachable->landlord_id) {
            return true;
        }

        if ($attachable instanceof Unit && (int) $user->id === (int) $attachable->property->landlord_id) {
            return true;
        }

        if ($attachable instanceof Listing && (int) $user->id === (int) $attachable->landlord_id) {
            return true;
        }

        // Landlord of a maintenance request may remove evidence on it too
        // (owner_user_id is always the tenant for this collection).
        if ($attachable instanceof MaintenanceRequest && (int) $user->id === (int) $attachable->landlord_id) {
            return true;
        }

        return false;
    }
}
