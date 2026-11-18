<?php

namespace App\Enums;

/**
 * How a lease's recurring rent periods are cut.
 *
 * CALENDAR    — periods are calendar months (1st through month end). A
 *               mid-month lease start produces a prorated opening stub
 *               (start → month end); a mid-month lease end produces a
 *               prorated closing stub. Default for every lease created after
 *               the November 2026 first-of-month policy.
 * ANNIVERSARY — legacy: periods run from the lease start day to the day
 *               before the same day next month, always billed in full. Kept
 *               only for leases signed under those terms before the policy,
 *               so their period identities (and so idempotency) carry forward.
 */
enum BillingAnchor: string
{
    case CALENDAR = 'calendar';
    case ANNIVERSARY = 'anniversary';

    public function label(): string
    {
        return match ($this) {
            self::CALENDAR => 'Calendar month',
            self::ANNIVERSARY => 'Lease anniversary (legacy)',
        };
    }
}
