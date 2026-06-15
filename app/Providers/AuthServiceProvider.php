<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Property::class => \App\Policies\PropertyPolicy::class,
        \App\Models\Unit::class => \App\Policies\UnitPolicy::class,
        \App\Models\Listing::class => \App\Policies\ListingPolicy::class,
        \App\Models\Contract::class => \App\Policies\ContractPolicy::class,
        \App\Models\LedgerEntry::class => \App\Policies\LedgerEntryPolicy::class,
        \App\Models\Notification::class => \App\Policies\NotificationPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Define admin gate for convenience
        Gate::define('admin', function ($user) {
            return $user instanceof \App\Models\Admin && $user->is_active;
        });

        // Define super-admin gate
        Gate::define('super-admin', function ($user) {
            return $user instanceof \App\Models\Admin && $user->is_super_admin && $user->is_active;
        });
    }
}
