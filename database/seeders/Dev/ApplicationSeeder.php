<?php

namespace Database\Seeders\Dev;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ApplicationRequest;
use App\Models\Listing;
use App\Models\User;

/**
 * ApplicationSeeder — rental applications, all tied to real listings and tenants.
 *
 * Two truthful layers (no filler):
 *   1. Approved histories — every leased unit gets the prior APPROVED application
 *      from the tenant who now lives there, so the "applied → approved → signed"
 *      story is real for each active contract.
 *   2. Live pipeline — a couple of CURRENT applications from existing tenants onto
 *      other available units (realistic in-platform upsizing), so the landlord
 *      "Applicants" screen has a genuine, decidable item. These only target active
 *      listings owned by full-feature landlords (who actually have the
 *      applications feature) — never the limited-feature landlord's listings.
 */
class ApplicationSeeder extends DevSeeder
{
    /**
     * Live applications: [tenant key, property key, unit number, status].
     * The targets are available units owned by verified, full-feature landlords.
     */
    private const LIVE = [
        ['tenant.good2', 'ridge-court', '1B-07', ApplicationStatus::IN_REVIEW],
        ['tenant.good4', 'garden-villas', 'DX-B', ApplicationStatus::SUBMITTED],
        ['tenant.good1', 'ridge-court', '1B-07', ApplicationStatus::NEEDS_ACTION],
        ['tenant.good3', 'garden-villas', 'DX-B', ApplicationStatus::DRAFT],
    ];

    public function run(): void
    {
        $count = $this->seedApprovedHistories();
        $count += $this->seedLivePipeline();

        $this->command?->info("  ✓ Applications: {$count} ({$this->summary()}).");
    }

    /** Approved application behind every lease (active AND former), from its tenant. */
    protected function seedApprovedHistories(): int
    {
        $count = 0;

        foreach (SeedCatalog::contractedUnits() as $u) {
            $unit = $this->unitFromCatalog($u);
            $tenant = $this->user($u['tenant']);
            if (! $unit || ! $tenant || ! ($listing = $this->listingForUnit($unit))) {
                continue;
            }

            $this->upsert($tenant->id, $listing, [
                'status' => ApplicationStatus::APPROVED->value,
                'cover_note' => 'I would love to make this my home and can move in promptly.',
                'submitted_at' => now()->subMonthsNoOverflow((int) $u['months'])->subDays(10),
                'reviewed_at' => now()->subMonthsNoOverflow((int) $u['months'])->subDays(8),
                'decided_at' => now()->subMonthsNoOverflow((int) $u['months'])->subDays(7),
                'decision_reason' => 'Strong application. References and income verified.',
            ]);
            $count++;
        }

        return $count;
    }

    /** A small set of live applications onto available, full-feature listings. */
    protected function seedLivePipeline(): int
    {
        $count = 0;

        foreach (self::LIVE as [$tenantKey, $propertyKey, $unitNumber, $status]) {
            $tenant = $this->user($tenantKey);
            $property = $this->property($propertyKey);
            if (! $tenant || ! $property) {
                continue;
            }

            $unit = $property->units()->where('unit_number', $unitNumber)->first();
            $listing = $unit ? $this->listingForUnit($unit) : null;
            if (! $listing) {
                continue;
            }

            $this->upsert($tenant->id, $listing, $this->fieldsForStatus($status));
            $this->seedTimeline($tenant, $listing, $status);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    protected function fieldsForStatus(ApplicationStatus $status): array
    {
        $base = [
            'status' => $status->value,
            'cover_note' => 'Currently renting with Wyncrest and interested in moving to this unit.',
            'submitted_at' => now()->subDays(3),
        ];

        return match ($status) {
            ApplicationStatus::IN_REVIEW => array_merge($base, [
                'reviewed_at' => now()->subDay(),
                'landlord_notes' => 'Reviewing references. Internal note (never shown to tenant).',
            ]),
            ApplicationStatus::NEEDS_ACTION => array_merge($base, [
                'reviewed_at' => now()->subDays(2),
            ]),
            ApplicationStatus::DRAFT => [
                'status' => ApplicationStatus::DRAFT->value,
                'submitted_at' => null,
                'form_data' => [
                    'personal' => ['first' => 'Ama', 'last' => 'Owusu'],
                    'rental' => ['curType' => 'Apartment', 'moveIn' => '1 Sep 2026'],
                ],
            ],
            default => $base,
        };
    }

    /**
     * Give live applications a truthful, tenant-visible timeline (and, for a
     * NEEDS_ACTION application, an open landlord request the demo tenant can
     * resolve). Idempotent so re-seeding never stacks duplicates.
     */
    protected function seedTimeline(User $tenant, Listing $listing, ApplicationStatus $status): void
    {
        $application = Application::where('tenant_id', $tenant->id)
            ->where('listing_id', $listing->id)
            ->first();
        if (! $application) {
            return;
        }

        $landlord = $listing->landlord;

        $this->event($application, 'started', 'Application started', $tenant, now()->subDays(4));

        if ($status === ApplicationStatus::DRAFT) {
            return; // A draft has only been started.
        }

        $this->event($application, 'submitted', 'Application submitted to landlord', $tenant, now()->subDays(3));

        if (in_array($status, [ApplicationStatus::IN_REVIEW, ApplicationStatus::NEEDS_ACTION], true)) {
            $this->event($application, 'opened', 'Landlord opened the application', $landlord, now()->subDays(2));
        }

        if ($status === ApplicationStatus::NEEDS_ACTION && $landlord) {
            ApplicationRequest::firstOrCreate(
                ['application_id' => $application->id, 'type' => 'document_replacement'],
                [
                    'requested_by_type' => $landlord->getMorphClass(),
                    'requested_by_id' => $landlord->id,
                    'requester_role' => 'landlord',
                    'document_type' => 'proof_of_income',
                    'message' => 'Please upload a clearer proof of income.',
                    'reason' => 'The uploaded document was too blurry to read.',
                    'due_at' => now()->addDays(5),
                ],
            );

            $this->event(
                $application,
                'info_requested',
                'The landlord requested more information',
                $landlord,
                now()->subDay(),
            );
        }
    }

    protected function event(Application $application, string $event, string $description, ?User $actor, $at): void
    {
        ApplicationEvent::firstOrCreate(
            ['application_id' => $application->id, 'event' => $event],
            [
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->id,
                'description' => $description,
                'created_at' => $at,
            ],
        );
    }

    protected function upsert(int $tenantId, Listing $listing, array $attributes): void
    {
        Application::updateOrCreate(
            ['tenant_id' => $tenantId, 'listing_id' => $listing->id],
            array_merge(['landlord_id' => $listing->landlord_id], $attributes),
        );
    }

    protected function summary(): string
    {
        $histories = count(SeedCatalog::contractedUnits());

        return "{$histories} approved histories, ".count(self::LIVE).' live applicants';
    }
}
