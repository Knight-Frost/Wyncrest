<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ListingStatus;
use App\Enums\UserType;
use App\Models\Admin;
use App\Models\Application;
use App\Models\Feature;
use App\Models\LandlordFeature;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LandlordListingLifecycleTest
 *
 * Covers the new landlord self-service listing lifecycle actions
 * (withdraw/deactivate/reactivate/archive/restore, resubmit-after-rejection),
 * the real audit-log-backed history endpoint, the non-fabricated
 * missing_requirements computation, and the application counts/filter.
 */
class LandlordListingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function verifiedLandlord(): User
    {
        return User::factory()->create([
            'user_type' => UserType::LANDLORD,
            'identity_verified' => true,
            'email_verified_at' => now(),
            'verification_status' => 'verified',
        ]);
    }

    protected function enableListingsFeature(User $landlord): void
    {
        $feature = Feature::firstOrCreate(
            ['key' => 'listings'],
            ['name' => 'Property Listings', 'description' => 'Create and manage property listings', 'requires_identity_verification' => false, 'enabled_by_default' => true]
        );

        LandlordFeature::firstOrCreate(
            ['landlord_id' => $landlord->id, 'feature_id' => $feature->id],
            ['enabled' => true, 'enabled_by' => Admin::factory()->create()->id, 'enabled_at' => now()]
        );
    }

    // ── resubmit after rejection ────────────────────────────────────────────

    public function test_landlord_can_resubmit_a_rejected_listing()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->rejected()->create(['landlord_id' => $landlord->id]);

        $response = $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/submit");

        $response->assertStatus(200);
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'status' => ListingStatus::PENDING_REVIEW->value,
            'rejection_reason' => null,
        ]);
    }

    public function test_active_listing_cannot_be_submitted()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->active()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/submit")
            ->assertStatus(403);
    }

    // ── withdraw ────────────────────────────────────────────────────────────

    public function test_landlord_can_withdraw_a_pending_submission()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->pendingReview()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/withdraw")
            ->assertStatus(200);

        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => ListingStatus::DRAFT->value]);
    }

    public function test_draft_listing_cannot_be_withdrawn()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->draft()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/withdraw")
            ->assertStatus(403);
    }

    // ── deactivate / reactivate ─────────────────────────────────────────────

    public function test_landlord_can_deactivate_an_active_listing()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->active()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/deactivate")
            ->assertStatus(200);

        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => ListingStatus::INACTIVE->value]);
    }

    public function test_draft_listing_cannot_be_deactivated()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->draft()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/deactivate")
            ->assertStatus(403);
    }

    public function test_landlord_can_reactivate_an_inactive_listing()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->create([
            'landlord_id' => $landlord->id,
            'status' => ListingStatus::INACTIVE,
        ]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/reactivate")
            ->assertStatus(200);

        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => ListingStatus::ACTIVE->value]);
    }

    // ── archive / restore ───────────────────────────────────────────────────

    public function test_landlord_can_archive_a_draft_listing()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->draft()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/archive")
            ->assertStatus(200);

        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => ListingStatus::ARCHIVED->value]);
    }

    public function test_active_listing_cannot_be_archived_directly()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->active()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/archive")
            ->assertStatus(403);
    }

    public function test_pending_listing_cannot_be_archived()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->pendingReview()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/archive")
            ->assertStatus(403);
    }

    public function test_landlord_can_restore_an_archived_listing_to_draft()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->create([
            'landlord_id' => $landlord->id,
            'status' => ListingStatus::ARCHIVED,
        ]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/restore")
            ->assertStatus(200);

        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => ListingStatus::DRAFT->value]);
    }

    public function test_draft_listing_cannot_be_restored()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->draft()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/restore")
            ->assertStatus(403);
    }

    // ── ownership enforcement ───────────────────────────────────────────────

    public function test_landlord_cannot_deactivate_another_landlords_listing()
    {
        $owner = $this->verifiedLandlord();
        $intruder = $this->verifiedLandlord();
        $listing = Listing::factory()->active()->create(['landlord_id' => $owner->id]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/deactivate")
            ->assertStatus(403);
    }

    // ── history endpoint ────────────────────────────────────────────────────

    public function test_history_returns_real_audit_trail_in_order()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->pendingReview()->create(['landlord_id' => $landlord->id]);

        $this->actingAs($landlord, 'sanctum')
            ->postJson("/api/landlord/listings/{$listing->id}/withdraw")
            ->assertStatus(200);

        $response = $this->actingAs($landlord, 'sanctum')
            ->getJson("/api/landlord/listings/{$listing->id}/history");

        $response->assertStatus(200);
        $actions = collect($response->json())->pluck('action');
        $this->assertTrue($actions->contains('listing_withdrawn'));
    }

    public function test_history_denied_for_non_owner()
    {
        $owner = $this->verifiedLandlord();
        $intruder = $this->verifiedLandlord();
        $listing = Listing::factory()->active()->create(['landlord_id' => $owner->id]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/landlord/listings/{$listing->id}/history")
            ->assertStatus(403);
    }

    // ── missing_requirements truthfulness ────────────────────────────────────

    public function test_incomplete_draft_reports_real_missing_requirements()
    {
        $landlord = $this->verifiedLandlord();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create([
            'property_id' => $property->id,
            'rent_amount' => 0,
            'available_from' => null,
        ]);
        $listing = Listing::factory()->create([
            'landlord_id' => $landlord->id,
            'unit_id' => $unit->id,
            'title' => '',
            'description' => 'Too short',
        ]);

        $response = $this->actingAs($landlord, 'sanctum')
            ->getJson("/api/landlord/listings/{$listing->id}");

        $response->assertStatus(200);
        $missing = $response->json('missing_requirements');
        $this->assertContains('listing title', $missing);
        $this->assertContains('description (at least 50 characters)', $missing);
        $this->assertContains('cover photo', $missing);
        $this->assertContains('monthly rent (set on the unit)', $missing);
        $this->assertContains('available date (set on the unit)', $missing);
    }

    public function test_active_listing_reports_no_missing_requirements()
    {
        $landlord = $this->verifiedLandlord();
        $listing = Listing::factory()->active()->create(['landlord_id' => $landlord->id]);

        $response = $this->actingAs($landlord, 'sanctum')
            ->getJson("/api/landlord/listings/{$listing->id}");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('missing_requirements'));
    }

    // ── application counts + filter ──────────────────────────────────────────

    public function test_index_reports_real_application_counts()
    {
        $landlord = $this->verifiedLandlord();
        $this->enableListingsFeature($landlord);
        $listing = Listing::factory()->active()->create(['landlord_id' => $landlord->id]);

        Application::factory()->count(2)->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);
        Application::factory()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'status' => ApplicationStatus::APPROVED,
        ]);

        $response = $this->actingAs($landlord, 'sanctum')->getJson('/api/landlord/listings');

        $response->assertStatus(200);
        $row = collect($response->json())->firstWhere('id', $listing->id);
        $this->assertSame(3, $row['applications_count']);
        $this->assertSame(2, $row['new_applications_count']);
    }

    public function test_applications_can_be_filtered_by_listing_id()
    {
        $landlord = $this->verifiedLandlord();
        $listingA = Listing::factory()->active()->create(['landlord_id' => $landlord->id]);
        $listingB = Listing::factory()->active()->create(['landlord_id' => $landlord->id]);

        Application::factory()->create(['listing_id' => $listingA->id, 'landlord_id' => $landlord->id]);
        Application::factory()->create(['listing_id' => $listingB->id, 'landlord_id' => $landlord->id]);

        $response = $this->actingAs($landlord, 'sanctum')
            ->getJson("/api/landlord/applications?listing_id={$listingA->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertSame($listingA->id, $response->json('0.listing_id'));
    }

    public function test_applications_filter_by_another_landlords_listing_returns_empty_not_leaked()
    {
        $landlord = $this->verifiedLandlord();
        $otherLandlord = $this->verifiedLandlord();
        $otherListing = Listing::factory()->active()->create(['landlord_id' => $otherLandlord->id]);
        Application::factory()->create(['listing_id' => $otherListing->id, 'landlord_id' => $otherLandlord->id]);

        $response = $this->actingAs($landlord, 'sanctum')
            ->getJson("/api/landlord/applications?listing_id={$otherListing->id}");

        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }
}
