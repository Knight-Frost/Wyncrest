<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Document;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LandlordApplicationCommandCentreTest
 *
 * Covers the new surface added for the landlord Applications command-centre
 * rebuild: the draft-leak fix on index(), the shortlist toggle, per-application
 * messaging, and the DocumentPolicy grant that lets a landlord open a document
 * attached to their own applicant's application.
 */
class LandlordApplicationCommandCentreTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;

    protected User $landlord;

    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->tenant()->identityVerified()->create();
        $this->landlord = User::factory()->landlord()->create();

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $this->listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);
    }

    // =========================================================================
    // index() no longer leaks drafts
    // =========================================================================

    public function test_landlord_index_excludes_tenants_private_draft(): void
    {
        $draft = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::DRAFT,
        ]);

        $submitted = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/applications');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($submitted->id));
        $this->assertFalse($ids->contains($draft->id));
    }

    // =========================================================================
    // Shortlist
    // =========================================================================

    public function test_landlord_can_shortlist_and_unshortlist_an_active_application(): void
    {
        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->postJson("/api/landlord/applications/{$application->id}/shortlist");
        $response->assertStatus(200)->assertJsonPath('is_shortlisted', true);
        $this->assertNotNull($application->fresh()->shortlisted_at);

        $response = $this->postJson("/api/landlord/applications/{$application->id}/shortlist");
        $response->assertStatus(200)->assertJsonPath('is_shortlisted', false);
        $this->assertNull($application->fresh()->shortlisted_at);
    }

    public function test_landlord_cannot_shortlist_a_decided_application(): void
    {
        $application = Application::factory()->approved()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $this->postJson("/api/landlord/applications/{$application->id}/shortlist")
            ->assertStatus(403);
    }

    public function test_different_landlord_cannot_shortlist_application(): void
    {
        $otherLandlord = User::factory()->landlord()->create();

        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($otherLandlord, [], 'sanctum');

        $this->postJson("/api/landlord/applications/{$application->id}/shortlist")
            ->assertStatus(403);
    }

    // =========================================================================
    // Messaging
    // =========================================================================

    public function test_landlord_can_message_applicant_and_fetch_the_thread(): void
    {
        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $empty = $this->getJson("/api/landlord/applications/{$application->id}/messages");
        $empty->assertStatus(200)->assertJson(['conversation_id' => null, 'messages' => []]);

        $send = $this->postJson("/api/landlord/applications/{$application->id}/messages", [
            'body' => 'Is the move-in date flexible?',
        ]);
        $send->assertStatus(201);
        $this->assertCount(1, $send->json('messages'));
        $this->assertTrue($send->json('messages.0.sender.is_me'));

        $this->assertDatabaseHas('conversations', [
            'subject_type' => Listing::class,
            'subject_id' => $this->listing->id,
        ]);

        $fetch = $this->getJson("/api/landlord/applications/{$application->id}/messages");
        $fetch->assertStatus(200);
        $this->assertCount(1, $fetch->json('messages'));
        $this->assertSame('Is the move-in date flexible?', $fetch->json('messages.0.body'));
    }

    public function test_tenant_can_see_landlord_message_in_their_own_conversation(): void
    {
        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $this->postJson("/api/landlord/applications/{$application->id}/messages", [
            'body' => 'Could you share a reference?',
        ])->assertStatus(201);

        Sanctum::actingAs($this->tenant, [], 'sanctum');
        $conversations = $this->getJson('/api/tenant/conversations');
        $conversations->assertStatus(200);
        $this->assertCount(1, $conversations->json());
    }

    public function test_different_landlord_cannot_message_on_application_they_do_not_own(): void
    {
        $otherLandlord = User::factory()->landlord()->create();

        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($otherLandlord, [], 'sanctum');

        $this->postJson("/api/landlord/applications/{$application->id}/messages", [
            'body' => 'Hello',
        ])->assertStatus(403);
    }

    // =========================================================================
    // Document cross-access
    // =========================================================================

    public function test_landlord_can_download_a_document_attached_to_their_applicants_application(): void
    {
        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        $document = Document::factory()->create([
            'owner_user_id' => $this->tenant->id,
            'uploaded_by_id' => $this->tenant->id,
            'related_type' => Application::class,
            'related_id' => $application->id,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson("/api/landlord/documents/{$document->id}/download");

        // Policy passes (not a 403); the factory's stored_path doesn't exist on
        // disk in this test, so the controller returns its own 404 for that —
        // what we're verifying here is authorization, not file streaming.
        $response->assertStatus(404)->assertJson(['message' => 'File not found on disk.']);
    }

    public function test_unrelated_landlord_still_cannot_download_a_tenants_document(): void
    {
        $otherLandlord = User::factory()->landlord()->create();

        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        $document = Document::factory()->create([
            'owner_user_id' => $this->tenant->id,
            'uploaded_by_id' => $this->tenant->id,
            'related_type' => Application::class,
            'related_id' => $application->id,
        ]);

        Sanctum::actingAs($otherLandlord, [], 'sanctum');

        $this->getJson("/api/landlord/documents/{$document->id}/download")
            ->assertStatus(403);
    }

    public function test_landlord_still_cannot_download_a_tenants_unrelated_document(): void
    {
        // A tenant document with no related Application at all (e.g. a global
        // identity/verification upload) must remain off-limits to any landlord.
        $document = Document::factory()->create([
            'owner_user_id' => $this->tenant->id,
            'uploaded_by_id' => $this->tenant->id,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $this->getJson("/api/landlord/documents/{$document->id}/download")
            ->assertStatus(403);
    }
}
