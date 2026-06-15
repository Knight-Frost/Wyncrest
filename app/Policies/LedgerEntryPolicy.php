<?php

namespace App\Policies;

use App\Enums\UserType;
use App\Models\LedgerEntry;
use App\Models\User;

/**
 * LedgerEntryPolicy
 *
 * Authorization rules for ledger access.
 * SECURITY: Uses strict type comparisons (===) throughout.
 *
 * CRITICAL: Ledger entries are read-only for users.
 * No user can modify or delete ledger entries.
 */
class LedgerEntryPolicy
{
    /**
     * Determine if user can view any ledger entries.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->user_type, [UserType::LANDLORD, UserType::TENANT], true);
    }

    /**
     * Determine if user can view the ledger entry.
     */
    public function view(User $user, LedgerEntry $entry): bool
    {
        // Cast IDs to same type for strict comparison
        $userId = (int) $user->id;
        $landlordId = (int) $entry->landlord_id;
        $tenantId = (int) $entry->tenant_id;

        return $userId === $landlordId || $userId === $tenantId;
    }

    /**
     * No user can create ledger entries directly.
     * (Only system via LedgerService)
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * No user can update ledger entries.
     * (Immutability enforced)
     */
    public function update(User $user, LedgerEntry $entry): bool
    {
        return false;
    }

    /**
     * No user can delete ledger entries.
     * (Immutability enforced)
     */
    public function delete(User $user, LedgerEntry $entry): bool
    {
        return false;
    }

    /**
     * Determine if user can pay this ledger entry.
     */
    public function pay(User $user, LedgerEntry $entry): bool
    {
        // Only tenants can pay
        if ($user->user_type !== UserType::TENANT) {
            return false;
        }

        // Only the assigned tenant can pay
        $userId = (int) $user->id;
        $tenantId = (int) $entry->tenant_id;

        if ($userId !== $tenantId) {
            return false;
        }

        // Entry must be in a payable state
        return $entry->canBePaid();
    }
}
