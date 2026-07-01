<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\UnitAvailabilityStatus;
use App\Enums\UserType;
use App\Models\Admin;
use App\Models\Feature;
use App\Models\LandlordFeature;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ListingEligibilityEnforcementTest
 *
 * Proves that the backend rejects duplicate listing creation when any
 * in-flight listing (draft, pending_review, or active) already exists on
 * the unit. Matches the frontend BLOCKING_STATUSES = ['draft','pending_review','active'].
 */
class ListingEligibilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Admin::factory()->create(['is_super_admin' => true]);

        $this->landlord = User::factory()->create([
            'user_type' => UserType::LANDLORD,
            'identity_verified' => true,
            'email_verified_at' => now(),
            'verification_status' => 'verified',
        ]);

        $feature = Feature::create([
            'key' => 'listings',
            'name' => 'Property Listings',
            'description' => 'Create and manage property listings',
            'requires_identity_verification' => false,
            'enabled_by_default' => true,
        ]);

        LandlordFeature::create([
            'landlord_id' => $this->landlord->id,
            'feature_id' => $feature->id,
            'enabled' => true,
            'enabled_by' => $admin->id,
            'enabled_at' => now(),
        ]);
    }

    private function ownedUnit(): Unit
    {
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);

        return Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);
    }

    private function listingPayload(): array
    {
        return [
            'title' => 'Second listing attempt',
            'description' => str_repeat('A well-kept apartment in a secure estate. ', 3),
            'pets_allowed' => false,
        ];
    }

    // ─── BLOCKING STATUSES ────────────────────────────────────────────────────

    /** A unit with an existing DRAFT listing cannot get a second listing. */
    public function test_cannot_create_listing_when_unit_has_draft_listing(): void
    {
        $unit = $this->ownedUnit();
        Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::DRAFT,
        ]);

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", $this->listingPayload())
            ->assertStatus(422)
            ->assertJson(['message' => 'This unit already has a listing in progress. Edit or remove it before creating another.']);
    }

    /** A unit with an existing PENDING_REVIEW listing cannot get a second listing. */
    public function test_cannot_create_listing_when_unit_has_pending_review_listing(): void
    {
        $unit = $this->ownedUnit();
        Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::PENDING_REVIEW,
        ]);

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", $this->listingPayload())
            ->assertStatus(422)
            ->assertJson(['message' => 'This unit already has a listing in progress. Edit or remove it before creating another.']);
    }

    /** A unit with an existing ACTIVE listing cannot get a second listing. */
    public function test_cannot_create_listing_when_unit_has_active_listing(): void
    {
        $unit = $this->ownedUnit();
        Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", $this->listingPayload())
            ->assertStatus(422)
            ->assertJson(['message' => 'This unit already has a listing in progress. Edit or remove it before creating another.']);
    }

    // ─── NON-BLOCKING STATUSES — unit should be re-listable ──────────────────

    /** A unit whose only listing was REJECTED can receive a new listing. */
    public function test_can_create_listing_when_existing_listing_is_rejected(): void
    {
        $unit = $this->ownedUnit();
        Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::REJECTED,
        ]);

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", $this->listingPayload())
            ->assertStatus(201);
    }

    /** A unit whose only listing is INACTIVE can receive a new listing. */
    public function test_can_create_listing_when_existing_listing_is_inactive(): void
    {
        $unit = $this->ownedUnit();
        Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::INACTIVE,
        ]);

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", $this->listingPayload())
            ->assertStatus(201);
    }

    /** A unit whose only listing is soft-deleted (withdrawn) can receive a new listing. */
    public function test_can_create_listing_when_existing_listing_is_soft_deleted(): void
    {
        $unit = $this->ownedUnit();
        $old = Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::DRAFT,
        ]);
        $old->delete(); // soft-delete simulates "withdrawn"

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", $this->listingPayload())
            ->assertStatus(201);
    }

    // ─── OWNERSHIP ENFORCEMENT ────────────────────────────────────────────────

    /** A landlord cannot create a listing on another landlord's unit (403, not 422). */
    public function test_ownership_still_enforced_for_other_landlords_unit(): void
    {
        $other = User::factory()->create(['user_type' => UserType::LANDLORD]);
        $foreignProperty = Property::factory()->create(['landlord_id' => $other->id]);
        $foreignUnit = Unit::factory()->create(['property_id' => $foreignProperty->id]);

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$foreignUnit->id}/listings", $this->listingPayload())
            ->assertForbidden();
    }
}
