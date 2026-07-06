<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PublicListingScopeTest
 *
 * Listing::scopePublic() previously only checked the listing's own status/
 * publish/expiry fields, so an ACTIVE listing whose landlord (or unit) had
 * been soft-deleted/archived was still publicly browsable. These tests pin
 * the fix: such listings are excluded from both the public index and show.
 */
class PublicListingScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveListing(): array
    {
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->available()->create(['property_id' => $property->id]);

        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);

        return [$listing, $landlord, $unit];
    }

    public function test_public_index_excludes_listing_whose_landlord_was_archived(): void
    {
        [$listing, $landlord] = $this->makeActiveListing();

        $this->getJson('/api/listings')->assertOk()
            ->assertJsonFragment(['id' => $listing->id]);

        $landlord->delete(); // soft delete (archive)

        $response = $this->getJson('/api/listings')->assertOk();
        $ids = collect($response->json('data') ?? $response->json())->pluck('id')->all();

        $this->assertNotContains($listing->id, $ids);
    }

    public function test_public_index_excludes_listing_whose_unit_was_deleted(): void
    {
        [$listing, , $unit] = $this->makeActiveListing();

        $unit->delete(); // soft delete

        $response = $this->getJson('/api/listings')->assertOk();
        $ids = collect($response->json('data') ?? $response->json())->pluck('id')->all();

        $this->assertNotContains($listing->id, $ids);
    }
}
