<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Billing jobs run on the BILLING calendar (config/billing.php) so "01:00 on
// the 1st" is the same calendar day the ledger's due dates are written in.
// Each run is also recorded in storage/logs/scheduler-*.log and, through the
// commands' own audit rows, in the audit log (see docs/OPERATIONS.md).

// Phase 3.4 - Automated Rent Generation
// Runs daily at 1:00 AM
Schedule::command('ledger:generate-rent')
    ->timezone(config('billing.timezone'))
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-rent.log'));

// Contract expiry: close out ACTIVE contracts past their end_date so
// statuses, analytics, and the renew gate stay truthful. Runs after rent
// generation (which already refuses to bill past end_date).
Schedule::command('contracts:mark-expired')
    ->timezone(config('billing.timezone'))
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-contracts.log'));

// Phase 3.4 - Overdue Detection
// Runs daily at 2:00 AM (after rent generation)
Schedule::command('ledger:mark-overdue')
    ->timezone(config('billing.timezone'))
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-overdue.log'));

// Phase 3.6 - Notification Delivery (Email)
// Runs every 5 minutes
Schedule::command('notifications:deliver')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/notifications-delivery.log'));

// Phase 3.7 - Notification Delivery (SMS)
// Runs every 5 minutes
Schedule::command('notifications:sms-deliver')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/notifications-sms.log'));

// Phase 3.9 - Daily Notification Digest
// Runs daily at 8:00 AM
Schedule::command('notifications:digest-daily')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/notifications-digest-daily.log'));

// Phase 3.9 - Weekly Notification Digest
// Runs every Monday at 9:00 AM
Schedule::command('notifications:digest-weekly')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/notifications-digest-weekly.log'));
