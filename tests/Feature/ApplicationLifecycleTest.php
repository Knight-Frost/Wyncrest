<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Models\Application;
use App\Models\ApplicationRequest;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ApplicationLifecycleTest
 *
 * Covers the full mockup-parity application flow beyond the basic
 * submit/withdraw/decide path: drafts + guided form, per-application documents,
 * the landlord "request more info" (NEEDS_ACTION) loop, and the tenant-visible
 * timeline.
 */
class ApplicationLifecycleTest extends TestCase
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

    private function actingTenant(): void
    {
        Sanctum::actingAs($this->tenant, [], 'sanctum');
    }

    private function actingLandlord(): void
    {
        Sanctum::actingAs($this->landlord, [], 'sanctum');
    }

    // =========================================================================
    // Draft lifecycle
    // =========================================================================

    public function test_tenant_can_start_a_draft_without_notifying_landlord(): void
    {
        $this->actingTenant();

        $response = $this->postJson('/api/tenant/applications/draft', [
            'listing_id' => $this->listing->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', ApplicationStatus::DRAFT->value)
            ->assertJsonPath('submitted_at', null);

        $this->assertDatabaseHas('applications', [
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'status' => ApplicationStatus::DRAFT->value,
        ]);

        // Timeline seeded with a "started" event…
        $this->assertDatabaseHas('application_events', [
            'application_id' => $response->json('id'),
            'event' => 'started',
        ]);

        // …but the landlord is NOT notified about an unsubmitted draft.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->landlord->id,
            'type' => NotificationType::APPLICATION_SUBMITTED->value,
        ]);
    }

    public function test_tenant_cannot_start_two_drafts_for_same_listing(): void
    {
        Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::DRAFT,
            'submitted_at' => null,
        ]);

        $this->actingTenant();

        $this->postJson('/api/tenant/applications/draft', [
            'listing_id' => $this->listing->id,
        ])->assertStatus(422);
    }

    public function test_tenant_can_save_draft_form_partially(): void
    {
        $draft = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::DRAFT,
            'form_data' => ['personal' => ['first' => 'Efua']],
        ]);

        $this->actingTenant();

        $this->patchJson("/api/tenant/applications/{$draft->id}", [
            'form_data' => ['employment' => ['status' => 'Employed full-time', 'income' => '9200']],
        ])->assertStatus(200);

        $draft->refresh();
        // Merge preserves the previously-entered section.
        $this->assertSame('Efua', $draft->form_data['personal']['first']);
        $this->assertSame('9200', $draft->form_data['employment']['income']);
    }

    public function test_tenant_can_submit_a_draft(): void
    {
        $draft = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::DRAFT,
            'submitted_at' => null,
        ]);

        $this->actingTenant();

        $this->postJson("/api/tenant/applications/{$draft->id}/submit", [
            'cover_note' => 'Please consider my application.',
        ])->assertStatus(200)
            ->assertJsonPath('status', ApplicationStatus::SUBMITTED->value);

        $draft->refresh();
        $this->assertNotNull($draft->submitted_at);
        $this->assertSame('Please consider my application.', $draft->cover_note);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->landlord->id,
            'type' => NotificationType::APPLICATION_SUBMITTED->value,
        ]);
    }

    public function test_tenant_can_delete_a_draft_but_not_a_submitted_application(): void
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
        ]);

        $this->actingTenant();

        $this->deleteJson("/api/tenant/applications/{$draft->id}")->assertStatus(200);
        $this->assertSoftDeleted('applications', ['id' => $draft->id]);

        $this->deleteJson("/api/tenant/applications/{$submitted->id}")->assertStatus(403);
    }

    public function test_another_tenant_cannot_edit_my_draft(): void
    {
        $draft = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::DRAFT,
        ]);

        Sanctum::actingAs(User::factory()->tenant()->create(), [], 'sanctum');

        $this->patchJson("/api/tenant/applications/{$draft->id}", [
            'form_data' => ['personal' => ['first' => 'Mallory']],
        ])->assertStatus(403);
    }

    // =========================================================================
    // Documents
    // =========================================================================

    public function test_tenant_can_attach_a_document_to_an_application(): void
    {
        Storage::fake('local');

        $app = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        $this->actingTenant();

        $response = $this->postJson("/api/tenant/applications/{$app->id}/documents", [
            'file' => UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf'),
            'document_type' => 'proof_of_income',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('documents', [
            'owner_user_id' => $this->tenant->id,
            'related_type' => (new Application)->getMorphClass(),
            'related_id' => $app->id,
            'document_type' => 'proof_of_income',
        ]);

        $this->assertDatabaseHas('application_events', [
            'application_id' => $app->id,
            'event' => 'documents_uploaded',
        ]);
    }

    public function test_document_upload_shows_in_application_documents(): void
    {
        Storage::fake('local');

        $app = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        $this->actingTenant();

        $this->postJson("/api/tenant/applications/{$app->id}/documents", [
            'file' => UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf'),
            'document_type' => 'proof_of_income',
        ])->assertStatus(201);

        $show = $this->getJson("/api/tenant/applications/{$app->id}");
        $show->assertStatus(200);
        $this->assertCount(1, $show->json('documents'));
        // Sensitive fields never leak.
        $this->assertArrayNotHasKey('stored_path', $show->json('documents.0'));
    }

    // =========================================================================
    // Landlord request-info → NEEDS_ACTION → resolve
    // =========================================================================

    public function test_landlord_can_request_info_moving_application_to_needs_action(): void
    {
        $app = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        $this->actingLandlord();

        $this->postJson("/api/landlord/applications/{$app->id}/request-info", [
            'type' => 'document_replacement',
            'document_type' => 'proof_of_income',
            'message' => 'Please upload a clearer proof of income.',
            'reason' => 'The uploaded document is too blurry to read.',
        ])->assertStatus(200)
            ->assertJsonPath('status', ApplicationStatus::NEEDS_ACTION->value);

        $this->assertDatabaseHas('application_requests', [
            'application_id' => $app->id,
            'type' => 'document_replacement',
            'resolved_at' => null,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenant->id,
            'type' => NotificationType::APPLICATION_NEEDS_ACTION->value,
        ]);
    }

    public function test_tenant_upload_resolves_request_and_returns_to_review(): void
    {
        Storage::fake('local');

        $app = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::NEEDS_ACTION,
        ]);

        $request = ApplicationRequest::create([
            'application_id' => $app->id,
            'requested_by_type' => (new User)->getMorphClass(),
            'requested_by_id' => $this->landlord->id,
            'requester_role' => 'landlord',
            'type' => 'document_replacement',
            'document_type' => 'proof_of_income',
            'message' => 'Please upload a clearer proof of income.',
        ]);

        $this->actingTenant();

        $this->postJson("/api/tenant/applications/{$app->id}/documents", [
            'file' => UploadedFile::fake()->create('payslip-clear.pdf', 120, 'application/pdf'),
            'document_type' => 'proof_of_income',
        ])->assertStatus(201);

        $this->assertDatabaseHas('application_requests', [
            'id' => $request->id,
        ]);
        $this->assertNotNull($request->fresh()->resolved_at);

        $app->refresh();
        $this->assertSame(ApplicationStatus::IN_REVIEW, $app->status);

        // Landlord is told the tenant responded.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->landlord->id,
            'type' => NotificationType::APPLICATION_UPDATED->value,
        ]);
    }

    public function test_tenant_cannot_request_info_on_their_own_application(): void
    {
        $app = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        $this->actingTenant();

        // Tenants have no landlord routes; the endpoint is landlord-guarded.
        $this->postJson("/api/landlord/applications/{$app->id}/request-info", [
            'message' => 'trying to escalate',
        ])->assertStatus(403);
    }

    // =========================================================================
    // Timeline + landlord opening
    // =========================================================================

    public function test_landlord_opening_submitted_application_moves_to_in_review(): void
    {
        $app = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
        ]);

        $this->actingLandlord();

        $this->getJson("/api/landlord/applications/{$app->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', ApplicationStatus::IN_REVIEW->value);

        $this->assertDatabaseHas('application_events', [
            'application_id' => $app->id,
            'event' => 'opened',
        ]);
    }

    public function test_show_returns_timeline_documents_and_requests_without_leaking_landlord_notes(): void
    {
        $app = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'status' => ApplicationStatus::SUBMITTED,
            'landlord_notes' => 'internal only',
        ]);

        // Seed a timeline event.
        app(\App\Services\ApplicationService::class)
            ->recordEvent($app, 'submitted', 'Application submitted to landlord', $this->tenant);

        $this->actingTenant();

        $show = $this->getJson("/api/tenant/applications/{$app->id}");
        $show->assertStatus(200)
            ->assertJsonStructure(['id', 'status', 'events', 'documents', 'requests']);

        $this->assertArrayNotHasKey('landlord_notes', $show->json());
        $this->assertNotEmpty($show->json('events'));
    }
}
