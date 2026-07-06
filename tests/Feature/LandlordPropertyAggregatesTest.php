<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\UnitAvailabilityStatus;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LandlordPropertyAggregatesTest
 *
 * Feature 2: PropertyController@index adds per-property occupancy and
 * collected-this-month aggregates, scoped to the authenticated landlord.
 */
class LandlordPropertyAggregatesTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
    }

    public function test_index_includes_per_property_aggregates(): void
    {
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);

        // 2 occupied, 1 available, 1 maintenance => total 4
        Unit::factory()->count(2)->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::OCCUPIED,
        ]);
        $availableUnit = Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);
        Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::MAINTENANCE,
        ]);

        // Rent paid this month for THIS property (through contract→listing→unit).
        $listing = Listing::factory()->active()->create([
            'unit_id' => $availableUnit->id,
            'landlord_id' => $this->landlord->id,
        ]);
        $contract = Contract::factory()->active()->create([
            'landlord_id' => $this->landlord->id,
            'listing_id' => $listing->id,
        ]);
        $paidRent = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $contract->tenant_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => 120000,
            'due_date' => now()->startOfMonth()->addDays(2),
        ]);
        // Collected is cash-basis (sum of PAYMENT entries via
        // LedgerComputationEngine), so the matching payment receipt is what
        // actually drives collected_this_month_cents.
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $contract->tenant_id,
            'type' => LedgerType::PAYMENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => -120000,
            'related_rent_entry_id' => $paidRent->id,
            'created_at' => now()->startOfMonth()->addDays(2),
        ]);
        // A pending entry this month must NOT count toward collected.
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $contract->tenant_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PENDING,
            'amount_cents' => 50000,
            'due_date' => now()->startOfMonth()->addDays(4),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/properties');

        $response->assertStatus(200)
            ->assertJsonPath('0.units_count', 4)
            ->assertJsonPath('0.occupied_units', 2)
            ->assertJsonPath('0.vacant_units', 1)
            ->assertJsonPath('0.occupancy_rate', 50)
            ->assertJsonPath('0.collected_this_month_cents', 120000);
    }

    public function test_aggregates_are_scoped_to_authenticated_landlord(): void
    {
        // Another landlord's property + collected rent must never leak.
        $other = User::factory()->landlord()->create();
        $otherProperty = Property::factory()->create(['landlord_id' => $other->id]);
        $otherUnit = Unit::factory()->create([
            'property_id' => $otherProperty->id,
            'availability_status' => UnitAvailabilityStatus::OCCUPIED,
        ]);
        $otherListing = Listing::factory()->active()->create([
            'unit_id' => $otherUnit->id,
            'landlord_id' => $other->id,
        ]);
        $otherContract = Contract::factory()->active()->create([
            'landlord_id' => $other->id,
            'listing_id' => $otherListing->id,
        ]);
        LedgerEntry::factory()->create([
            'contract_id' => $otherContract->id,
            'landlord_id' => $other->id,
            'tenant_id' => $otherContract->tenant_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => 999000,
            'due_date' => now()->startOfMonth()->addDays(2),
        ]);

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/properties');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $response->assertJsonPath('0.id', $property->id)
            ->assertJsonPath('0.occupied_units', 0)
            ->assertJsonPath('0.vacant_units', 1)
            ->assertJsonPath('0.occupancy_rate', 0)
            ->assertJsonPath('0.collected_this_month_cents', 0);
    }

    public function test_tenant_cannot_access_landlord_properties(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/landlord/properties')->assertStatus(403);
    }
}
