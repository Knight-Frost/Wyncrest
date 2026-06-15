<?php

namespace App\Enums;

/**
 * UserType Enum
 *
 * Defines the two user types in the system.
 * Admins are NOT users - they have a separate table.
 */
enum UserType: string
{
    case TENANT = 'tenant';
    case LANDLORD = 'landlord';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::TENANT => 'Tenant',
            self::LANDLORD => 'Landlord',
        };
    }

    /**
     * Check if this user type is a landlord
     */
    public function isLandlord(): bool
    {
        return $this === self::LANDLORD;
    }

    /**
     * Check if this user type is a tenant
     */
    public function isTenant(): bool
    {
        return $this === self::TENANT;
    }
}
