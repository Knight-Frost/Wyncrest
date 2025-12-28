<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 3.4 - Automated Rent Generation
// Runs daily at 1:00 AM
Schedule::command('ledger:generate-rent')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-rent.log'));

// Phase 3.4 - Overdue Detection
// Runs daily at 2:00 AM (after rent generation)
Schedule::command('ledger:mark-overdue')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-overdue.log'));