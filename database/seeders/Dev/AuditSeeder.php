<?php

namespace Database\Seeders\Dev;

use App\Enums\ListingStatus;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Listing;

/**
 * AuditSeeder — append-only audit activity, all tied to real seeded outcomes.
 *
 * The financial actions already self-audited while LedgerSeeder ran (every rent
 * entry created). This seeder adds the surrounding privileged activity that
 * GENUINELY happened in this seed run — so the admin audit-log investigation
 * screen is populated with real, explainable events and nothing fabricated:
 *
 *   - identity_verified   — for every verified demo user (they were approved)
 *   - listing_published   — for every active/published listing
 *   - feature_enabled     — for every landlord feature grant
 *   - contract_accepted   — for every active lease (the tenant accepted it)
 *   - admin_login         — a single sign-in by the seeded admin, today
 *
 * No bulk random history, no invented rate-limit storms, no events for things
 * that did not occur.
 */
class AuditSeeder extends DevSeeder
{
    public function run(): void
    {
        $admin = $this->superAdmin();
        $before = AuditLog::count();

        $this->seedAdminLogin($admin);
        $this->seedIdentityVerifications($admin);
        $this->seedListingModeration($admin);
        $this->seedFeatureGrants($admin);
        $this->seedContractAcceptances();

        $added = AuditLog::count() - $before;
        $total = AuditLog::count();
        $this->command?->info("  ✓ Audit: +{$added} privileged activity rows ({$total} total incl. ledger self-audits).");
    }

    protected function seedAdminLogin(?object $admin): void
    {
        if ($admin) {
            AuditLog::factory()->today()->forActor($admin)->create([
                'action' => 'admin_login',
                'severity' => 'info',
                'description' => 'Admin signed in to the platform.',
            ]);
        }
    }

    /** One identity_verified entry per verified demo user (matches reality). */
    protected function seedIdentityVerifications(?object $admin): void
    {
        if (! $admin) {
            return;
        }

        foreach (array_merge(SeedCatalog::LANDLORDS, SeedCatalog::TENANTS) as $person) {
            if ($person['verification'] !== 'verified') {
                continue;
            }
            if (! ($user = $this->user($person['key']))) {
                continue;
            }

            AuditLog::factory()->forActor($admin)->aboutSubject($user)->create([
                'action' => 'identity_verified',
                'severity' => 'warning',
                'description' => 'User identity verified by admin.',
                'created_at' => now()->subDays(20),
            ]);
        }
    }

    /** listing_published for each listing that is actually active/published. */
    protected function seedListingModeration(?object $admin): void
    {
        $listings = Listing::where('status', ListingStatus::ACTIVE->value)->orderBy('id')->get();

        foreach ($listings as $listing) {
            $factory = AuditLog::factory()->aboutSubject($listing);
            if ($admin) {
                $factory = $factory->forActor($admin);
            }
            $factory->create([
                'action' => 'listing_published',
                'severity' => 'info',
                'description' => 'Listing approved and published.',
                'created_at' => now()->subDays(7),
            ]);
        }
    }

    /** feature_enabled for each landlord that received a feature grant. */
    protected function seedFeatureGrants(?object $admin): void
    {
        if (! $admin) {
            return;
        }

        foreach (SeedCatalog::LANDLORDS as $person) {
            if (empty(SeedCatalog::FEATURE_TIERS[$person['features']] ?? [])) {
                continue;
            }
            if (! ($landlord = $this->user($person['key']))) {
                continue;
            }

            AuditLog::factory()->forActor($admin)->aboutSubject($landlord)->create([
                'action' => 'feature_enabled',
                'severity' => 'info',
                'description' => 'Platform feature enabled for landlord.',
                'created_at' => now()->subDays(19),
            ]);
        }
    }

    /** contract_accepted for each active lease (the tenant accepted it). */
    protected function seedContractAcceptances(): void
    {
        $contracts = Contract::orderBy('created_at')->get();

        foreach ($contracts as $contract) {
            $tenant = $contract->tenant;
            $factory = AuditLog::factory()->aboutSubject($contract);
            if ($tenant) {
                $factory = $factory->forActor($tenant);
            }
            $factory->create([
                'action' => 'contract_accepted',
                'severity' => 'info',
                'description' => 'Tenant accepted contract.',
                'created_at' => $contract->start_date,
            ]);
        }
    }
}
