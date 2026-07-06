<?php

namespace App\Enums;

/**
 * MaintenanceContactMethod Enum
 *
 * How the tenant prefers to be reached about this request. "Message" routes
 * through the in-app maintenance thread the tenant and landlord already share.
 */
enum MaintenanceContactMethod: string
{
    case MESSAGE = 'message';
    case PHONE = 'phone';
    case EMAIL = 'email';
}
