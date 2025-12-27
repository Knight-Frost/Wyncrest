<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Property;
use App\Models\Unit;
use App\Models\Listing;
use App\Enums\UserType;
use App\Enums\ListingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PolicyAuthorizationTest
 * 
 * Tests that policies correctly enforce ownership rules.
 */
class PolicyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_can_only_view_own_properties()
    {
        $landlord1 = User::factory()->create(['user_type' => UserType::LANDLORD]);
        $landlord2 = User::factory()->create(['user_type' => UserType::LANDLORD]);

        $property1 = Property::factory()->create(['landlord_id' => $landlord1->id]);
        $property2 = Property::factory()->create(['landlord_id' => $landlord2->id]);

        // Landlord 1 can view own property
        $this->assertTrue($landlord1->can('view', $property1));

        // Landlord 1 cannot view landlord 2's property
        $this->assertFalse($landlord1->can('view', $property2));
    }

    public function test_landlord_can_only_update_own_units()
    {
        $landlord1 = User::factory()->create(['user_type' => UserType::LANDLORD]);
        $landlord2 = User::factory()->create(['user_type' => UserType::LANDLORD]);

        $unit1 = Unit::factory()->create([
            'property_id' => Property::factory()->create([
                'landlord_id' => $landlord1->id
            ])->id
        ]);

        $unit2 = Unit::factory()->create([
            'property_id' => Property::factory()->create([
                'landlord_id' => $landlord2->id
            ])->id
        ]);

        // Landlord 1 can update own unit
        $this->assertTrue($landlord1->can('update', $unit1));

        // Landlord 1 cannot update landlord 2's unit
        $this->assertFalse($landlord1->can('update', $unit2));
    }

    public function test_landlord_cannot_edit_pending_listing()
    {
        $landlord = User::factory()->create(['user_type' => UserType::LANDLORD]);

        $listing = Listing::factory()->create([
            'landlord_id' => $landlord->id,
            'status' => ListingStatus::PENDING_REVIEW,
        ]);

        // Cannot update pending listing
        $this->assertFalse($landlord->can('update', $listing));
    }

    public function test_landlord_can_edit_draft_listing()
    {
        $landlord = User::factory()->create(['user_type' => UserType::LANDLORD]);

        $listing = Listing::factory()->create([
            'landlord_id' => $landlord->id,
            'status' => ListingStatus::DRAFT,
        ]);

        // Can update draft listing
        $this->assertTrue($landlord->can('update', $listing));
    }

    public function test_landlord_can_only_submit_draft_listings()
    {
        $landlord = User::factory()->create(['user_type' => UserType::LANDLORD]);

        $draftListing = Listing::factory()->create([
            'landlord_id' => $landlord->id,
            'status' => ListingStatus::DRAFT,
        ]);

        $activeListing = Listing::factory()->create([
            'landlord_id' => $landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        // Can submit draft
        $this->assertTrue($landlord->can('submit', $draftListing));

        // Cannot submit active listing
        $this->assertFalse($landlord->can('submit', $activeListing));
    }

    public function test_tenant_cannot_create_properties()
    {
        $tenant = User::factory()->create(['user_type' => UserType::TENANT]);

        // Tenant cannot create properties
        $this->assertFalse($tenant->can('create', Property::class));
    }

    public function test_landlord_cannot_delete_property_with_units()
    {
        $landlord = User::factory()->create(['user_type' => UserType::LANDLORD]);

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);

        // Property with no units can be deleted
        $this->assertTrue($landlord->can('delete', $property));

        // Add a unit
        Unit::factory()->create(['property_id' => $property->id]);

        // Refresh property to update relationship count
        $property->refresh();

        // Property with units cannot be deleted
        $this->assertFalse($landlord->can('delete', $property));
    }
}
