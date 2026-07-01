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
 * ListingBuilderFlowTest
 *
 * Locks in the exact endpoint contract the route-based Create Listing builder
 * (frontend: /app/listings/create) depends on. The builder persists across steps
 * by writing to the UNIT (PUT /units/{id}), creating + updating a DRAFT LISTING
 * (POST /units/{id}/listings, PUT /listings/{id}), and finally submitting for
 * review (POST /listings/{id}/submit). These tests prove that sequence works and
 * that authorization / eligibility / the verification gate are enforced server-side.
 */
class ListingBuilderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create(['is_super_admin' => true]);

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
            'enabled_by' => $this->admin->id,
            'enabled_at' => now(),
        ]);
    }

    private function ownedUnit(User $owner): Unit
    {
        $property = Property::factory()->create(['landlord_id' => $owner->id]);

        return Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);
    }

    /** The full builder sequence: edit unit → create draft → patch listing/unit → submit. */
    public function test_builder_persists_across_steps_then_submits_for_review(): void
    {
        $unit = $this->ownedUnit($this->landlord);

        // Step 1 — unit basics + create draft listing.
        $this->actingAs($this->landlord, 'sanctum')
            ->putJson("/api/landlord/units/{$unit->id}", [
                'unit_number' => '4B',
                'bedrooms' => 2,
                'bathrooms' => 2,
                'square_feet' => 1100,
            ])->assertOk();

        $create = $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", [
                'title' => 'Bright two-bedroom in Cantonments',
                'description' => str_repeat('A spacious, well-finished two-bedroom apartment. ', 3),
                'pets_allowed' => false,
            ])->assertStatus(201)->json('listing.id');

        // Step 3 — pricing on the unit + listing terms.
        $this->actingAs($this->landlord, 'sanctum')
            ->putJson("/api/landlord/units/{$unit->id}", [
                'rent_amount' => 4500,
                'security_deposit' => 9000,
            ])->assertOk();
        $this->actingAs($this->landlord, 'sanctum')
            ->putJson("/api/landlord/listings/{$create}", [
                'lease_duration_months' => 12,
            ])->assertOk();

        // Step 4 — amenities on the unit + pets on the listing.
        $this->actingAs($this->landlord, 'sanctum')
            ->putJson("/api/landlord/units/{$unit->id}", [
                'amenities' => ['Parking', 'Air conditioning', 'Security'],
            ])->assertOk();

        // Step 6 — submit for review.
        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$create}/submit")
            ->assertOk();

        $this->assertDatabaseHas('listings', [
            'id' => $create,
            'status' => ListingStatus::PENDING_REVIEW->value,
            'title' => 'Bright two-bedroom in Cantonments',
        ]);
        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'rent_amount' => 4500,
            'bedrooms' => 2,
        ]);
        $this->assertEqualsCanonicalizing(
            ['Parking', 'Air conditioning', 'Security'],
            $unit->fresh()->amenities,
        );
    }

    /** A short description (< 50 chars) is rejected by the backend, not just the UI. */
    public function test_invalid_listing_payload_is_rejected(): void
    {
        $unit = $this->ownedUnit($this->landlord);

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", [
                'title' => 'Too short',
                'description' => 'tiny',
                'pets_allowed' => false,
            ])->assertStatus(422)->assertJsonValidationErrors(['description']);
    }

    /** A unit that already has an ACTIVE listing is ineligible (duplicate prevented). */
    public function test_unit_with_active_listing_is_ineligible(): void
    {
        $unit = $this->ownedUnit($this->landlord);
        Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", [
                'title' => 'Second listing attempt',
                'description' => str_repeat('This should not be allowed. ', 3),
                'pets_allowed' => false,
            ])->assertStatus(422);
    }

    /** A landlord cannot build a listing on another landlord's unit. */
    public function test_cannot_build_on_another_landlords_unit(): void
    {
        $other = User::factory()->create(['user_type' => UserType::LANDLORD]);
        $foreignUnit = $this->ownedUnit($other);

        $this->actingAs($this->landlord, 'sanctum')
            ->putJson("/api/landlord/units/{$foreignUnit->id}", ['bedrooms' => 3])
            ->assertForbidden();

        $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$foreignUnit->id}/listings", [
                'title' => 'Not my unit',
                'description' => str_repeat('Trying to list someone else unit. ', 3),
                'pets_allowed' => false,
            ])->assertForbidden();
    }

    /** Unverified landlords can save a draft but cannot submit; the draft persists. */
    public function test_unverified_landlord_can_draft_but_not_submit(): void
    {
        $unverified = User::factory()->create([
            'user_type' => UserType::LANDLORD,
            'verification_status' => 'pending',
            'identity_verified' => false,
        ]);
        $feature = Feature::where('key', 'listings')->first();
        LandlordFeature::create([
            'landlord_id' => $unverified->id,
            'feature_id' => $feature->id,
            'enabled' => true,
            'enabled_at' => now(),
        ]);
        $unit = $this->ownedUnit($unverified);

        $listingId = $this->actingAs($unverified, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", [
                'title' => 'Draft while unverified',
                'description' => str_repeat('A perfectly good draft listing. ', 3),
                'pets_allowed' => false,
            ])->assertStatus(201)->json('listing.id');

        $this->actingAs($unverified, 'sanctum')
            ->postJson("/api/landlord/listings/{$listingId}/submit")
            ->assertStatus(403);

        $this->assertDatabaseHas('listings', [
            'id' => $listingId,
            'status' => ListingStatus::DRAFT->value,
        ]);
    }
}
