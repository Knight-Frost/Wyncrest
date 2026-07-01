<?php

namespace Database\Seeders\Dev;

use App\Enums\VerificationStatus;
use App\Models\User;
use App\Models\VerificationRequest;

/**
 * VerificationSeeder — identity verification requests.
 *
 * For every demo user whose verification status is not "unverified" we create a
 * matching verification_request so the admin review queue (pending / needs-info)
 * and the verified/rejected history are both populated and consistent with the
 * user's verification_status set in UserSeeder.
 */
class VerificationSeeder extends DevSeeder
{
    public function run(): void
    {
        $adminId = $this->superAdmin()?->id;
        $created = 0;

        foreach (array_merge(SeedCatalog::LANDLORDS, SeedCatalog::TENANTS) as $person) {
            $status = $person['verification'];
            if ($status === 'unverified') {
                continue; // never submitted
            }

            $user = $this->user($person['key']);
            if (! $user) {
                continue;
            }

            $this->createRequest($user, $status, $adminId);
            $created++;
        }

        $this->command?->info("  ✓ Verification: {$created} approved verification requests (every demo account is verified).");
    }

    protected function createRequest(User $user, string $status, ?int $adminId): void
    {
        $submittedAt = now()->subDays(18);

        $attributes = [
            'user_id' => $user->id,
            'note' => 'Submitted Ghana Card and proof of address for review (demo).',
            'submitted_at' => $submittedAt,
        ];

        // Reviewed outcomes carry a reviewer + timestamp + reason; queued ones don't.
        match ($status) {
            'verified' => $attributes = array_merge($attributes, [
                'status' => VerificationStatus::VERIFIED->value,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => $submittedAt->copy()->addDays(2),
                'decision_reason' => 'Identity confirmed against submitted Ghana Card.',
            ]),
            'rejected' => $attributes = array_merge($attributes, [
                'status' => VerificationStatus::REJECTED->value,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => $submittedAt->copy()->addDays(2),
                'decision_reason' => 'Submitted document was expired. Please re-submit a valid ID.',
            ]),
            'needs_more_information' => $attributes = array_merge($attributes, [
                'status' => VerificationStatus::NEEDS_MORE_INFORMATION->value,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => $submittedAt->copy()->addDays(1),
                'decision_reason' => 'Proof of address is unreadable. Please upload a clearer copy.',
            ]),
            default => $attributes['status'] = VerificationStatus::PENDING->value, // awaiting review
        };

        VerificationRequest::create($attributes);
    }
}
