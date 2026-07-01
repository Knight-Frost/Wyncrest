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
 *   - 1 admin (separate `admins` table) — can log in and manage the whole system
 *   - 5 landlords (all verified; 4 operating + 1 deliberate empty-state account)
 *   - 5 tenants  (all verified; 4 in good standing + 1 who owes one month)
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
            '  ✓ Users: 3 admins (1 super, 1 scoped, 1 pending invite), '
            .count(SeedCatalog::LANDLORDS).' landlords, '
            .count(SeedCatalog::TENANTS).' tenants.'
        );
    }

    /**
     * The admin team for the development world:
     *   - 1 super admin (system operator — full authority)
     *   - 1 scoped regular admin (limited capabilities) so the access page and
     *     capability enforcement are visible/demonstrable
     *   - 1 pending invite (never accepted) so the invite lifecycle is visible
     */
    protected function seedAdmin(string $password): void
    {
        Admin::updateOrCreate(
            ['email' => 'admin@'.$this->domain()],
            ['name' => 'Wyncrest Admin', 'password' => $password, 'is_super_admin' => true, 'is_active' => true],
        );

        // Scoped regular admin — can moderate content & read the audit log, but
        // cannot manage the team or touch finance (enforced server-side).
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
