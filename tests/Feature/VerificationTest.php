<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Document;
use App\Models\Feature;
use App\Models\LandlordFeature;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;

    protected User $landlord;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->tenant()->create();
        $this->landlord = User::factory()->landlord()->create();
        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
    }

    // Helper: create an identity document for a user
    protected function createIdentityDoc(User $user): void
    {
        Document::create([
            'owner_user_id' => $user->id,
            'uploaded_by_id' => $user->id,
            'document_type' => 'identity_document',
            'original_filename' => 'id.pdf',
            'stored_path' => 'docs/id.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
        ]);
    }

    // =========================================================================
    // Verification submission
    // =========================================================================

    public function test_tenant_cannot_submit_verification_without_identity_document(): void
    {
        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/verification/submit');

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'You must upload at least one identity document before submitting for verification.']);
    }

    public function test_tenant_can_submit_verification_with_identity_document(): void
    {
        $this->createIdentityDoc($this->tenant);
        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->postJson('/api/tenant/verification/submit', ['note' => 'Please verify me.']);

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'Verification request submitted successfully.']);

        $this->assertDatabaseHas('users', [
            'id' => $this->tenant->id,
            'verification_status' => 'pending',
        ]);

        $this->assertDatabaseHas('verification_requests', [
            'user_id' => $this->tenant->id,
            'status' => 'pending',
        ]);
    }

    public function test_cannot_submit_when_already_pending(): void
    {
        $this->createIdentityDoc($this->tenant);
        $this->tenant->update(['verification_status' => 'pending']);
        VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');
        $response = $this->postJson('/api/tenant/verification/submit');

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Your verification request is already being processed.']);
    }

    public function test_landlord_can_submit_verification(): void
    {
        $this->createIdentityDoc($this->landlord);
        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->postJson('/api/landlord/verification/submit');

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'id' => $this->landlord->id,
            'verification_status' => 'pending',
        ]);
    }

    public function test_tenant_can_get_verification_status(): void
    {
        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $response = $this->getJson('/api/tenant/verification');

        $response->assertStatus(200)
            ->assertJsonStructure(['verification_status', 'identity_verified', 'latest_request']);
    }

    // =========================================================================
    // Admin verification actions
    // =========================================================================

    public function test_admin_can_approve_verification(): void
    {
        $this->createIdentityDoc($this->tenant);
        $this->tenant->update(['verification_status' => 'pending']);
        $req = VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/verifications/{$req->id}/approve", [
            'reason' => 'Documents look good.',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Verification request approved.']);

        $this->assertDatabaseHas('users', [
            'id' => $this->tenant->id,
            'verification_status' => 'verified',
            'identity_verified' => 1,
        ]);

        $this->assertDatabaseHas('verification_requests', [
            'id' => $req->id,
            'status' => 'approved',
        ]);
    }

    public function test_admin_can_reject_verification(): void
    {
        $this->createIdentityDoc($this->tenant);
        $this->tenant->update(['verification_status' => 'pending']);
        $req = VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/verifications/{$req->id}/reject", [
            'reason' => 'Invalid documents provided.',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Verification request rejected.']);

        $this->assertDatabaseHas('users', [
            'id' => $this->tenant->id,
            'verification_status' => 'rejected',
        ]);
    }

    public function test_admin_can_request_more_info(): void
    {
        $this->tenant->update(['verification_status' => 'pending']);
        $req = VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/verifications/{$req->id}/request-info", [
            'note' => 'Please provide a clearer photo of your ID.',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Additional information requested.']);

        $this->assertDatabaseHas('users', [
            'id' => $this->tenant->id,
            'verification_status' => 'needs_more_information',
        ]);
    }

    public function test_admin_can_list_verifications(): void
    {
        VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson('/api/admin/verifications');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'current_page']);
    }

    // =========================================================================
    // Verification gates
    // =========================================================================

    public function test_unverified_landlord_cannot_submit_listing_for_review(): void
    {
        $listingsFeature = Feature::create([
            'key' => 'listings',
            'name' => 'Listings',
            'description' => 'Create listings',
            'requires_identity_verification' => false,
            'enabled_by_default' => true,
        ]);
        LandlordFeature::create([
            'landlord_id' => $this->landlord->id,
            'feature_id' => $listingsFeature->id,
            'enabled' => true,
            'enabled_by' => $this->admin->id,
            'enabled_at' => now(),
        ]);

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $response = $this->postJson("/api/landlord/listings/{$listing->id}/submit");

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'You must complete identity verification before submitting a listing for review.']);
    }

    public function test_verified_landlord_can_submit_listing_for_review(): void
    {
        $verifiedLandlord = User::factory()->landlord()->identityVerified()->create();

        $listingsFeature = Feature::create([
            'key' => 'listings',
            'name' => 'Listings',
            'description' => 'Create listings',
            'requires_identity_verification' => false,
            'enabled_by_default' => true,
        ]);
        LandlordFeature::create([
            'landlord_id' => $verifiedLandlord->id,
            'feature_id' => $listingsFeature->id,
            'enabled' => true,
            'enabled_by' => $this->admin->id,
            'enabled_at' => now(),
        ]);

        $property = Property::factory()->create(['landlord_id' => $verifiedLandlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $verifiedLandlord->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($verifiedLandlord, [], 'sanctum');
        $response = $this->postJson("/api/landlord/listings/{$listing->id}/submit");

        $response->assertStatus(200);
    }

    public function test_unverified_tenant_cannot_apply_to_listing(): void
    {
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');
        $response = $this->postJson('/api/tenant/applications', [
            'listing_id' => $listing->id,
            'cover_note' => 'I would like to apply.',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'You must complete identity verification before applying to a listing.']);
    }

    public function test_verified_tenant_can_apply_to_listing(): void
    {
        $verifiedTenant = User::factory()->tenant()->identityVerified()->create();

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($verifiedTenant, [], 'sanctum');
        $response = $this->postJson('/api/tenant/applications', [
            'listing_id' => $listing->id,
            'cover_note' => 'I would like to apply.',
        ]);

        $response->assertStatus(201);
    }

    // =========================================================================
    // Account governance
    // =========================================================================

    public function test_admin_can_block_user(): void
    {
        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/users/{$this->tenant->id}/block", [
            'reason' => 'Fraudulent activity detected.',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'User blocked.']);

        $this->assertDatabaseHas('users', [
            'id' => $this->tenant->id,
            'account_status' => 'blocked',
            'is_active' => 0,
        ]);
    }

    public function test_admin_can_archive_user(): void
    {
        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/users/{$this->tenant->id}/archive", [
            'reason' => 'Account requested for closure.',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'User archived.']);

        $this->assertDatabaseHas('users', [
            'id' => $this->tenant->id,
            'account_status' => 'archived',
            'is_active' => 0,
        ]);

        // Soft deleted
        $this->assertSoftDeleted('users', ['id' => $this->tenant->id]);
    }

    public function test_blocked_tenant_is_rejected_by_middleware(): void
    {
        $this->tenant->update([
            'account_status' => 'blocked',
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->tenant, [], 'sanctum');
        $response = $this->getJson('/api/tenant/dashboard');

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account has been blocked.']);
    }

    public function test_archived_landlord_is_rejected_by_middleware(): void
    {
        $this->landlord->update([
            'account_status' => 'archived',
            'is_active' => false,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');
        $response = $this->getJson('/api/landlord/dashboard');

        $response->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account has been archived.']);
    }

    public function test_activate_restores_user_to_active(): void
    {
        $this->tenant->update([
            'account_status' => 'blocked',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/users/{$this->tenant->id}/activate");

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->tenant->id,
            'account_status' => 'active',
            'is_active' => 1,
        ]);
    }

    // =========================================================================
    // Admin document download (moderation context)
    // =========================================================================

    public function test_admin_can_download_an_applicant_document_for_moderation(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('docs/id.pdf', 'fake-pdf-bytes');

        $verificationRequest = VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $doc = Document::create([
            'owner_user_id' => $this->tenant->id,
            'uploaded_by_id' => $this->tenant->id,
            'document_type' => 'identity_document',
            'original_filename' => 'id.pdf',
            'stored_path' => 'docs/id.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 14,
            // Only documents stamped to a verification context are
            // downloadable via this endpoint (see AdminVerificationDocumentAccessTest).
            'related_type' => VerificationRequest::class,
            'related_id' => $verificationRequest->id,
        ]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->get("/api/admin/documents/{$doc->id}/download");

        $response->assertStatus(200);
        // The access is audited.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin_document_viewed',
            'subject_id' => $doc->id,
        ]);
    }

    public function test_non_admin_cannot_reach_the_admin_document_download_route(): void
    {
        $doc = Document::create([
            'owner_user_id' => $this->tenant->id,
            'uploaded_by_id' => $this->tenant->id,
            'document_type' => 'identity_document',
            'original_filename' => 'id.pdf',
            'stored_path' => 'docs/id.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 14,
        ]);

        // A tenant bearer identity is unauthenticated on the admin session guard.
        // (Non-JSON GET still resolves to a clean 401, not a redirect crash.)
        Sanctum::actingAs($this->tenant, [], 'sanctum');
        $this->get("/api/admin/documents/{$doc->id}/download")->assertStatus(401);
    }
}
