<?php

namespace Database\Factories;

use App\Enums\MaintenanceAccess;
use App\Enums\MaintenanceArea;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceContactMethod;
use App\Enums\MaintenanceOnset;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceVisitWindow;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MaintenanceRequest>
 *
 * Builds a complete, coherent object graph:
 *   landlord → property → unit → listing (ACTIVE)
 *   tenant
 *   contract (ACTIVE) tying landlord, tenant, and listing together
 *   maintenance_request derived from the above
 */
class MaintenanceRequestFactory extends Factory
{
    public function definition(): array
    {
        // Build the full graph so all FK columns are consistent.
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);
        $tenant = User::factory()->tenant()->create();
        $contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ]);

        return [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'area' => fake()->randomElement(MaintenanceArea::cases())->value,
            'specific_location' => fake()->optional()->words(3, true),
            'onset' => fake()->randomElement(MaintenanceOnset::cases())->value,
            'safety_flags' => null,
            'access_permission' => MaintenanceAccess::CONTACT_FIRST->value,
            'preferred_visit_window' => MaintenanceVisitWindow::ANY->value,
            'preferred_contact_method' => MaintenanceContactMethod::MESSAGE->value,
            'access_instructions' => null,
            'category' => MaintenanceCategory::GENERAL->value,
            'priority' => MaintenancePriority::MEDIUM->value,
            'status' => MaintenanceStatus::OPEN->value,
            'resolution_notes' => null,
            'submitted_at' => now(),
            'acknowledged_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Request has been picked up by the landlord.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MaintenanceStatus::IN_PROGRESS->value,
            'acknowledged_at' => now()->subHours(2),
        ]);
    }

    /**
     * Request has been resolved by the landlord.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MaintenanceStatus::RESOLVED->value,
            'acknowledged_at' => now()->subDays(3),
            'resolved_at' => now()->subDay(),
            'resolution_notes' => fake()->sentence(),
        ]);
    }

    /**
     * Request was cancelled by the tenant.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MaintenanceStatus::CANCELLED->value,
            'closed_at' => now()->subHours(1),
        ]);
    }
}
