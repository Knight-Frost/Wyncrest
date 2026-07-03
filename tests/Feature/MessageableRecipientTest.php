<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MessageableRecipientTest
 *
 * Covers GET /api/tenant/messageable-recipients
 *
 * The source set is exclusively the authenticated tenant's saved listings,
 * reshaped into compact recipient objects for a "To:" search field.
 */
class MessageableRecipientTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    protected User $tenant;

    protected User $landlord;

    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->tenant()->create();
        $this->landlord = User::factory()->landlord()->create([
            'first_name' => 'Kwame',
            'last_name' => 'Mensah',
        ]);

        $property = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
            'street_address' => 'East Legon',
            'city' => 'Accra',
        ]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $this->listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'title' => '2-Bedroom Apartment in East Legon',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helper: save a listing for a user (pivot attach)
    // -------------------------------------------------------------------------

    private function saveListing(User $user, Listing $listing): void
    {
        $user->savedListings()->attach($listing->id);
    }

    // -------------------------------------------------------------------------
    // Helper: create an active conversation between two users about a listing
    // -------------------------------------------------------------------------

    private function makeConversation(
        User $participantOne,
        User $participantTwo,
        Listing $listing
    ): Conversation {
        return Conversation::create([
            'participant_one_type' => User::class,
            'participant_one_id' => $participantOne->id,
            'participant_two_type' => User::class,
            'participant_two_id' => $participantTwo->id,
            'subject_type' => Listing::class,
            'subject_id' => $listing->id,
            'title' => $listing->title,
            'status' => 'active',
            'last_message_at' => now(),
            'last_message_by' => $participantOne->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Unauthenticated guard
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/tenant/messageable-recipients')->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Role guards: non-tenant roles cannot reach this tenant-only route
    // -------------------------------------------------------------------------

    public function test_landlord_cannot_reach_tenant_route(): void
    {
        Sanctum::actingAs(User::factory()->landlord()->create(), [], 'sanctum');

        // A landlord IS authenticated (bearer) but is the wrong role → 403.
        $this->getJson('/api/tenant/messageable-recipients')->assertStatus(403);
    }

    public function test_admin_cannot_reach_tenant_route(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->getJson('/api/tenant/messageable-recipients')->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Core: tenant with saved listings gets correct recipients
    // -------------------------------------------------------------------------

    public function test_tenant_with_saved_listings_gets_back_reshaped_recipients(): void
    {
        $this->saveListing($this->tenant, $this->listing);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson('/api/tenant/messageable-recipients');

        $response->assertStatus(200);

        $items = $response->json();
        $this->assertIsArray($items);
        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertSame($this->listing->id, $item['listing_id']);
        $this->assertSame('2-Bedroom Apartment in East Legon', $item['listing_title']);
        $this->assertSame($this->landlord->id, $item['landlord']['id']);
        $this->assertSame('Kwame Mensah', $item['landlord']['name']);
        $this->assertSame('East Legon, Accra', $item['location']);
        $this->assertNull($item['thumbnail_url']);            // no photo seeded
        $this->assertNull($item['existing_conversation_id']); // no conversation yet
    }

    // -------------------------------------------------------------------------
    // Thumbnail: photo path returned raw when a primary photo exists
    // -------------------------------------------------------------------------

    public function test_thumbnail_url_contains_primary_photo_path_when_present(): void
    {
        $this->saveListing($this->tenant, $this->listing);

        ListingPhoto::create([
            'listing_id' => $this->listing->id,
            'path' => 'listings/test-photo.jpg',
            'disk' => 'local',
            'filename' => 'test-photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson('/api/tenant/messageable-recipients');

        $response->assertStatus(200);

        $item = $response->json(0);
        $this->assertSame('listings/test-photo.jpg', $item['thumbnail_url']);
    }

    // -------------------------------------------------------------------------
    // Tenant-scoping: another tenant's saved listings do NOT appear
    // -------------------------------------------------------------------------

    public function test_another_tenants_saved_listings_do_not_appear(): void
    {
        $otherTenant = User::factory()->tenant()->create();
        $this->saveListing($otherTenant, $this->listing);
        // The authenticated tenant has NOT saved anything

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson('/api/tenant/messageable-recipients');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    // -------------------------------------------------------------------------
    // Listing with no landlord is excluded
    // -------------------------------------------------------------------------

    public function test_listing_with_no_landlord_is_excluded(): void
    {
        // Create a landlord, attach their listing to the tenant's saved set, then
        // soft-delete the landlord. Eloquent's BelongsTo query respects SoftDeletes
        // and returns null for deleted records — matching the "no landlord" case.
        $orphanLandlord = User::factory()->landlord()->create();
        $unit = Unit::factory()->create();
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $orphanLandlord->id,
        ]);

        $this->saveListing($this->tenant, $listing);

        // Soft-delete the landlord — the listing FK still exists but the relationship
        // resolves to null because soft-deleted models are excluded from queries.
        $orphanLandlord->delete();

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson('/api/tenant/messageable-recipients');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    // -------------------------------------------------------------------------
    // existing_conversation_id: null when no conversation, correct id when one exists
    // -------------------------------------------------------------------------

    public function test_existing_conversation_id_is_null_when_no_conversation(): void
    {
        $this->saveListing($this->tenant, $this->listing);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $item = $this->getJson('/api/tenant/messageable-recipients')->json(0);

        $this->assertNull($item['existing_conversation_id']);
    }

    public function test_existing_conversation_id_is_returned_when_active_conversation_exists(): void
    {
        $this->saveListing($this->tenant, $this->listing);

        $conv = $this->makeConversation($this->tenant, $this->landlord, $this->listing);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $item = $this->getJson('/api/tenant/messageable-recipients')->json(0);

        $this->assertSame($conv->id, $item['existing_conversation_id']);
    }

    public function test_existing_conversation_id_is_returned_even_when_tenant_is_participant_two(): void
    {
        // Participant arrangement reversed (landlord is participant_one)
        $this->saveListing($this->tenant, $this->listing);

        $conv = $this->makeConversation($this->landlord, $this->tenant, $this->listing);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $item = $this->getJson('/api/tenant/messageable-recipients')->json(0);

        $this->assertSame($conv->id, $item['existing_conversation_id']);
    }

    // -------------------------------------------------------------------------
    // q filter: search by landlord name
    // -------------------------------------------------------------------------

    public function test_q_filter_matches_on_landlord_name(): void
    {
        $this->saveListing($this->tenant, $this->listing);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        // Matching query
        $response = $this->getJson('/api/tenant/messageable-recipients?q=Kwame');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());

        // Non-matching query
        $response = $this->getJson('/api/tenant/messageable-recipients?q=NonexistentName');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    // -------------------------------------------------------------------------
    // q filter: search by listing title
    // -------------------------------------------------------------------------

    public function test_q_filter_matches_on_listing_title(): void
    {
        $this->saveListing($this->tenant, $this->listing);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        // Partial match on title (case-insensitive)
        $response = $this->getJson('/api/tenant/messageable-recipients?q=east+legon');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    // -------------------------------------------------------------------------
    // q filter: non-matching q returns empty array
    // -------------------------------------------------------------------------

    public function test_q_filter_non_matching_returns_empty_array(): void
    {
        $this->saveListing($this->tenant, $this->listing);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson('/api/tenant/messageable-recipients?q=zzznomatch');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    // -------------------------------------------------------------------------
    // q validation: q longer than 100 characters is rejected
    // -------------------------------------------------------------------------

    public function test_q_longer_than_100_chars_is_rejected(): void
    {
        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $longQ = str_repeat('a', 101);

        $this->getJson("/api/tenant/messageable-recipients?q={$longQ}")
            ->assertStatus(422);
    }
}
