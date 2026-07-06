<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\Document;
use App\Models\User;

/**
 * DocumentPolicy
 *
 * Authorises document actions.
 *
 * SECURITY:
 * - All comparisons use strict === with (int) casts to prevent type-juggling
 *   attacks where an int user ID is compared against a string value.
 * - Landlord cross-access is granted ONLY for a document attached to an
 *   Application on one of that landlord's own listings — a landlord reviewing
 *   an applicant needs to open their proof-of-income/ID. No other cross-access
 *   is granted; admin access to documents should be added in a dedicated admin
 *   controller + policy gate in a future pass.
 */
class DocumentPolicy
{
    /**
     * Any authenticated user may call the index action.
     * The controller always filters to the authenticated user's own documents,
     * so this gate is intentionally permissive.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A user may view a document if they are the owner OR the uploader, OR
     * they are the landlord of the Application this document is attached to.
     */
    public function view(User $user, Document $document): bool
    {
        $userId = (int) $user->id;
        $ownerId = (int) $document->owner_user_id;
        $uploaderId = (int) $document->uploaded_by_id;

        if ($userId === $ownerId || $userId === $uploaderId) {
            return true;
        }

        if ($document->related_type === Application::class && $document->related !== null) {
            return $userId === (int) $document->related->landlord_id;
        }

        return false;
    }

    /**
     * Any authenticated user may create (upload) a document for themselves.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Only the document owner may delete their document.
     */
    public function delete(User $user, Document $document): bool
    {
        return (int) $user->id === (int) $document->owner_user_id;
    }
}
