<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PublicListingAddressVisibilityTest
 *
 * The public listing endpoints previously returned raw Property models with
 * no field filtering, leaking street_address to anonymous visitors
 * regardless of the landlord's intent. These tests pin the fix: the street
 * address is only ever present when address_visibility === 'public'.
 */
class PublicListingAddressVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveListing(string $addressVisibility): Listing
    {
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create([
            'landlord_id' => $landlord->id,
            'street_address' => '42 Secret Close',
            'address_visibility' => $addressVisibility,
        ]);
        $unit = Unit::factory()->available()->create(['property_id' => $property->id]);

        return Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);
    }

    public function test_public_show_hides_street_address_by_default()
    {
        $listing = $this->makeActiveListing('area_only');

        $response = $this->getJson("/api/listings/{$listing->id}");

        $response->assertStatus(200);
        $this->assertNull($response->json('unit.property.street_address'));
        $this->assertStringNotContainsString('Secret Close', $response->getContent());
    }

    public function test_public_show_hides_street_address_for_full_after_approval()
    {
        $listing = $this->makeActiveListing('full_after_approval');

        $response = $this->getJson("/api/listings/{$listing->id}");

        $response->assertStatus(200);
        $this->assertNull($response->json('unit.property.street_address'));
    }

    public function test_public_show_exposes_street_address_when_opted_public()
    {
        $listing = $this->makeActiveListing('public');

        $response = $this->getJson("/api/listings/{$listing->id}");

        $response->assertStatus(200);
        $this->assertSame('42 Secret Close', $response->json('unit.property.street_address'));
    }

    public function test_public_index_hides_street_address_by_default()
    {
        $this->makeActiveListing('area_only');

        $response = $this->getJson('/api/listings');

        $response->assertStatus(200);
        $this->assertStringNotContainsString('Secret Close', $response->getContent());
    }

    public function test_featured_endpoint_hides_street_address_by_default()
    {
        $listing = $this->makeActiveListing('area_only');
        $listing->update(['featured' => true]);

        $response = $this->getJson('/api/listings/featured');

        $response->assertStatus(200);
        $this->assertStringNotContainsString('Secret Close', $response->getContent());
    }

    public function test_admin_tenant_preview_matches_public_visibility_rule()
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $listing = $this->makeActiveListing('area_only');

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/admin/listings/review/{$listing->id}/preview");

        $response->assertStatus(200);
        $this->assertNull($response->json('property.street_address'));
        $this->assertNull($response->json('property.full_address'));
        $this->assertStringNotContainsString('Secret Close', $response->getContent());
    }

    public function test_admin_tenant_preview_exposes_address_when_property_is_public()
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $listing = $this->makeActiveListing('public');

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/api/admin/listings/review/{$listing->id}/preview");

        $response->assertStatus(200);
        $this->assertSame('42 Secret Close', $response->json('property.street_address'));
    }
}
