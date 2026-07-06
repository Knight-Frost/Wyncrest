<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\Application;
use App\Models\User;

/**
 * ApplicationPolicy
 *
 * Authorizes application actions based on user role and ownership.
 * SECURITY: Uses strict type comparisons (===) and (int) casts throughout
 * to guard against int/string type mismatch on IDs.
 */
class ApplicationPolicy
{
    /**
     * Determine whether the user can list applications.
     * Both tenants and landlords may list their own applications.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->user_type, [UserType::LANDLORD, UserType::TENANT], true);
    }

    /**
     * Determine whether the user can view a specific application.
     * Only the applying tenant or the target landlord may view it — except a
     * DRAFT application, which is still private to the tenant and is not yet
     * visible to the landlord (it hasn't been submitted).
     */
    public function view(User $user, Application $application): bool
    {
        $userId = (int) $user->id;
        $tenantId = (int) $application->tenant_id;
        $landlordId = (int) $application->landlord_id;

        if ($application->status->isDraft()) {
            return $userId === $tenantId;
        }

        return $userId === $tenantId || $userId === $landlordId;
    }

    /**
     * Determine whether the user can create an application.
     * Only tenants may apply.
     */
    public function create(User $user): bool
    {
        return $user->user_type === UserType::TENANT;
    }

    /**
     * Determine whether the tenant can withdraw their own application.
     * Only the submitting tenant may withdraw, and only while it is still active.
     */
    public function withdraw(User $user, Application $application): bool
    {
        $userId = (int) $user->id;
        $tenantId = (int) $application->tenant_id;

        return $userId === $tenantId && $application->status->canBeWithdrawn();
    }

    /**
     * Determine whether the tenant can edit / submit / delete their draft.
     * Only the owning tenant, and only while the application is still a draft.
     */
    public function update(User $user, Application $application): bool
    {
        return $this->ownsDraft($user, $application);
    }

    public function submit(User $user, Application $application): bool
    {
        return $this->ownsDraft($user, $application);
    }

    public function delete(User $user, Application $application): bool
    {
        return $this->ownsDraft($user, $application);
    }

    /**
     * Determine whether the tenant can attach a document to this application.
     * The owning tenant may add documents while the application is a draft or
     * still in flight (draft / submitted / in_review / landlord_review /
     * needs_action) — i.e. any non-final state.
     */
    public function uploadDocument(User $user, Application $application): bool
    {
        $ownsIt = (int) $user->id === (int) $application->tenant_id;

        return $ownsIt && ! $application->status->isFinal();
    }

    /**
     * Determine whether the landlord can request more info on an application.
     * Only the listing's landlord, and only while the application is active.
     */
    public function requestInfo(User $user, Application $application): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $application->landlord_id;

        return $userId === $landlordId && $application->status->isActive();
    }

    /**
     * Determine whether the landlord can shortlist/unshortlist an application.
     * Shortlisting is an internal organisational flag, not a decision — it may
     * be toggled any time before a final outcome (approved/rejected/withdrawn),
     * but not on a tenant's still-private draft.
     */
    public function shortlist(User $user, Application $application): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $application->landlord_id;

        return $userId === $landlordId
            && ! $application->status->isDraft()
            && ! $application->status->isFinal();
    }

    /**
     * Shared guard: the user owns this application and it is still a draft.
     */
    private function ownsDraft(User $user, Application $application): bool
    {
        return (int) $user->id === (int) $application->tenant_id
            && $application->status->isDraft();
    }

    /**
     * Determine whether the landlord can decide (approve/reject) an application.
     * Only the listing's landlord may decide, and only while the application is active.
     */
    public function decide(User $user, Application $application): bool
    {
        $userId = (int) $user->id;
        $landlordId = (int) $application->landlord_id;

        return $userId === $landlordId && $application->status->isActive();
    }
}
