<?php

namespace App\Providers;

use App\Events\LedgerEntryMarkedOverdue;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RentGenerated;
use App\Listeners\CreateNotificationListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Phase 3.5: Notification Events
        RentGenerated::class => [
            CreateNotificationListener::class,
        ],
        LedgerEntryMarkedOverdue::class => [
            CreateNotificationListener::class,
        ],
        PaymentSucceeded::class => [
            CreateNotificationListener::class,
        ],
        PaymentFailed::class => [
            CreateNotificationListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
