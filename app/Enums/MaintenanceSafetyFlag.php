<?php

namespace App\Enums;

/**
 * MaintenanceSafetyFlag Enum
 *
 * Structured safety/damage indicators a tenant can raise on intake so the
 * landlord can triage urgent hazards at a glance. Stored as a JSON array of
 * these values on the request (empty array = "none of these").
 */
enum MaintenanceSafetyFlag: string
{
    case WATER_LEAK = 'water_leak';
    case NO_POWER = 'no_power';
    case SECURITY = 'security';
    case MOLD = 'mold';
    case PEST = 'pest';
    case INJURY_RISK = 'injury_risk';
    case PROPERTY_DAMAGE = 'property_damage';

    /**
     * Whether this flag denotes a serious hazard that should visibly elevate
     * the request in the landlord's queue.
     */
    public function isSevere(): bool
    {
        return in_array($this, [
            self::WATER_LEAK,
            self::NO_POWER,
            self::SECURITY,
            self::INJURY_RISK,
        ], true);
    }
}
