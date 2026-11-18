<?php

namespace App\Providers;

use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Observers\ContractObserver;
use App\Observers\LedgerEntryObserver;
use App\Observers\NotificationObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 5.2: Event-based cache invalidation
        Contract::observe(ContractObserver::class);
        LedgerEntry::observe(LedgerEntryObserver::class);
        Notification::observe(NotificationObserver::class);

        // WYN-010: durable record of every scheduled task outcome.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\ScheduledTaskFinished::class, [\App\Support\Ops\SchedulerRunLog::class, 'finished']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\ScheduledTaskFailed::class, [\App\Support\Ops\SchedulerRunLog::class, 'failed']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Console\Events\ScheduledTaskSkipped::class, [\App\Support\Ops\SchedulerRunLog::class, 'skipped']);
    }
}
