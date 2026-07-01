<?php

namespace Database\Seeders\Dev;

use App\Models\Admin;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Base class for the development sub-seeders. Provides small shared helpers for
 * resolving demo accounts (created by UserSeeder) by their catalog key.
 */
abstract class DevSeeder extends Seeder
{
    /** The reserved test email domain for all demo accounts. */
    protected function domain(): string
    {
        return config('seed.development.email_domain', 'wyncrest.test');
    }

    /** Demo password shared by every demo account (local development only). */
    protected function demoPassword(): string
    {
        return config('seed.development.password', 'password');
    }

    /** Currency for demo money (the platform presents GH₵). */
    protected function currency(): string
    {
        return config('seed.currency', 'GHS');
    }

    /** Resolve a catalog user key (e.g. 'tenant.owing') to its User. */
    protected function user(string $key): ?User
    {
        return User::where('email', SeedCatalog::email($key))->first();
    }

    /** The seeded super admin (first admin row). */
    protected function superAdmin(): ?Admin
    {
        return Admin::orderBy('id')->first();
    }

    /** Resolve a PROPERTIES catalog key to its Property model. */
    protected function property(string $propertyKey): ?Property
    {
        foreach (SeedCatalog::PROPERTIES as $p) {
            if ($p['key'] === $propertyKey) {
                $landlord = $this->user($p['landlord']);

                return $landlord
                    ? Property::where('landlord_id', $landlord->id)->where('name', $p['name'])->first()
                    : null;
            }
        }

        return null;
    }

    /** Resolve a UNITS catalog entry to its Unit model. */
    protected function unitFromCatalog(array $catalogUnit): ?Unit
    {
        $property = $this->property($catalogUnit['property']);

        return $property
            ? Unit::where('property_id', $property->id)->where('unit_number', $catalogUnit['number'])->first()
            : null;
    }

    /** The (single) listing attached to a unit, if any. */
    protected function listingForUnit(Unit $unit): ?Listing
    {
        return Listing::where('unit_id', $unit->id)->first();
    }
}
