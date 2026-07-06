<?php

namespace App\Enums;

/**
 * MaintenanceVisitWindow Enum
 *
 * The tenant's preferred window for a repair visit. Advisory only — the
 * landlord confirms the actual appointment when assigning a vendor.
 */
enum MaintenanceVisitWindow: string
{
    case MORNING = 'morning';
    case AFTERNOON = 'afternoon';
    case EVENING = 'evening';
    case WEEKEND = 'weekend';
    case ANY = 'any';
}
