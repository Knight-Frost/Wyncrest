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
use Database\Seeders\Dev\ConversationSeeder;
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
 * and analytic is meaningful — without demo noise. Each account exists to exercise
 * a specific scenario (see docs/SEEDED_SCENARIOS.md):
 *   - 4 admins (1 super, 2 scoped [content + finance], 1 pending invite);
 *     7 landlords (4 operating + 1 empty-state + 1 pending-verification +
 *     1 suspended); 9 tenants (4 good + 1 owing + 1 owing-with-late-fee +
 *     2 former [terminated/expired] + 1 unverified)
 *   - 4 properties / 13 units; listings across active / pending-review / draft /
 *     inactive so browse, the moderation queue and occupied units are testable
 *   - 8 leases (6 active + 1 terminated + 1 expired) with an immutable,
 *     mathematically-consistent ledger: paid-to-zero, one owing EXACTLY one month,
 *     one owing rent + a REAL late fee, and two settled former leases
 *   - verification records (incl. pending/needs-info/rejected), feature gates,
 *     live applications, maintenance, reviews (incl. from a former tenant),
 *     messaging threads (read/unread), and notifications/audit rows that every
 *     map back to a real seeded event
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
        UserSeeder::class,            // 4 admins (super/2 scoped/pending), 7 landlords, 9 tenants
        VerificationSeeder::class,    // identity verification requests + statuses
        FeatureGateSeeder::class,     // per-landlord feature access (full/limited/none)
        PropertySeeder::class,        // 4 properties + 13 units
        ListingSeeder::class,         // listings: active/pending_review/draft/inactive
        ApplicationSeeder::class,     // approved histories + a few live applicants
        ContractSeeder::class,        // 8 leases: 6 active + 1 terminated + 1 expired
        LedgerSeeder::class,          // immutable ledger: paid-to-zero, owing, late fee, former
        MaintenanceSeeder::class,     // one maintenance request per active lease
        ReviewSeeder::class,          // reviews (approved/pending/rejected + responses)
        NotificationSeeder::class,    // in-app notifications tied to real events
        EngagementSeeder::class,      // saved listings, email logs, media metadata
        ConversationSeeder::class,    // tenant↔landlord messaging threads (read/unread)
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
        $out->writeln('  <comment>Admins</comment> (4 records: 3 active logins, 1 pending invite)');
        $out->writeln('    '.str_pad(SeedCatalog::email('admin'), 30).'super admin, full access');
        $out->writeln('    '.str_pad(SeedCatalog::email('reviewer'), 30).'scoped admin — content (verifications, listings, reviews, audit)');
        $out->writeln('    '.str_pad(SeedCatalog::email('finance'), 30).'scoped admin — finance (contracts, ledger, analytics, audit)');
        $out->writeln('    '.str_pad(SeedCatalog::email('pending.admin'), 30).'pending invite, not an active login');

        $out->writeln('  <comment>Landlords</comment>');
        foreach (SeedCatalog::LANDLORDS as $l) {
            $out->writeln('    '.str_pad(SeedCatalog::email($l['key']), 30).$l['purpose']);
        }

        $out->writeln('  <comment>Tenants</comment>');
        foreach (SeedCatalog::TENANTS as $t) {
            $status = match ($t['standing']) {
                'owing' => 'owes exactly one month',
                'latefee' => 'owes one month + a late fee',
                'former' => 'former tenant (lease ended, paid up)',
                null => 'unverified, no lease (empty state)',
                default => 'good standing (paid up)',
            };
            $out->writeln('    '.str_pad(SeedCatalog::email($t['key']), 30).$status);
        }

        $out->writeln('  Verify the graph + ledger: <info>php artisan wyncrest:seed:verify</info>');
        $out->writeln('');
    }
}
