<?php

namespace App\Providers;

use App\Events\LedgerEntryMarkedOverdue;
use App\Events\ListingChangesRequested;
use App\Events\ListingPublished;
use App\Events\ListingRejected;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RentGenerated;
use App\Events\UserCreated;
use App\Listeners\CreateNotificationListener;
use App\Listeners\LogUserCreated;
use App\Listeners\SendListingChangesRequestedNotification;
use App\Listeners\SendListingPublishedNotification;
use App\Listeners\SendListingRejectedNotification;
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

        // Listing lifecycle events
        // Audit is written directly in the controller (approve/reject), so
        // these listeners only fan out the landlord notification.
        ListingPublished::class => [
            SendListingPublishedNotification::class,
        ],
        ListingRejected::class => [
            SendListingRejectedNotification::class,
        ],
        ListingChangesRequested::class => [
            SendListingChangesRequestedNotification::class,
        ],

        // User lifecycle events
        UserCreated::class => [
            LogUserCreated::class,
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
