<?php

namespace App\Enums;

/**
 * MaintenanceAccess Enum
 *
 * Whether maintenance/a vendor may enter the unit when the tenant is not home.
 * Drives how the landlord schedules the visit.
 */
enum MaintenanceAccess: string
{
    case YES = 'yes';
    case NO = 'no';
    case CONTACT_FIRST = 'contact_first';
}
