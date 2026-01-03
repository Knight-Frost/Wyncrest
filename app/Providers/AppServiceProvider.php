<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Observers\ContractObserver;
use App\Observers\LedgerEntryObserver;
use App\Observers\NotificationObserver;

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
    }
}