<?php

namespace App\Enums;

/**
 * MaintenanceReporter Enum
 *
 * Who filed a maintenance request — the tenant (the normal path) or the
 * landlord (e.g. logged from a routine inspection).
 */
enum MaintenanceReporter: string
{
    case TENANT = 'tenant';
    case LANDLORD = 'landlord';
}
