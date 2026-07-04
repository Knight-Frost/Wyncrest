<?php

/*
|--------------------------------------------------------------------------
| Seeding Configuration
|--------------------------------------------------------------------------
|
| Controls the two seeding modes:
|
|   - development : a SMALL, controlled, fully-truthful demo world (1 admin,
|                   5 landlords, 5 tenants — 4 in good standing + 1 owing one
|                   month — with real properties, listings, leases and an
|                   immutable, derivable ledger). Predictable local-only creds.
|
|   - production  : safe baseline only — reference/system data (feature
|                   definitions) and an OPTIONAL bootstrap admin from env vars.
|                   NEVER any demo people, money, or inventory.
|
| Mode resolution (see Database\Seeders\DatabaseSeeder::resolveMode()):
|   1. WYNCREST_SEED_MODE env, if set ('development' | 'production')
|   2. otherwise: production app environment => 'production', else 'development'
|
| ENV NAMING: the project is migrating to WYNCREST_* env vars. The legacy
| NEXUS_* names are still read as deprecated fallbacks so existing local .env
| files keep working — prefer WYNCREST_* going forward.
|
*/

return [
    // Explicit mode override. Leave null to auto-resolve from APP_ENV.
    'mode' => env('WYNCREST_SEED_MODE', env('NEXUS_SEED_MODE')),

    // -------------------------------------------------------------------------
    // Development demo data
    // -------------------------------------------------------------------------
    'development' => [
        // Shared password for EVERY demo account. Local development ONLY — this is
        // intentionally printed to the console after seeding. Never used in prod.
        'password' => env('WYNCREST_DEMO_PASSWORD', env('NEXUS_DEMO_PASSWORD', 'password')),

        // All demo emails use a reserved, non-routable test domain so they can
        // never collide with or email a real person.
        'email_domain' => env('WYNCREST_DEMO_DOMAIN', env('NEXUS_DEMO_DOMAIN', 'wyncrest.test')),

        // Expected CATALOG account counts for the controlled development world
        // (SeedCatalog::LANDLORDS / ::TENANTS). The VerificationSeeder adds a few
        // standalone accounts on top to keep the admin review queue non-empty; the
        // verify command accounts for those separately.
        'tenants' => (int) env('WYNCREST_DEMO_TENANTS', env('NEXUS_DEMO_TENANTS', 9)),
        'landlords' => (int) env('WYNCREST_DEMO_LANDLORDS', env('NEXUS_DEMO_LANDLORDS', 7)),
    ],

    // -------------------------------------------------------------------------
    // Production bootstrap admin (OPTIONAL, env-driven)
    // -------------------------------------------------------------------------
    // If all three are present, ProductionSeeder firstOrCreate()s a single super
    // admin. If any are missing, it is SKIPPED with a logged warning — production
    // seeding never invents credentials. (./dev.sh --prod supplies safe local
    // defaults so the production preview always has exactly one admin to log in.)
    'bootstrap_admin' => [
        'email' => env('WYNCREST_BOOTSTRAP_ADMIN_EMAIL', env('NEXUS_BOOTSTRAP_ADMIN_EMAIL')),
        'name' => env('WYNCREST_BOOTSTRAP_ADMIN_NAME', env('NEXUS_BOOTSTRAP_ADMIN_NAME')),
        'password' => env('WYNCREST_BOOTSTRAP_ADMIN_PASSWORD', env('NEXUS_BOOTSTRAP_ADMIN_PASSWORD')),
    ],

    // Safety latch: refuse to run the development seeder while APP_ENV is
    // production unless this is explicitly flipped on. Prevents an accidental
    // `WYNCREST_SEED_MODE=development` from poisoning a production database.
    'allow_dev_seed_in_production' => (bool) env(
        'WYNCREST_ALLOW_DEV_SEED_IN_PROD',
        env('NEXUS_ALLOW_DEV_SEED_IN_PROD', false),
    ),

    // Currency used for demo money (the platform presents GH₵).
    'currency' => env('WYNCREST_DEMO_CURRENCY', env('NEXUS_DEMO_CURRENCY', 'GHS')),
];
