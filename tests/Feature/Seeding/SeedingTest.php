<?php

namespace Tests\Feature\Seeding;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Enums\UserType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\Feature;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Dev\SeedCatalog;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the development/production seeding system: production safety &
 * idempotency, mode resolution, and the integrity of the development graph
 * (counts, lifecycle coverage, and a mathematically-consistent ledger).
 */
class SeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_seeder_creates_only_reference_data_and_no_demo_data(): void
    {
        $this->seed(ProductionSeeder::class);

        $this->assertSame(count(SeedCatalog::FEATURES), Feature::count());

        // Crucially: production NEVER fabricates people, money, or inventory.
        $this->assertSame(0, User::count());
        $this->assertSame(0, Admin::count());
        $this->assertSame(0, Property::count());
        $this->assertSame(0, Contract::count());
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_production_seeder_is_idempotent(): void
    {
        $this->seed(ProductionSeeder::class);
        $this->seed(ProductionSeeder::class);

        // Re-running must not duplicate reference rows.
        $this->assertSame(count(SeedCatalog::FEATURES), Feature::count());
    }

    public function test_production_bootstrap_admin_is_created_only_from_config(): void
    {
        config([
            'seed.bootstrap_admin.email' => 'ops@example.com',
            'seed.bootstrap_admin.name' => 'Ops Admin',
            'seed.bootstrap_admin.password' => 'secret-password',
        ]);

        $this->seed(ProductionSeeder::class);
        $this->seed(ProductionSeeder::class); // idempotent

        $this->assertSame(1, Admin::where('email', 'ops@example.com')->count());
        $this->assertTrue(Admin::where('email', 'ops@example.com')->first()->is_super_admin);
    }

    public function test_mode_resolution_prefers_explicit_config(): void
    {
        config(['seed.mode' => 'production']);
        $this->assertSame('production', DatabaseSeeder::resolveMode());

        config(['seed.mode' => 'development']);
        $this->assertSame('development', DatabaseSeeder::resolveMode());

        // No explicit mode + non-production env => development.
        config(['seed.mode' => null]);
        $this->assertSame('development', DatabaseSeeder::resolveMode());
    }

    public function test_development_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['seed.allow_dev_seed_in_production' => false]);

        // Invoke the seeder directly so the safety guard's exception surfaces
        // (the artisan db:seed path mocks console output and would mask it).
        $seeder = $this->app->make(DevelopmentSeeder::class);

        try {
            $this->expectException(\RuntimeException::class);
            $seeder->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_development_world_has_exact_counts_and_consistent_ledger(): void
    {
        $this->seed(DevelopmentSeeder::class);

        // Exact, controlled counts — 3 admins (1 super, 1 scoped, 1 pending
        // invite), 5 landlords, 5 tenants.
        $this->assertSame(3, Admin::count());
        $this->assertSame(1, Admin::where('is_super_admin', true)->count());
        $this->assertSame(1, Admin::whereNotNull('invited_at')->whereNull('invite_accepted_at')->count());
        $this->assertSame(5, User::where('user_type', UserType::LANDLORD->value)->count());
        $this->assertSame(5, User::where('user_type', UserType::TENANT->value)->count());
        $this->assertSame(count(SeedCatalog::PROPERTIES), Property::count());
        $this->assertSame(count(SeedCatalog::UNITS), Unit::count());
        $this->assertSame(count(SeedCatalog::UNITS), Listing::count());

        // Exactly one active lease per occupied unit; every contract is active.
        $leased = count(SeedCatalog::leasedUnits());
        $this->assertSame($leased, Contract::count());
        $this->assertSame($leased, Contract::where('status', ContractStatus::ACTIVE->value)->count());

        // The listing statuses this world deliberately includes are present.
        foreach ([ListingStatus::ACTIVE, ListingStatus::PENDING_REVIEW, ListingStatus::DRAFT, ListingStatus::INACTIVE] as $status) {
            $this->assertTrue(
                Listing::where('status', $status->value)->exists(),
                "Expected a listing in status {$status->value}",
            );
        }

        $this->assertLedgerIsConsistent();
        $this->assertTenantStanding();
    }

    /** Payments are negative & linked; obligations positive; balances derivable. */
    protected function assertLedgerIsConsistent(): void
    {
        $this->assertSame(
            0,
            LedgerEntry::where('type', LedgerType::PAYMENT->value)
                ->where(fn ($q) => $q->where('amount_cents', '>=', 0)->orWhereNull('related_rent_entry_id'))
                ->count(),
            'All PAYMENT entries must be negative and linked to an obligation.',
        );

        $this->assertSame(
            0,
            LedgerEntry::whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
                ->where('amount_cents', '<=', 0)->count(),
            'All obligations must be positive.',
        );

        $payments = app(PaymentService::class);
        foreach (User::where('user_type', UserType::TENANT->value)->get() as $tenant) {
            $obligations = LedgerEntry::byTenant($tenant->id)
                ->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])->sum('amount_cents');
            $paid = LedgerEntry::byTenant($tenant->id)->where('type', LedgerType::PAYMENT->value)->sum('amount_cents');

            $this->assertSame(
                $obligations + $paid,
                $payments->getTenantBalance($tenant),
                "Balance for tenant {$tenant->id} must be derivable from the ledger.",
            );
        }
    }

    /**
     * Exactly 4 tenants in good standing (balance 0) and exactly 1 owing tenant
     * who owes EXACTLY one month of rent via a single overdue entry — and no late
     * fee was invented by the seeder.
     */
    protected function assertTenantStanding(): void
    {
        $payments = app(PaymentService::class);

        $good = 0;
        $owing = 0;
        foreach (User::where('user_type', UserType::TENANT->value)->get() as $tenant) {
            $balance = $payments->getTenantBalance($tenant);
            if ($balance === 0) {
                $good++;
            } elseif ($balance > 0) {
                $owing++;
            }
        }

        $this->assertSame(4, $good, 'Expected exactly 4 tenants in good standing (balance 0).');
        $this->assertSame(1, $owing, 'Expected exactly 1 tenant owing money.');

        // No late fees are ever invented by the seeder.
        $this->assertSame(0, LedgerEntry::where('type', LedgerType::LATE_FEE->value)->count());

        // The owing tenant owes exactly one month's rent, via one overdue entry.
        $owingTenant = User::where('email', SeedCatalog::email('tenant.owing'))->first();
        $this->assertNotNull($owingTenant);

        $owingUnit = collect(SeedCatalog::leasedUnits())->firstWhere('standing', 'owing');
        $expected = (int) round($owingUnit['rent'] * 100);
        $this->assertSame($expected, $payments->getTenantBalance($owingTenant));

        $this->assertSame(
            1,
            LedgerEntry::byTenant($owingTenant->id)
                ->where('type', LedgerType::RENT->value)
                ->where('status', LedgerStatus::OVERDUE->value)->count(),
        );
    }
}
