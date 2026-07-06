<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * ProductionSeeder — safe baseline initialization ONLY.
 *
 * Creates:
 *   - Reference data (feature definitions) via ReferenceDataSeeder (idempotent).
 *   - OPTIONALLY a single super admin, bootstrapped from environment variables.
 *
 * NEVER creates demo tenants, landlords, properties, listings, applications,
 * contracts, ledger entries, payments, notifications or any fabricated data.
 *
 * Fully IDEMPOTENT — re-running it never duplicates rows or destroys data.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Reference/system data (safe, idempotent, shared with development).
        $this->call(ReferenceDataSeeder::class);

        // 2. Optional super-admin bootstrap — only when ALL env vars are present.
        $this->bootstrapSuperAdmin();

        $this->command?->info('Production baseline seeding complete (no demo data created).');
    }

    /**
     * Create the super admin only if the bootstrap env vars are configured.
     * Skips with a clear warning otherwise — production never invents admins.
     */
    protected function bootstrapSuperAdmin(): void
    {
        $email = config('seed.bootstrap_admin.email');
        $name = config('seed.bootstrap_admin.name');
        $password = config('seed.bootstrap_admin.password');

        if (! $email || ! $name || ! $password) {
            $message = 'Skipping super-admin bootstrap: set WYNCREST_BOOTSTRAP_ADMIN_EMAIL, '
                .'WYNCREST_BOOTSTRAP_ADMIN_NAME and WYNCREST_BOOTSTRAP_ADMIN_PASSWORD to create one '
                .'(legacy NEXUS_BOOTSTRAP_ADMIN_* still works as a fallback).';
            $this->command?->warn('  ⚠ '.$message);
            \Illuminate\Support\Facades\Log::warning('[ProductionSeeder] '.$message);

            return;
        }

        // firstOrCreate: never overwrite an existing admin's credentials.
        $admin = Admin::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_super_admin' => true,
                'is_active' => true,
            ],
        );

        $verb = $admin->wasRecentlyCreated ? 'Created' : 'Found existing';
        $this->command?->info("  ✓ {$verb} super admin: {$email}");
    }
}
