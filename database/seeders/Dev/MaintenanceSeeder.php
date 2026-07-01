<?php

namespace Database\Seeders\Dev;

use App\Enums\ContractStatus;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Models\Contract;
use App\Models\MaintenanceRequest;

/**
 * MaintenanceSeeder — one realistic maintenance request per active lease.
 *
 * Attaches a single request to each active lease, cycling through the issue bank
 * so the tenant and landlord maintenance screens show a spread of statuses
 * (open / acknowledged / in-progress / resolved / closed). All foreign keys are
 * resolved from the real contract graph (tenant/landlord/property/unit) — no
 * orphaned rows, nothing invented.
 */
class MaintenanceSeeder extends DevSeeder
{
    /** A small bank of realistic issues, cycled deterministically. */
    private const ISSUES = [
        ['title' => 'Kitchen tap is leaking', 'cat' => MaintenanceCategory::PLUMBING, 'pri' => MaintenancePriority::MEDIUM, 'status' => MaintenanceStatus::OPEN],
        ['title' => 'Bedroom socket has no power', 'cat' => MaintenanceCategory::ELECTRICAL, 'pri' => MaintenancePriority::HIGH, 'status' => MaintenanceStatus::ACKNOWLEDGED],
        ['title' => 'Air conditioner not cooling', 'cat' => MaintenanceCategory::HVAC, 'pri' => MaintenancePriority::MEDIUM, 'status' => MaintenanceStatus::IN_PROGRESS],
        ['title' => 'Fridge stopped working', 'cat' => MaintenanceCategory::APPLIANCE, 'pri' => MaintenancePriority::HIGH, 'status' => MaintenanceStatus::RESOLVED],
        ['title' => 'Crack in living room ceiling', 'cat' => MaintenanceCategory::STRUCTURAL, 'pri' => MaintenancePriority::URGENT, 'status' => MaintenanceStatus::CLOSED],
        ['title' => 'Replace blown corridor bulb', 'cat' => MaintenanceCategory::GENERAL, 'pri' => MaintenancePriority::LOW, 'status' => MaintenanceStatus::CANCELLED],
    ];

    public function run(): void
    {
        $contracts = Contract::where('status', ContractStatus::ACTIVE->value)
            ->orderBy('created_at')->get();

        $count = 0;
        foreach ($contracts as $i => $contract) {
            $unit = $contract->listing?->unit;
            if (! $unit) {
                continue;
            }

            // One request per active lease, cycling through the issue bank/statuses.
            $issue = self::ISSUES[$i % count(self::ISSUES)];
            $this->createRequest($contract, $unit->property_id, $unit->id, $issue);
            $count++;
        }

        $this->command?->info("  ✓ Maintenance: {$count} requests across open/acknowledged/in-progress/resolved/closed.");
    }

    protected function createRequest(Contract $contract, int $propertyId, int $unitId, array $issue): void
    {
        /** @var MaintenanceStatus $status */
        $status = $issue['status'];
        $submittedAt = now()->subDays(rand(2, 20));

        MaintenanceRequest::create([
            'tenant_id' => $contract->tenant_id,
            'contract_id' => $contract->id,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'landlord_id' => $contract->landlord_id,
            'title' => $issue['title'],
            'description' => $issue['title'].'. Please attend to this at your earliest convenience.',
            'category' => $issue['cat']->value,
            'priority' => $issue['pri']->value,
            'status' => $status->value,
            'resolution_notes' => $status->isFinal() && $status !== MaintenanceStatus::CANCELLED
                ? 'Technician attended and resolved the issue.'
                : null,
            'submitted_at' => $submittedAt,
            'acknowledged_at' => $status === MaintenanceStatus::OPEN ? null : $submittedAt->copy()->addHours(6),
            'resolved_at' => $status === MaintenanceStatus::RESOLVED ? $submittedAt->copy()->addDays(2) : null,
            'closed_at' => in_array($status, [MaintenanceStatus::CLOSED, MaintenanceStatus::CANCELLED], true)
                ? $submittedAt->copy()->addDays(3)
                : null,
        ]);
    }
}
