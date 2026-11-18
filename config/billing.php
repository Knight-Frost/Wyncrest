<?php

/*
|--------------------------------------------------------------------------
| Rent billing policy
|--------------------------------------------------------------------------
|
| Owner-approved policy (November 2026 launch closeout): monthly rent is due
| on the FIRST day of each calendar month by default. Authorized admins
| (manage_ledger) may move one unpaid charge's due date, or change the due
| day for a lease's future months. See docs/LEDGER.md "Rent due dates".
|
| Every billing calendar decision ("what is today", "is this charge overdue",
| "which month is next") resolves in ONE time zone so generation, overdue
| marking, late fees, notifications, statements and exports all agree on the
| same calendar date. Wyncrest has no per-lease or per-property time zone, so
| this single application billing time zone is the one source.
|
*/

return [
    // why: Wyncrest bills in GH₵ for Ghanaian leases; Africa/Accra is UTC+0
    // with no DST, so it matches the UTC-stored timestamps today, but naming it
    // keeps the calendar day correct if the app timezone ever changes.
    'timezone' => env('BILLING_TIMEZONE', 'Africa/Accra'),

    // Default due day for new leases (policy: the 1st).
    'default_due_day' => 1,

    // Days after the due date before an unpaid charge becomes OVERDUE.
    // why: 0 preserves the pre-existing behaviour exactly (a charge was marked
    // overdue on the day after its due date); no grace period was configured.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 0),

    // A one-time due-date extension may not move a charge more than this many
    // days past the end of its own billing period.
    'max_extension_days_after_period' => (int) env('BILLING_MAX_EXTENSION_DAYS', 60),

    // Each rent run bills every unbilled period from a lease's latest charge up
    // to today (recovering months missed while the scheduler was down), at
    // most this many per lease per run. See docs/OPERATIONS.md before
    // deploying onto a database whose scheduler has not been running.
    'catch_up_max_periods' => (int) env('BILLING_CATCH_UP_MAX_PERIODS', 12),

    // How many future months the schedule-change preview shows.
    'preview_months' => 6,
];
