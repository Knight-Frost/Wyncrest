<?php

namespace App\Enums;

/**
 * UnitAvailabilityStatus Enum
 *
 * Defines the availability states of a unit.
 */
enum UnitAvailabilityStatus: string
{
    case AVAILABLE = 'available';
    case OCCUPIED = 'occupied';
    case PENDING = 'pending';
    case MAINTENANCE = 'maintenance';
    case UNLISTED = 'unlisted';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Available',
            self::OCCUPIED => 'Occupied',
            self::PENDING => 'Pending',
            self::MAINTENANCE => 'Under Maintenance',
            self::UNLISTED => 'Unlisted',
        };
    }

    /**
     * Check if unit can have active listing
     */
    public function canBeListed(): bool
    {
        return in_array($this, [self::AVAILABLE, self::PENDING]);
    }

    /**
     * Check if unit is rentable
     */
    public function isRentable(): bool
    {
        return $this === self::AVAILABLE;
    }
}
