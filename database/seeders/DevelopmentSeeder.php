<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\Dev\ApplicationSeeder;
use Database\Seeders\Dev\AuditSeeder;
use Database\Seeders\Dev\ContractSeeder;
use Database\Seeders\Dev\EngagementSeeder;
use Database\Seeders\Dev\FeatureGateSeeder;
use Database\Seeders\Dev\LedgerSeeder;
use Database\Seeders\Dev\ListingSeeder;
use Database\Seeders\Dev\MaintenanceSeeder;
use Database\Seeders\Dev\NotificationSeeder;
use Database\Seeders\Dev\PropertySeeder;
use Database\Seeders\Dev\ReviewSeeder;
use Database\Seeders\Dev\SeedCatalog;
use Database\Seeders\Dev\UserSeeder;
use Database\Seeders\Dev\VerificationSeeder;
use Illuminate\Database\Seeder;

/**
 * DevelopmentSeeder — the controlled Wyncrest development world.
 *
 * Builds a SMALL, recognisable, fully-truthful platform so every dashboard, list
 * and analytic is meaningful — without demo noise:
 *   - 1 admin, 5 landlords (incl. 1 empty-state), 5 tenants (4 good + 1 owing)
 *   - 4 properties / 10 units; listings across active / pending-review / draft /
 *     inactive so browse, the moderation queue and occupied units are testable
 *   - 5 active leases with an immutable, mathematically-consistent ledger: four
 *     tenants paid to zero, one owing EXACTLY one month (no invented late fee)
 *   - verification records, feature gates, a couple of live applications, one
 *     maintenance request per lease, a few reviews, and notifications/audit rows
 *     that every map back to a real seeded event
 *
 * All data is obviously demo (fictional names, @wyncrest.test emails, shared
 * password). Each focused sub-seeder owns one slice and reads its prerequisites
 * back from the database, so the pipeline stays modular and order-driven.
 */
class DevelopmentSeeder extends Seeder
{
    /**
     * Sub-seeders, in dependency order. Each is independently runnable but the
     * sequence guarantees prerequisites exist (users → properties → listings → …).
     */
    private const PIPELINE = [
        ReferenceDataSeeder::class,   // shared feature definitions
        UserSeeder::class,            // 1 admin, 5 landlords, 5 tenants
        VerificationSeeder::class,    // identity verification requests + statuses
        FeatureGateSeeder::class,     // per-landlord feature access (full/limited)
        PropertySeeder::class,        // 4 properties + 10 units
        ListingSeeder::class,         // listings: active/pending_review/draft/inactive
        ApplicationSeeder::class,     // approved histories + a few live applicants
        ContractSeeder::class,        // 5 active leases
        LedgerSeeder::class,          // immutable ledger: 4 paid-to-zero, 1 owing one month
        MaintenanceSeeder::class,     // one maintenance request per active lease
        ReviewSeeder::class,          // reviews (approved/pending/rejected + responses)
        NotificationSeeder::class,    // in-app notifications tied to real events
        EngagementSeeder::class,      // saved listings, email logs, media metadata
        AuditSeeder::class,           // privileged audit activity tied to real state
    ];

    public function run(): void
    {
        $this->guardAgainstProduction();

        foreach (self::PIPELINE as $seeder) {
            $this->call($seeder);
        }

        $this->printSummary();
    }

    /**
     * Refuse to run the demo seeder against a production database unless explicitly
     * allowed. Prevents an accidental WYNCREST_SEED_MODE=development from poisoning
     * production with fake people and money.
     */
    protected function guardAgainstProduction(): void
    {
        if (app()->environment('production') && ! config('seed.allow_dev_seed_in_production')) {
            throw new \RuntimeException(
                'Refusing to run DevelopmentSeeder in production. This creates demo data. '
                .'If you really intend this, set WYNCREST_ALLOW_DEV_SEED_IN_PROD=true.'
            );
        }
    }

    /**
     * Print a concise summary of the seeded world + local-only login credentials.
     */
    protected function printSummary(): void
    {
        $out = $this->command?->getOutput();
        if (! $out) {
            return;
        }

        $password = config('seed.development.password');

        $counts = [
            ['Admins', \App\Models\Admin::count()],
            ['Landlords', User::where('user_type', 'landlord')->count()],
            ['Tenants', User::where('user_type', 'tenant')->count()],
            ['Properties', Property::count()],
            ['Units', Unit::count()],
            ['Listings', Listing::count()],
            ['Contracts', Contract::count()],
            ['Ledger entries', LedgerEntry::count()],
        ];

        $out->writeln('');
        $out->writeln('  <info>═══════════════════════════════════════════════════════</info>');
        $out->writeln('  <info>  WYNCREST DEVELOPMENT SEED COMPLETE</info>');
        $out->writeln('  <info>═══════════════════════════════════════════════════════</info>');
        $this->command->table(['Entity', 'Count'], $counts);

        $out->writeln("  <comment>Demo logins</comment> (local development only), password: <info>{$password}</info>");
        $out->writeln('  <comment>Admin</comment>');
        $out->writeln('    '.SeedCatalog::email('admin').'   (system administrator)');

        $out->writeln('  <comment>Landlords</comment>');
        foreach (SeedCatalog::LANDLORDS as $l) {
            $out->writeln('    '.str_pad(SeedCatalog::email($l['key']), 30).$l['purpose']);
        }

        $out->writeln('  <comment>Tenants</comment>');
        foreach (SeedCatalog::TENANTS as $t) {
            $status = $t['standing'] === 'owing' ? 'owes exactly one month' : 'good standing (paid up)';
            $out->writeln('    '.str_pad(SeedCatalog::email($t['key']), 30).$status);
        }

        $out->writeln('  Verify the graph + ledger: <info>php artisan wyncrest:seed:verify</info>');
        $out->writeln('');
    }
}
