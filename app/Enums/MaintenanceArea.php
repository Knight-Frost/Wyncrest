<?php

namespace App\Enums;

/**
 * MaintenanceArea Enum
 *
 * The room or area of the unit where the reported issue is located. Captured
 * on intake so the landlord/vendor knows where to go without guessing.
 */
enum MaintenanceArea: string
{
    case KITCHEN = 'kitchen';
    case BATHROOM = 'bathroom';
    case BEDROOM = 'bedroom';
    case LIVING_ROOM = 'living_room';
    case BALCONY = 'balcony';
    case EXTERIOR = 'exterior';
    case SHARED_AREA = 'shared_area';
    case GARAGE = 'garage';
    case HALLWAY = 'hallway';
    case OTHER = 'other';
}
