<?php

namespace App\Enums;

/**
 * MaintenanceCategory Enum
 *
 * Classifies the type of maintenance issue reported.
 */
enum MaintenanceCategory: string
{
    case PLUMBING = 'plumbing';
    case ELECTRICAL = 'electrical';
    case APPLIANCE = 'appliance';
    case HVAC = 'hvac';
    case STRUCTURAL = 'structural';
    case PEST = 'pest';
    case SECURITY = 'security';
    case LOCKS = 'locks';
    case WINDOWS = 'windows';
    case FLOORING = 'flooring';
    case WATER_DAMAGE = 'water_damage';
    case SHARED_AREA = 'shared_area';
    case GENERAL = 'general';
}
