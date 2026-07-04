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
use App\Models\Conversation;
use App\Models\Feature;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Message;
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
 * (4 admins — 1 super, 2 scoped, 1 pending invite — / 7 catalog landlords /
 * 9 catalog tenants, plus the verification-queue standalone accounts), the
 * lifecycle statuses that SHOULD exist (active/terminated/expired contracts,
 * read/unread messages) are present, and — most importantly — the immutable
 * ledger is mathematically consistent and matches the intended tenant standing:
 *   - good-standing tenants have balance == 0 (incl. two settled former leases)
 *   - one tenant owes EXACTLY one clean month of rent
 *   - one tenant owes one month + a real 10% late fee (via the real service)
 *
 * Exits non-zero if any check fails, so it doubles as a CI smoke check.
 */
class SeedVerifyCommand extends Command
{
    protected $signature = 'wyncrest:seed:verify';

    protected $description = 'Verify the Wyncrest development seed world and ledger consistency';

    /**
     * Standalone accounts VerificationSeeder adds on top of the catalog to keep the
     * admin verification review queue non-empty (see VerificationSeeder::seedQueueDemoCases).
     */
    private const QUEUE_LANDLORDS = 1; // verify.landlord.pending

    private const QUEUE_TENANTS = 4;   // verify.tenant.{pending,needsinfo,nodocs,resubmitted}

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
        // 4 admins: 1 super (operator), 2 scoped (content + finance), 1 pending invite.
        $this->assert('Admins', 4, Admin::count());
        $this->assert('Super admins', 1, Admin::where('is_super_admin', true)->count());
        $this->assert('Scoped admins', 3, Admin::where('is_super_admin', false)->count());
        $this->assert('Pending admin invites', 1, Admin::whereNotNull('invited_at')->whereNull('invite_accepted_at')->count());

        // Catalog accounts + the standalone review-queue accounts VerificationSeeder
        // adds (1 landlord + 4 tenants) to keep the admin verification queue non-empty.
        $expectedLandlords = count(SeedCatalog::LANDLORDS) + self::QUEUE_LANDLORDS;
        $expectedTenants = count(SeedCatalog::TENANTS) + self::QUEUE_TENANTS;
        $this->assert('Landlords', $expectedLandlords, User::where('user_type', UserType::LANDLORD->value)->count());
        $this->assert('Tenants', $expectedTenants, User::where('user_type', UserType::TENANT->value)->count());
        $this->assert('Properties', count(SeedCatalog::PROPERTIES), Property::count());
        $this->assert('Units', count(SeedCatalog::UNITS), Unit::count());
        $this->assert('Listings', count(SeedCatalog::UNITS), Listing::count());
        $this->assert('Features', count(SeedCatalog::FEATURES), Feature::count());

        // Contracts span the full lifecycle: one per contracted unit, of which the
        // active ones equal the live lease graph, plus one terminated + one expired.
        $this->assert('Contracts (all lifecycle)', count(SeedCatalog::contractedUnits()), Contract::count());
        $this->assert('Active contracts', count(SeedCatalog::leasedUnits()), Contract::where('status', ContractStatus::ACTIVE->value)->count());
        $this->assert('Terminated contracts', 1, Contract::where('status', ContractStatus::TERMINATED->value)->count());
        $this->assert('Expired contracts', 1, Contract::where('status', ContractStatus::EXPIRED->value)->count());

        $this->assert('Applications (>0)', '>0', Application::count(), Application::count() > 0);
        $this->assert('Ledger entries (>0)', '>0', LedgerEntry::count(), LedgerEntry::count() > 0);
        $this->assert('Notifications (>0)', '>0', Notification::count(), Notification::count() > 0);
        $this->assert('Verification requests (>0)', '>0', VerificationRequest::count(), VerificationRequest::count() > 0);
        $this->assert('Conversations (>0)', '>0', Conversation::count(), Conversation::count() > 0);
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

        // Messaging exists with both read and unread messages (unread is derived
        // from is_read, so an unread message is what makes an unread badge truthful).
        $this->assert('Messages seeded', '>0', Message::count(), Message::exists());
        $this->assert('Unread messages', '>0', Message::where('is_read', false)->count(), Message::where('is_read', false)->exists());
        $this->assert('Read messages', '>0', Message::where('is_read', true)->count(), Message::where('is_read', true)->exists());
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

        // 3. Exactly one late fee exists (the late-fee tenant), raised via the real
        //    service and linked to an overdue rent entry — never hand-inserted.
        $this->assert('Late fee entries', 1, LedgerEntry::where('type', LedgerType::LATE_FEE->value)->count());
        $lateFeesLinkedToOverdue = LedgerEntry::where('type', LedgerType::LATE_FEE->value)
            ->whereHas('relatedRentEntry', fn ($q) => $q->where('status', LedgerStatus::OVERDUE->value))
            ->count();
        $this->assert('Late fee linked to overdue rent', 1, $lateFeesLinkedToOverdue);

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

        // Good-standing (balance 0): 4 catalog good tenants + 2 former tenants
        // (settled) + 1 unverified (no ledger) + 4 verification-queue tenants
        // (no ledger). Owing (balance > 0): tenant.owing + tenant.latefee.
        $this->assert('Good-standing tenants (balance 0)', 7 + self::QUEUE_TENANTS, $good);
        $this->assert('Owing tenants', 2, $owing);

        // The owing tenant owes EXACTLY one clean month of rent, via one overdue entry.
        $owingTenant = User::where('email', SeedCatalog::email('tenant.owing'))->first();
        if ($owingTenant) {
            $this->assert('Owing balance == one month', $this->rentCentsFor('owing'), $payments->getTenantBalance($owingTenant));

            $overdue = LedgerEntry::byTenant($owingTenant->id)
                ->where('type', LedgerType::RENT->value)
                ->where('status', LedgerStatus::OVERDUE->value)->count();
            $this->assert('Owing tenant has 1 overdue month', 1, $overdue);
        }

        // The late-fee tenant owes one overdue month + a 10% late fee.
        $lateFeeTenant = User::where('email', SeedCatalog::email('tenant.latefee'))->first();
        if ($lateFeeTenant) {
            $rentCents = $this->rentCentsFor('latefee');
            $expected = $rentCents + (int) round($rentCents * 0.10);
            $this->assert('Late-fee balance == rent + fee', $expected, $payments->getTenantBalance($lateFeeTenant));

            $lateFees = LedgerEntry::byTenant($lateFeeTenant->id)
                ->where('type', LedgerType::LATE_FEE->value)->count();
            $this->assert('Late-fee tenant has 1 late fee', 1, $lateFees);
        }
    }

    /** Monthly rent in cents for the leased unit with the given standing. */
    protected function rentCentsFor(string $standing): int
    {
        foreach (SeedCatalog::leasedUnits() as $u) {
            if (($u['standing'] ?? null) === $standing) {
                return (int) round($u['rent'] * 100);
            }
        }

        return 0;
    }
}
