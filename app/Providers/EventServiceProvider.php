<?php

namespace App\Providers;

use App\Events\UserCreated;
use App\Events\EmailVerified;
use App\Events\IdentityVerified;
use App\Events\ListingPublished;
use App\Events\ListingSubmittedForReview;
use App\Events\ListingRejected;
use App\Listeners\SendWelcomeEmail;
use App\Listeners\SendEmailVerifiedNotification;
use App\Listeners\SendIdentityVerifiedNotification;
use App\Listeners\SendListingPublishedNotification;
use App\Listeners\NotifyAdminOfListingSubmission;
use App\Listeners\SendListingRejectedNotification;
use App\Listeners\LogUserCreated;
use App\Listeners\LogEmailVerified;
use App\Listeners\LogIdentityVerified;
use App\Listeners\LogListingPublished;
use App\Listeners\LogListingRejected;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * EventServiceProvider
 * 
 * Registers all events and their listeners.
 * All listeners are queued for asynchronous processing.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // User Events
        UserCreated::class => [
            SendWelcomeEmail::class,
            LogUserCreated::class,
        ],
        
        EmailVerified::class => [
            SendEmailVerifiedNotification::class,
            LogEmailVerified::class,
        ],
        
        IdentityVerified::class => [
            SendIdentityVerifiedNotification::class,
            LogIdentityVerified::class,
        ],
        
        // Listing Events
        ListingPublished::class => [
            SendListingPublishedNotification::class,
            LogListingPublished::class,
        ],
        
        ListingSubmittedForReview::class => [
            NotifyAdminOfListingSubmission::class,
        ],
        
        ListingRejected::class => [
            SendListingRejectedNotification::class,
            LogListingRejected::class,
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
        return false; // Explicit registration for clarity
    }
}
