<?php

namespace Database\Seeders\Dev;

use App\Enums\ContractStatus;
use App\Enums\ReviewStatus;
use App\Models\Contract;
use App\Models\Review;

/**
 * ReviewSeeder — property reviews with governed eligibility.
 *
 * Respects the platform rule: a reviewer must hold an active/terminated/expired
 * contract on the property, and there is at most one review per contract (unique
 * constraint). Produces a spread of statuses so the admin moderation queue
 * (pending), the public surface (approved-only), and landlord responses are all
 * exercised. Pending/rejected reviews must NEVER affect public averages — that
 * is enforced by the app's approved-only aggregation, which this data lets you
 * verify.
 */
class ReviewSeeder extends DevSeeder
{
    /** Rating + status + optional landlord response, cycled across contracts. */
    private const TEMPLATES = [
        ['rating' => 5, 'status' => ReviewStatus::APPROVED, 'title' => 'Excellent landlord and home', 'body' => 'Responsive landlord, spotless unit, and a quiet neighbourhood. Highly recommend.', 'response' => 'Thank you, it was a pleasure having you as a tenant!'],
        ['rating' => 4, 'status' => ReviewStatus::APPROVED, 'title' => 'Great place, minor delays', 'body' => 'Lovely apartment. Maintenance was good though occasionally a little slow.', 'response' => null],
        ['rating' => 5, 'status' => ReviewStatus::APPROVED, 'title' => 'Felt like home', 'body' => 'Secure, well-managed and great value for the location.', 'response' => 'Much appreciated, thank you for the kind words.'],
        ['rating' => 2, 'status' => ReviewStatus::PENDING, 'title' => 'Awaiting moderation', 'body' => 'Had some issues with the water supply during my stay.', 'response' => null],
        ['rating' => 1, 'status' => ReviewStatus::REJECTED, 'title' => 'Removed by moderation', 'body' => 'This review contained unverifiable personal accusations.', 'response' => null],
        ['rating' => 4, 'status' => ReviewStatus::APPROVED, 'title' => 'Comfortable and well located', 'body' => 'Good amenities and easy access to town. Would rent again.', 'response' => null],
    ];

    public function run(): void
    {
        $adminId = $this->superAdmin()?->id;

        // Eligible contracts: a tenant may review an active/terminated/expired lease
        // — so both current tenants and the two former tenants can leave a review.
        $contracts = Contract::whereIn('status', [
            ContractStatus::ACTIVE->value,
            ContractStatus::TERMINATED->value,
            ContractStatus::EXPIRED->value,
        ])->orderBy('created_at')->get();

        $count = 0;
        foreach ($contracts as $i => $contract) {
            $unit = $contract->listing?->unit;
            if (! $unit) {
                continue;
            }

            $t = self::TEMPLATES[$i % count(self::TEMPLATES)];
            $isModerated = in_array($t['status'], [ReviewStatus::APPROVED, ReviewStatus::REJECTED], true);

            Review::updateOrCreate(
                ['contract_id' => $contract->id], // one review per contract (unique)
                [
                    'reviewer_user_id' => $contract->tenant_id,
                    'property_id' => $unit->property_id,
                    'unit_id' => $unit->id,
                    'landlord_id' => $contract->landlord_id,
                    'rating' => $t['rating'],
                    'title' => $t['title'],
                    'body' => $t['body'],
                    'status' => $t['status']->value,
                    'moderation_reason' => $t['status'] === ReviewStatus::REJECTED
                        ? 'Violated community guidelines.'
                        : null,
                    'moderated_by_admin_id' => $isModerated ? $adminId : null,
                    'landlord_response' => $t['status'] === ReviewStatus::APPROVED ? $t['response'] : null,
                    'responded_at' => ($t['status'] === ReviewStatus::APPROVED && $t['response']) ? now()->subDays(2) : null,
                ],
            );
            $count++;
        }

        $this->command?->info("  ✓ Reviews: {$count} (approved/pending/rejected + landlord responses).");
    }
}
