<?php

namespace App\Providers;

use App\Models\Property;
use App\Models\Unit;
use App\Models\Listing;
use App\Policies\PropertyPolicy;
use App\Policies\UnitPolicy;
use App\Policies\ListingPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * AuthServiceProvider
 * 
 * Registers authorization policies.
 * Phase 2: Property, Unit, and Listing policies.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Property::class => PropertyPolicy::class,
        Unit::class => UnitPolicy::class,
        Listing::class => ListingPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
