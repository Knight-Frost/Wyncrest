<?php

namespace App\Console\Commands;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Enums\UserType;
use App\Models\Admin;
use App\Models\Application;
use App\Models\Contract;
use App\Models\Feature;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\PaymentService;
use Database\Seeders\Dev\SeedCatalog;
use Illuminate\Console\Command;

/**
 * wyncrest:seed:verify
 *
 * Verifies the controlled development world: the documented counts are exact
 * (3 admins — 1 super, 1 scoped, 1 pending invite — / 5 landlords / 5 tenants),
 * the lifecycle statuses that SHOULD exist
 * are present, and — most importantly — the immutable ledger is mathematically
 * consistent and matches the intended tenant standing:
 *   - exactly 4 tenants in good standing (balance == 0)
 *   - exactly 1 tenant owing EXACTLY one month of rent (no invented late fee)
 *
 * Exits non-zero if any check fails, so it doubles as a CI smoke check.
 */
class SeedVerifyCommand extends Command
{
    protected $signature = 'wyncrest:seed:verify';

    protected $description = 'Verify the Wyncrest development seed world and ledger consistency';

    /** @var array<int,array{0:string,1:string|int,2:string|int,3:bool}> */
    protected array $rows = [];

    public function handle(): int
    {
        $this->checkCounts();
        $this->checkStatusCoverage();
        $this->checkLedgerConsistency();
        $this->checkTenantStanding();

        $this->table(['Check', 'Expected', 'Actual', 'OK'], array_map(
            fn ($r) => [$r[0], (string) $r[1], (string) $r[2], $r[3] ? '<info>✓</info>' : '<error>✗</error>'],
            $this->rows,
        ));

        $failed = collect($this->rows)->reject(fn ($r) => $r[3])->count();

        if ($failed > 0) {
            $this->error("Seed verification FAILED: {$failed} check(s) did not pass.");

            return self::FAILURE;
        }

        $this->info('Seed verification passed. The development world is complete and the ledger is consistent.');

        return self::SUCCESS;
    }

    protected function assert(string $label, $expected, $actual, ?bool $ok = null): void
    {
        $this->rows[] = [$label, $expected, $actual, $ok ?? ($expected === $actual)];
    }

    protected function checkCounts(): void
    {
        // 3 admins: 1 super (operator), 1 scoped regular, 1 pending invite.
        $this->assert('Admins', 3, Admin::count());
        $this->assert('Super admins', 1, Admin::where('is_super_admin', true)->count());
        $this->assert('Scoped admins', 2, Admin::where('is_super_admin', false)->count());
        $this->assert('Pending admin invites', 1, Admin::whereNotNull('invited_at')->whereNull('invite_accepted_at')->count());
        $this->assert('Landlords', config('seed.development.landlords', 5), User::where('user_type', UserType::LANDLORD->value)->count());
        $this->assert('Tenants', config('seed.development.tenants', 5), User::where('user_type', UserType::TENANT->value)->count());
        $this->assert('Properties', count(SeedCatalog::PROPERTIES), Property::count());
        $this->assert('Units', count(SeedCatalog::UNITS), Unit::count());
        $this->assert('Listings', count(SeedCatalog::UNITS), Listing::count());
        $this->assert('Features', count(SeedCatalog::FEATURES), Feature::count());

        // Exactly one active lease per occupied unit; all leases are active.
        $leased = count(SeedCatalog::leasedUnits());
        $this->assert('Contracts (all active)', $leased, Contract::count());
        $this->assert('Active contracts', $leased, Contract::where('status', ContractStatus::ACTIVE->value)->count());

        $this->assert('Applications (>0)', '>0', Application::count(), Application::count() > 0);
        $this->assert('Ledger entries (>0)', '>0', LedgerEntry::count(), LedgerEntry::count() > 0);
        $this->assert('Notifications (>0)', '>0', Notification::count(), Notification::count() > 0);
        $this->assert('Verification requests (>0)', '>0', VerificationRequest::count(), VerificationRequest::count() > 0);
    }

