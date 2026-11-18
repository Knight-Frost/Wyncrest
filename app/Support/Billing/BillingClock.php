<?php

namespace App\Support\Billing;

use Carbon\Carbon;

/**
 * BillingClock
 *
 * The single answer to "what calendar date is it for billing purposes".
 *
 * Ledger dates (billing periods, due dates) are stored as plain calendar
 * dates. Comparing them with `now()` in the server's time zone made the
 * answer depend on where the server runs, and on a UTC server a charge due
 * "today" in a UTC+N billing zone could flip overdue a day early or late.
 * Every billing consumer (rent generation, overdue sweep, late-fee
 * eligibility, expiry, schedule-change validation) asks this clock instead.
 *
 * The returned Carbon is midnight of the billing-zone date, expressed in the
 * APP time zone, so it compares correctly with the `date`-cast attributes
 * Eloquent produces (also app-zone midnights).
 */
class BillingClock
{
    public static function timezone(): string
    {
        return (string) config('billing.timezone', 'Africa/Accra');
    }

    /** Today's billing calendar date (app-zone midnight). */
    public static function today(): Carbon
    {
        return Carbon::parse(
            Carbon::now(self::timezone())->toDateString(),
            config('app.timezone', 'UTC')
        )->startOfDay();
    }

    /**
     * The last day on which an unpaid charge due on $dueDate is still current.
     * After this date it is overdue.
     */
    public static function overdueCutoffFor(\DateTimeInterface $dueDate): Carbon
    {
        return Carbon::parse($dueDate->format('Y-m-d'), config('app.timezone', 'UTC'))
            ->startOfDay()
            ->addDays(max(0, (int) config('billing.grace_days', 0)));
    }

    /** Whether a charge due on $dueDate is past its overdue cutoff today. */
    public static function isPastDue(\DateTimeInterface $dueDate): bool
    {
        return self::overdueCutoffFor($dueDate)->lt(self::today());
    }

    /**
     * Charges with a due date strictly before this date are overdue today.
     * (today - grace_days), as a Y-m-d string for whereDate().
     */
    public static function overdueBeforeDate(): string
    {
        return self::today()->subDays(max(0, (int) config('billing.grace_days', 0)))->toDateString();
    }
}
