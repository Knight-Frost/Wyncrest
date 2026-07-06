<?php

namespace App\Enums;

/**
 * MaintenanceOnset Enum
 *
 * When the tenant first noticed the issue. A coarse, tenant-friendly bucket
 * (not an exact timestamp) so the landlord can gauge how long it has persisted.
 */
enum MaintenanceOnset: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case THIS_WEEK = 'this_week';
    case OVER_A_WEEK = 'over_a_week';
    case NOT_SURE = 'not_sure';
}
