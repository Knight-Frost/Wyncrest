<?php

namespace Database\Seeders\Dev;

use App\Enums\AccountStatus;
use App\Enums\AdminCapability;
use App\Enums\UserType;
use App\Enums\VerificationStatus;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * UserSeeder — demo identities.
 *
 * Creates exactly:
 *   - 4 admins (separate `admins` table): 1 super admin + 2 scoped admins (a
 *     content reviewer + a finance admin, both active logins) + 1 pending invite
 *     (created but never accepted, cannot log in)
 *   - 7 landlords (4 operating + 1 empty-state + 1 pending-verification + 1
 *     suspended). Verification/account state comes from the catalog.
 *   - 9 tenants  (4 good standing + 1 owing + 1 owing-with-late-fee + 2 former
 *     tenants + 1 unverified). Standing/verification state comes from the catalog.
 *
 * Every account uses an @{test-domain} email and the shared demo password. Names
 * are fictional. Phone numbers use Ghana MTN-style prefixes; cities are real
 * Ghanaian locations to make the dashboard greeting/location truthful.
 */
class UserSeeder extends DevSeeder
{
    public function run(): void
    {
        $password = Hash::make($this->demoPassword());

        $this->seedAdmin($password);
        $this->seedRole(SeedCatalog::LANDLORDS, UserType::LANDLORD, $password);
        $this->seedRole(SeedCatalog::TENANTS, UserType::TENANT, $password);

        $this->command?->info(
            '  ✓ Users: 4 admins (1 super, 2 scoped [content + finance], 1 pending invite), '
            .count(SeedCatalog::LANDLORDS).' landlords, '
            .count(SeedCatalog::TENANTS).' tenants.'
        );
    }

    /**
     * The admin team for the development world:
     *   - 1 super admin (system operator — full authority)
     *   - 2 scoped regular admins (a content reviewer + a finance admin) so the
     *     access page and capability enforcement are visible/demonstrable from
     *     both sides of the boundary
     *   - 1 pending invite (never accepted) so the invite lifecycle is visible
     */
    protected function seedAdmin(string $password): void
    {
        Admin::updateOrCreate(
            ['email' => 'admin@'.$this->domain()],
            ['name' => 'Wyncrest Admin', 'password' => $password, 'is_super_admin' => true, 'is_active' => true],
        );

        // Scoped CONTENT admin — can moderate content & read the audit log, but
        // cannot manage the team, touch finance, or the ledger (enforced server-side).
        Admin::updateOrCreate(
            ['email' => 'reviewer@'.$this->domain()],
            [
                'name' => 'Efua Reviewer',
                'password' => $password,
                'is_super_admin' => false,
                'is_active' => true,
                'capabilities' => [
                    AdminCapability::REVIEW_VERIFICATIONS->value,
                    AdminCapability::MODERATE_LISTINGS->value,
                    AdminCapability::MODERATE_REVIEWS->value,
                    AdminCapability::VIEW_AUDIT->value,
                ],
                'invited_at' => now()->subDays(14),
                'invite_accepted_at' => now()->subDays(13),
            ],
        );

        // Scoped FINANCE admin — the mirror image of the content reviewer: can
        // manage contracts/ledger and read analytics, but CANNOT moderate listings,
        // reviews, verifications or manage the team. Together the two scoped admins
        // exercise both sides of the capability boundary (each is denied what the
        // other is allowed), so 403s are demonstrable in both directions.
        Admin::updateOrCreate(
            ['email' => 'finance@'.$this->domain()],
            [
                'name' => 'Kwabena Finance',
                'password' => $password,
                'is_super_admin' => false,
                'is_active' => true,
                'capabilities' => [
                    AdminCapability::MANAGE_CONTRACTS->value,
                    AdminCapability::MANAGE_LEDGER->value,
                    AdminCapability::VIEW_ANALYTICS->value,
                    AdminCapability::VIEW_AUDIT->value,
                ],
                'invited_at' => now()->subDays(12),
                'invite_accepted_at' => now()->subDays(11),
            ],
        );

        // Pending invite — created but never accepted (no usable password).
        Admin::updateOrCreate(
            ['email' => 'pending.admin@'.$this->domain()],
            [
                'name' => 'Kojo Pending',
                'password' => Hash::make(Str::password(40)),
                'is_super_admin' => false,
                'is_active' => true,
                'capabilities' => [AdminCapability::MANAGE_USERS->value],
                'invited_at' => now()->subDays(1),
                'invite_accepted_at' => null,
            ],
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $people
     */
    protected function seedRole(array $people, UserType $type, string $password): void
    {
        foreach ($people as $i => $person) {
            $verified = $person['verification'] === 'verified';

            User::updateOrCreate(
                ['email' => SeedCatalog::email($person['key'])],
                [
                    'user_type' => $type,
                    'password' => $password,
                    'first_name' => $person['first'],
                    'last_name' => $person['last'],
                    'phone' => $this->phone($type, $i),
                    'city' => $person['city'],
                    'email_verified_at' => now()->subDays(30 - ($i % 20)),
                    'identity_verified' => $verified,
                    'identity_verified_at' => $verified ? now()->subDays(20) : null,
                    'identity_verified_by' => $verified ? 'admin@'.$this->domain() : null,
                    'verification_status' => $this->verificationStatus($person['verification']),
                    'account_status' => $this->accountStatus($person['account']),
                    'is_active' => $person['account'] === 'active',
                    'suspended_at' => $person['account'] === 'suspended' ? now()->subDays(3) : null,
                    'suspension_reason' => $person['account'] === 'suspended'
                        ? 'Suspended pending document re-verification (demo).'
                        : null,
                ],
            );
        }
    }

    protected function phone(UserType $type, int $i): string
    {
        // Ghana mobile format: 024 (MTN) for tenants, 054 for landlords, then a
        // deterministic 7-digit body so numbers are stable and non-colliding.
        $prefix = $type === UserType::LANDLORD ? '054' : '024';

        return $prefix.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT);
    }

    protected function verificationStatus(string $value): string
    {
        return match ($value) {
            'verified' => VerificationStatus::VERIFIED->value,
            'pending' => VerificationStatus::PENDING->value,
            'rejected' => VerificationStatus::REJECTED->value,
            'needs_more_information' => VerificationStatus::NEEDS_MORE_INFORMATION->value,
            default => VerificationStatus::UNVERIFIED->value,
        };
    }

    protected function accountStatus(string $value): string
    {
        return match ($value) {
            'suspended' => AccountStatus::SUSPENDED->value,
            'blocked' => AccountStatus::BLOCKED->value,
            'archived' => AccountStatus::ARCHIVED->value,
            default => AccountStatus::ACTIVE->value,
        };
    }
}