    protected function checkStatusCoverage(): void
    {
        // The statuses this world deliberately includes (browse, moderation queue,
        // draft, and the off-market listings on occupied units).
        foreach ([ListingStatus::ACTIVE, ListingStatus::PENDING_REVIEW, ListingStatus::DRAFT, ListingStatus::INACTIVE] as $status) {
            $has = Listing::where('status', $status->value)->exists();
            $this->assert("Listing status: {$status->value}", 'present', $has ? 'present' : 'missing', $has);
        }

        // Notifications exist in both read and unread states (inbox is exercisable).
        $this->assert('Unread notifications', '>0', Notification::whereNull('read_at')->count(), Notification::whereNull('read_at')->exists());
        $this->assert('Read notifications', '>0', Notification::whereNotNull('read_at')->count(), Notification::whereNotNull('read_at')->exists());
    }

    protected function checkLedgerConsistency(): void
    {
        $payments = app(PaymentService::class);

        // 1. Every PAYMENT entry is negative and linked to an obligation.
        $badPayments = LedgerEntry::where('type', LedgerType::PAYMENT->value)
            ->where(fn ($q) => $q->where('amount_cents', '>=', 0)->orWhereNull('related_rent_entry_id'))
            ->count();
        $this->assert('Payments negative & linked', 0, $badPayments);

        // 2. Every obligation (rent/late_fee) is a positive amount.
        $badObligations = LedgerEntry::whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
            ->where('amount_cents', '<=', 0)->count();
        $this->assert('Obligations positive', 0, $badObligations);

        // 3. No late fees are invented by the seeder.
        $this->assert('No invented late fees', 0, LedgerEntry::where('type', LedgerType::LATE_FEE->value)->count());

        // 4. Per-tenant balance is derivable and matches PaymentService.
        $mismatch = 0;
        foreach (User::where('user_type', UserType::TENANT->value)->get() as $tenant) {
            $obligations = LedgerEntry::byTenant($tenant->id)
                ->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])->sum('amount_cents');
            $paid = LedgerEntry::byTenant($tenant->id)->where('type', LedgerType::PAYMENT->value)->sum('amount_cents');
            if (($obligations + $paid) !== $payments->getTenantBalance($tenant)) {
                $mismatch++;
            }
        }
        $this->assert('Tenant balances derivable', 0, $mismatch);

        // 5. A paid rent history exists.
        $anyPaid = LedgerEntry::where('type', LedgerType::RENT->value)
            ->where('status', LedgerStatus::PAID->value)->exists();
        $this->assert('Paid rent history exists', 'yes', $anyPaid ? 'yes' : 'no', $anyPaid);
    }

    protected function checkTenantStanding(): void
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

        $this->assert('Good-standing tenants (balance 0)', 4, $good);
        $this->assert('Owing tenants', 1, $owing);

        // The single owing tenant owes EXACTLY one month of rent, via one overdue entry.
        $owingTenant = User::where('email', SeedCatalog::email('tenant.owing'))->first();
        if ($owingTenant) {
            $balance = $payments->getTenantBalance($owingTenant);
            $expected = $this->expectedOwingCents();
            $this->assert('Owing balance == one month', $expected, $balance);

            $overdue = LedgerEntry::byTenant($owingTenant->id)
                ->where('type', LedgerType::RENT->value)
                ->where('status', LedgerStatus::OVERDUE->value)->count();
            $this->assert('Owing tenant has 1 overdue month', 1, $overdue);
        }
    }

    /** Expected owing balance in cents: the owing unit's monthly rent. */
    protected function expectedOwingCents(): int
    {
        foreach (SeedCatalog::leasedUnits() as $u) {
            if (($u['standing'] ?? null) === 'owing') {
                return (int) round($u['rent'] * 100);
            }
        }

        return 0;
    }
}
