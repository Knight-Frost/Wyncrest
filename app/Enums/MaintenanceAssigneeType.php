<?php

namespace App\Enums;

/**
 * MaintenanceAssigneeType Enum
 *
 * Classifies who a maintenance request has been assigned to.
 */
enum MaintenanceAssigneeType: string
{
    case VENDOR = 'vendor';
    case STAFF = 'staff';
}
