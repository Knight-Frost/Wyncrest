<?php

namespace Tests\Feature\Seeding;

use App\Enums\AccountStatus;
use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Enums\UserType;
use App\Enums\VerificationStatus;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Feature;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Property;
use App\Models\Review;
use App\Models\Unit;
use App\Models\User;
use App\Models\VerificationRequest;
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

        // Exact, controlled counts — 4 admins (1 super, 2 scoped [content +
        // finance], 1 pending invite), 7 catalog landlords + 1 verification-queue
        // demo landlord, 9 catalog tenants + 4 verification-queue demo tenants.
        // The extra accounts (seeded by VerificationSeeder, not SeedCatalog) exist
        // solely to keep the admin verification review queue non-empty in a fresh
        // dev world — see VerificationSeeder::seedQueueDemoCases().
        $this->assertSame(4, Admin::count());
        $this->assertSame(1, Admin::where('is_super_admin', true)->count());
        $this->assertSame(3, Admin::where('is_super_admin', false)->count());
        $this->assertSame(1, Admin::whereNotNull('invited_at')->whereNull('invite_accepted_at')->count());
        $this->assertSame(count(SeedCatalog::LANDLORDS) + 1, User::where('user_type', UserType::LANDLORD->value)->count());
        $this->assertSame(count(SeedCatalog::TENANTS) + 4, User::where('user_type', UserType::TENANT->value)->count());
        $this->assertSame(count(SeedCatalog::PROPERTIES), Property::count());
        $this->assertSame(count(SeedCatalog::UNITS), Unit::count());
        $this->assertSame(count(SeedCatalog::UNITS), Listing::count());

        // Contracts cover the full lifecycle: one per contracted unit; the active
        // ones equal the live lease graph, plus one terminated and one expired.
        $this->assertSame(count(SeedCatalog::contractedUnits()), Contract::count());
        $this->assertSame(count(SeedCatalog::leasedUnits()), Contract::where('status', ContractStatus::ACTIVE->value)->count());
        $this->assertSame(1, Contract::where('status', ContractStatus::TERMINATED->value)->count());
        $this->assertSame(1, Contract::where('status', ContractStatus::EXPIRED->value)->count());

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

    public function test_development_world_seeds_messaging_with_read_and_unread(): void
    {
        $this->seed(DevelopmentSeeder::class);

        // Three real tenant↔landlord threads, each anchored to a listing.
        $this->assertSame(3, Conversation::count());
        $this->assertTrue(Message::where('is_read', true)->exists(), 'Expected read messages.');
        $this->assertTrue(Message::where('is_read', false)->exists(), 'Expected unread messages.');

        // The owing tenant has an UNREAD message FROM their landlord — an unread
        // badge is derived from is_read + sender, never a stored count.
        $tenant = User::where('email', SeedCatalog::email('tenant.owing'))->first();
        $landlord = User::where('email', SeedCatalog::email('landlord.2'))->first();
        $conversation = Conversation::where('participant_one_id', $tenant->id)
            ->where('participant_two_id', $landlord->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame(
            1,
            $conversation->messages()->where('is_read', false)->where('sender_id', $landlord->id)->count(),
        );

        // Two good tenants are deliberately left with empty inboxes (empty state).
        foreach (['tenant.good3', 'tenant.good4'] as $key) {
            $u = User::where('email', SeedCatalog::email($key))->first();
            $count = Conversation::where('participant_one_id', $u->id)
                ->orWhere('participant_two_id', $u->id)->count();
            $this->assertSame(0, $count, "Expected {$key} to have an empty inbox.");
        }
    }

    public function test_development_world_covers_account_and_verification_states(): void
    {
        $this->seed(DevelopmentSeeder::class);

        // Suspended landlord: cannot log in (is_active false), status suspended.
        $suspended = User::where('email', SeedCatalog::email('landlord.suspended'))->first();
        $this->assertNotNull($suspended);
        $this->assertFalse((bool) $suspended->is_active);
        $this->assertSame(AccountStatus::SUSPENDED, $suspended->account_status);

        // Pending-verification landlord: not identity-verified → hard-gated features.
        $pending = User::where('email', SeedCatalog::email('landlord.pending'))->first();
        $this->assertFalse($pending->identity_verified);
        $this->assertSame(VerificationStatus::PENDING, $pending->verification_status);

        // Unverified tenant: never submitted → no verification request at all.
        $unverified = User::where('email', SeedCatalog::email('tenant.unverified'))->first();
        $this->assertFalse($unverified->identity_verified);
        $this->assertSame(0, VerificationRequest::where('user_id', $unverified->id)->count());

        // The admin review queue is genuinely populated across every review state.
        foreach (['pending', 'rejected', 'needs_more_information'] as $status) {
            $this->assertTrue(
                VerificationRequest::where('status', $status)->exists(),
                "Expected a verification request in status {$status}.",
            );
        }
    }

    public function test_development_world_former_leases_are_settled_and_reviewable(): void
    {
        $this->seed(DevelopmentSeeder::class);

        $payments = app(PaymentService::class);

        // Both former leases (terminated + expired) are paid off — zero balance.
        foreach (['tenant.former', 'tenant.expired'] as $key) {
            $tenant = User::where('email', SeedCatalog::email($key))->first();
            $this->assertNotNull($tenant);
            $this->assertSame(0, $payments->getTenantBalance($tenant), "Expected {$key} to owe nothing.");
        }

        // The terminated contract carries its termination metadata (truthful record).
        $terminated = Contract::where('status', ContractStatus::TERMINATED->value)->first();
        $this->assertNotNull($terminated);
        $this->assertNotNull($terminated->terminated_by);
        $this->assertNotEmpty($terminated->termination_reason);

        // A former tenant left a review (platform allows reviews on ended leases).
        $formerContractIds = Contract::whereIn('status', [
            ContractStatus::TERMINATED->value,
            ContractStatus::EXPIRED->value,
        ])->pluck('id');
        $this->assertTrue(
            Review::whereIn('contract_id', $formerContractIds)->exists(),
            'Expected at least one review from a former tenant.',
        );
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
     * Good-standing tenants have balance 0: the 4 catalog good tenants, the 2
     * former tenants (settled leases), the 1 unverified tenant (no ledger), and
     * the 4 verification-queue demo tenants (no ledger) — 11 in all. Two tenants
     * owe money: tenant.owing (one clean month) and tenant.latefee (one month +
     * a real late fee). Exactly one late fee exists, raised via the real service.
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

        $this->assertSame(11, $good, 'Expected exactly 11 tenants in good standing (balance 0).');
        $this->assertSame(2, $owing, 'Expected exactly 2 tenants owing money (owing + late-fee).');

        // Exactly one late fee exists, and it is on an overdue rent entry.
        $this->assertSame(1, LedgerEntry::where('type', LedgerType::LATE_FEE->value)->count());

        // The owing tenant owes exactly one clean month's rent, via one overdue entry.
        $owingTenant = User::where('email', SeedCatalog::email('tenant.owing'))->first();
        $this->assertNotNull($owingTenant);

        $owingUnit = collect(SeedCatalog::leasedUnits())->firstWhere('standing', 'owing');
        $this->assertSame((int) round($owingUnit['rent'] * 100), $payments->getTenantBalance($owingTenant));

        $this->assertSame(
            1,
            LedgerEntry::byTenant($owingTenant->id)
                ->where('type', LedgerType::RENT->value)
                ->where('status', LedgerStatus::OVERDUE->value)->count(),
        );

        // The late-fee tenant owes one overdue month + a 10% late fee.
        $lateFeeTenant = User::where('email', SeedCatalog::email('tenant.latefee'))->first();
        $this->assertNotNull($lateFeeTenant);

        $lateFeeUnit = collect(SeedCatalog::leasedUnits())->firstWhere('standing', 'latefee');
        $rentCents = (int) round($lateFeeUnit['rent'] * 100);
        $this->assertSame(
            $rentCents + (int) round($rentCents * 0.10),
            $payments->getTenantBalance($lateFeeTenant),
        );
        $this->assertSame(
            1,
            LedgerEntry::byTenant($lateFeeTenant->id)
                ->where('type', LedgerType::LATE_FEE->value)->count(),
        );
    }
}
