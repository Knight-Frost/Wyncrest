<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ApplicationWorkflowTest
 *
 * Tests the full application lifecycle:
 * - Tenant submits, views, and withdraws applications
 * - Landlord lists applications for their listings and decides on them
 * - Authorization boundaries (403/401/422 on violations)
 * - landlord_notes is never leaked to tenants
 *
 * ASSUMED ROUTES (supervisor must wire these exactly):
 *
 * Tenant group (middleware: auth:sanctum + tenant, prefix: tenant):
 *   GET    /api/tenant/applications                         -> Tenant\ApplicationController@index
 *   POST   /api/tenant/applications                         -> Tenant\ApplicationController@store
 *   GET    /api/tenant/applications/{application}           -> Tenant\ApplicationController@show
 *   POST   /api/tenant/applications/{application}/withdraw  -> Tenant\ApplicationController@withdraw
 *
 * Landlord group (middleware: auth:sanctum + landlord, prefix: landlord):
 *   GET    /api/landlord/applications                       -> Landlord\LandlordApplicationController@index
 *   GET    /api/landlord/applications/{application}         -> Landlord\LandlordApplicationController@show
 *   POST   /api/landlord/applications/{application}/decide  -> Landlord\LandlordApplicationController@decide
 */
class ApplicationWorkflowTest extends TestCase
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

        // Create a publicly-active listing owned by $this->landlord
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $this->listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);
    }

    // =========================================================================
    // Tenant — store
    // =========================================================================

    public function test_tenant_can_submit_application_to_active_listing(): void
    {
        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/applications', [
            'listing_id' => $this->listing->id,
            'cover_note' => 'I am very interested in this property.',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED->value,
        ]);
    }

    public function test_tenant_cannot_apply_to_non_public_listing(): void
    {
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $draft = Listing::factory()->draft()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/applications', [
            'listing_id' => $draft->id,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'This listing is not available for applications']);
    }

    public function test_duplicate_active_application_is_rejected(): void
    {
        // Seed an existing active application for this tenant + listing
        Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/applications', [
            'listing_id' => $this->listing->id,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'You already have an application for this listing']);
    }

    public function test_tenant_cannot_reapply_after_approval(): void
    {
        Application::factory()->approved()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/applications', [
            'listing_id' => $this->listing->id,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'You already have an application for this listing']);
    }

    public function test_tenant_cannot_reapply_after_rejection(): void
    {
        Application::factory()->rejected()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/applications', [
            'listing_id' => $this->listing->id,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'You already have an application for this listing']);
    }

    // =========================================================================
    // Tenant — index
    // =========================================================================

    public function test_tenant_index_returns_only_own_applications(): void
    {
        $otherTenant = User::factory()->tenant()->create();

        // Own application
        $ownApp = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // Another tenant's application — must not appear in response
        Application::factory()->create([
            'tenant_id' => $otherTenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson('/api/tenant/applications');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($ownApp->id));
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // Tenant — show
    // =========================================================================

    public function test_tenant_cannot_view_another_tenants_application(): void
    {
        $otherTenant = User::factory()->tenant()->create();

        $application = Application::factory()->create([
            'tenant_id' => $otherTenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson("/api/tenant/applications/{$application->id}");

        $response->assertStatus(403);
    }

    public function test_landlord_notes_are_not_in_tenant_show_response(): void
    {
        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'landlord_notes' => 'Internal note: tenant background check pending.',
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson("/api/tenant/applications/{$application->id}");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('landlord_notes', $response->json());
    }

    // =========================================================================
    // Tenant — withdraw
    // =========================================================================

    public function test_tenant_can_withdraw_active_application(): void
    {
        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->postJson("/api/tenant/applications/{$application->id}/withdraw");

        $response->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::WITHDRAWN->value,
        ]);
    }

    public function test_tenant_can_reapply_after_withdrawing(): void
    {
        // Create an already-withdrawn application
        Application::factory()->withdrawn()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');

        // Re-apply to the same listing — should succeed (no active application)
        $response = $this->postJson('/api/tenant/applications', [
            'listing_id' => $this->listing->id,
            'cover_note' => 'I am reapplying after my previous withdrawal.',
        ]);

        $response->assertStatus(201);
    }

    // =========================================================================
    // Landlord — index
    // =========================================================================

    public function test_landlord_index_returns_only_own_listing_applications(): void
    {
        $otherLandlord = User::factory()->landlord()->create();
        $otherProperty = Property::factory()->create(['landlord_id' => $otherLandlord->id]);
        $otherUnit = Unit::factory()->create(['property_id' => $otherProperty->id]);
        $otherListing = Listing::factory()->active()->create([
            'unit_id' => $otherUnit->id,
            'landlord_id' => $otherLandlord->id,
        ]);

        // Application for THIS landlord
        $ownApp = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // Application for another landlord — must not appear
        Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $otherListing->id,
            'landlord_id' => $otherLandlord->id,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/applications');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($ownApp->id));
        $this->assertCount(1, $ids);
    }

    // =========================================================================
    // Landlord — decide
    // =========================================================================

    public function test_landlord_can_approve_application(): void
    {
        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->postJson("/api/landlord/applications/{$application->id}/decide", [
            'decision' => 'approved',
            'decision_reason' => 'Excellent tenant profile.',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => ApplicationStatus::APPROVED->value,
        ]);
    }

    public function test_different_landlord_cannot_decide_on_application(): void
    {
        $otherLandlord = User::factory()->landlord()->create();

        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($otherLandlord, [], 'sanctum');

        $response = $this->postJson("/api/landlord/applications/{$application->id}/decide", [
            'decision' => 'approved',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // Unauthenticated
    // =========================================================================

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/tenant/applications');

        $response->assertStatus(401);
    }
}
