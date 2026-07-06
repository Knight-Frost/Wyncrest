<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Enums\UnitAvailabilityStatus;
use App\Models\Contract;
use App\Models\Document;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LandlordPropertyDetailTest
 *
 * GET /landlord/properties/{property}/detail assembles the full Property page
 * payload (summary, attention, units, listings, contracts, ledger, maintenance,
 * documents, photos, activity) from real data, owner-scoped.
 */
class LandlordPropertyDetailTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
    }

    public function test_detail_returns_full_payload_for_owned_property(): void
    {
        $property = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
            'parking' => '1 covered space per unit',
            'pet_policy' => 'Cats and small dogs allowed',
            'smoking_policy' => 'No smoking indoors',
        ]);

        // One occupied unit with an active listing + contract + tenant.
        $occupiedUnit = Unit::factory()->create([
            'property_id' => $property->id,
            'unit_number' => '2B',
            'availability_status' => UnitAvailabilityStatus::OCCUPIED,
        ]);
        $activeListing = Listing::factory()->active()->create([
            'unit_id' => $occupiedUnit->id,
            'landlord_id' => $this->landlord->id,
        ]);
        $tenant = User::factory()->tenant()->create();
        $contract = Contract::factory()->active()->create([
            'landlord_id' => $this->landlord->id,
            'listing_id' => $activeListing->id,
            'tenant_id' => $tenant->id,
        ]);

        // A rent charge (unpaid) so the contract carries a balance.
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $tenant->id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PENDING,
            'amount_cents' => 250000,
            'due_date' => now()->startOfMonth()->addDays(2),
        ]);

        // A vacant unit with NO listing → drives an attention warning.
        Unit::factory()->create([
            'property_id' => $property->id,
            'unit_number' => '3A',
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);

        // A maintenance request against the property.
        MaintenanceRequest::factory()->create([
            'landlord_id' => $this->landlord->id,
            'property_id' => $property->id,
            'unit_id' => $occupiedUnit->id,
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'title' => 'Kitchen sink leaking',
        ]);

        // A document attached to the property.
        Document::factory()->create([
            'owner_user_id' => $this->landlord->id,
            'uploaded_by_id' => $this->landlord->id,
            'related_type' => Property::class,
            'related_id' => $property->id,
            'original_filename' => 'Title Deed.pdf',
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson("/api/landlord/properties/{$property->id}/detail");

        $response->assertStatus(200)
            ->assertJsonPath('property.id', $property->id)
            ->assertJsonPath('property.parking', '1 covered space per unit')
            ->assertJsonPath('property.pet_policy', 'Cats and small dogs allowed')
            ->assertJsonPath('summary.units_total', 2)
            ->assertJsonPath('summary.occupied', 1)
            ->assertJsonPath('summary.vacant', 1)
            ->assertJsonPath('summary.listed', 1);

        // Units carry their derived listing status + current tenant.
        $units = collect($response->json('units'));
        $occupied = $units->firstWhere('unit_number', '2B');
        $this->assertSame('active', $occupied['listing_status']);
        $this->assertSame($tenant->full_name, $occupied['tenant_name']);

        // Contract row exposes a computed balance.
        $this->assertSame(250000, $response->json('contracts.0.balance_cents'));

        // Ledger, maintenance, documents are present + property-scoped.
        $this->assertCount(1, $response->json('ledger'));
        $this->assertSame('Kitchen sink leaking', $response->json('maintenance.0.title'));
        $this->assertSame('Title Deed.pdf', $response->json('documents.0.original_filename'));

        // The vacant, unlisted unit produced a warning.
        $messages = collect($response->json('attention'))->pluck('message')->implode(' ');
        $this->assertStringContainsString('no listing yet', $messages);
    }

    public function test_rejected_listing_produces_red_attention_warning(): void
    {
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create([
            'property_id' => $property->id,
            'unit_number' => '1A',
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);
        Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::REJECTED,
            'rejection_reason' => 'Cover photo too dark.',
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson("/api/landlord/properties/{$property->id}/detail");

        $response->assertStatus(200);
        $red = collect($response->json('attention'))->firstWhere('level', 'red');
        $this->assertNotNull($red);
        $this->assertStringContainsString('rejected', $red['message']);
    }

    public function test_detail_is_scoped_to_owner(): void
    {
        $other = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $other->id]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $this->getJson("/api/landlord/properties/{$property->id}/detail")
            ->assertStatus(403);
    }

    public function test_tenant_cannot_access_property_detail(): void
    {
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $tenant = User::factory()->tenant()->create();

        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson("/api/landlord/properties/{$property->id}/detail")
            ->assertStatus(403);
    }
}
