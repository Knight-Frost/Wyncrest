<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Admin;
use App\Models\Property;
use App\Models\Unit;
use App\Models\Listing;
use App\Models\Feature;
use App\Models\LandlordFeature;
use App\Enums\UserType;
use App\Enums\PropertyType;
use App\Enums\UnitAvailabilityStatus;
use App\Enums\ListingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ListingSubmissionWorkflowTest
 * 
 * Tests the complete landlord → admin workflow:
 * 1. Landlord creates property
 * 2. Landlord creates unit
 * 3. Landlord creates listing (draft)
 * 4. Landlord submits for review
 * 5. Admin approves listing
 * 6. Listing becomes public
 */
class ListingSubmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;
    protected Admin $admin;
    protected Feature $listingsFeature;

    protected function setUp(): void
    {
        parent::setUp();

        // Create landlord
        $this->landlord = User::factory()->create([
            'user_type' => UserType::LANDLORD,
            'identity_verified' => true,
            'email_verified_at' => now(),
        ]);

        // Create admin
        $this->admin = Admin::factory()->create([
            'is_super_admin' => true,
        ]);

        // Create listings feature
        $this->listingsFeature = Feature::create([
            'key' => 'listings',
            'name' => 'Property Listings',
            'description' => 'Create and manage property listings',
            'requires_identity_verification' => false,
            'enabled_by_default' => true,
        ]);

        // Enable listings feature for landlord
        LandlordFeature::create([
            'landlord_id' => $this->landlord->id,
            'feature_id' => $this->listingsFeature->id,
            'enabled' => true,
            'enabled_by' => $this->admin->id,
            'enabled_at' => now(),
        ]);
    }

    public function test_landlord_can_create_property()
    {
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/properties', [
                'name' => 'Test Property',
                'property_type' => PropertyType::APARTMENT->value,
                'street_address' => '123 Main St',
                'city' => 'San Francisco',
                'state' => 'CA',
                'zip_code' => '94102',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'property' => ['id', 'name', 'property_type']
            ]);

        $this->assertDatabaseHas('properties', [
            'landlord_id' => $this->landlord->id,
            'name' => 'Test Property',
        ]);
    }

    public function test_landlord_can_create_unit()
    {
        $property = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
        ]);

        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/properties/{$property->id}/units", [
                'unit_number' => '101',
                'bedrooms' => 2,
                'bathrooms' => 2,
                'rent_amount' => 3000,
                'availability_status' => UnitAvailabilityStatus::AVAILABLE->value,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'unit' => ['id', 'unit_number']
            ]);

        $this->assertDatabaseHas('units', [
            'property_id' => $property->id,
            'unit_number' => '101',
        ]);
    }

    public function test_complete_listing_submission_workflow()
    {
        // Step 1: Create property
        $property = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
        ]);

        // Step 2: Create unit
        $unit = Unit::factory()->create([
            'property_id' => $property->id,
        ]);

        // Step 3: Create listing (draft)
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", [
                'title' => 'Beautiful 2BR Apartment',
                'description' => 'A wonderful apartment in downtown SF with amazing views and modern amenities.',
                'pets_allowed' => false,
            ]);

        $response->assertStatus(201);
        $listingId = $response->json('listing.id');

        $this->assertDatabaseHas('listings', [
            'id' => $listingId,
            'status' => ListingStatus::DRAFT->value,
        ]);

        // Step 4: Submit for review
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listingId}/submit");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Listing submitted for admin review'
            ]);

        $this->assertDatabaseHas('listings', [
            'id' => $listingId,
            'status' => ListingStatus::PENDING_REVIEW->value,
        ]);

        // Step 5: Admin approves listing
        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/listings/{$listingId}/approve");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Listing approved and published'
            ]);

        $this->assertDatabaseHas('listings', [
            'id' => $listingId,
            'status' => ListingStatus::ACTIVE->value,
        ]);

        // Step 6: Verify listing is publicly visible
        $response = $this->getJson("/api/listings/{$listingId}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $listingId,
                'title' => 'Beautiful 2BR Apartment',
            ]);
    }

    public function test_landlord_without_feature_cannot_create_listing()
    {
        // Disable feature
        LandlordFeature::where('landlord_id', $this->landlord->id)->delete();

        $unit = Unit::factory()->create([
            'property_id' => Property::factory()->create([
                'landlord_id' => $this->landlord->id,
            ])->id,
        ]);

        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/units/{$unit->id}/listings", [
                'title' => 'Test Listing',
                'description' => 'This should fail because the feature is disabled and the landlord does not have access.',
                'pets_allowed' => false,
            ]);

        $response->assertStatus(500); // Feature gating throws exception
    }

    public function test_tenant_cannot_access_landlord_routes()
    {
        $tenant = User::factory()->create([
            'user_type' => UserType::TENANT,
        ]);

        $response = $this->actingAs($tenant, 'sanctum')
            ->getJson('/api/landlord/properties');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'This action is only available to landlords.'
            ]);
    }

    public function test_landlord_cannot_access_tenant_routes()
    {
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->getJson('/api/tenant/dashboard');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'This action is only available to tenants.'
            ]);
    }
}