<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminCapability;
use App\Models\Admin;
use App\Models\Document;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * VerificationCaseReviewTest
 *
 * Covers the case-review workflow layered on top of the base verification
 * lifecycle (see VerificationTest): the queue summary, filters/sort, the
 * computed detail payload (checklist/warnings/history/previous attempts),
 * internal notes, status-transition + document/account guards, and capability
 * enforcement on the new routes.
 */
class VerificationCaseReviewTest extends TestCase
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

    /**
     * Matches what VerificationService::submit() now does for real uploads:
     * a document only counts as "on this case" once it's linked via
     * related_type/related_id, since documents are uploaded through the
     * generic /documents endpoint before a request exists.
     */
    protected function createIdentityDoc(User $user, ?VerificationRequest $req = null): Document
    {
        return Document::create([
            'owner_user_id' => $user->id,
            'uploaded_by_id' => $user->id,
            'document_type' => 'identity_document',
            'original_filename' => 'id.pdf',
            'stored_path' => 'docs/id.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
            'related_type' => $req ? VerificationRequest::class : null,
            'related_id' => $req?->id,
        ]);
    }

    // =========================================================================
    // Summary
    // =========================================================================

    public function test_summary_counts_are_truthful(): void
    {
        $tenantReq = VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        $this->createIdentityDoc($this->tenant, $tenantReq);

        $rejectedUser = User::factory()->tenant()->create();
        VerificationRequest::create([
            'user_id' => $rejectedUser->id,
            'status' => 'rejected',
            'submitted_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
            'decision_reason' => 'No match.',
        ]);

        // A second, currently-pending request for the same (previously rejected) user.
        VerificationRequest::create([
            'user_id' => $rejectedUser->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson('/api/admin/verifications/summary');

        $response->assertStatus(200)->assertJson([
            'pending' => 2,
            'rejected' => 1,
            'missing_documents' => 1, // the rejected user's new pending request has no doc
            'previously_rejected_now_active' => 1,
        ]);
    }

    // =========================================================================
    // Queue filters
    // =========================================================================

    public function test_queue_filters_by_role(): void
    {
        VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);
        VerificationRequest::create(['user_id' => $this->landlord->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson('/api/admin/verifications?role=landlord');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('user_id')->all();
        $this->assertEquals([$this->landlord->id], $ids);
    }

    public function test_queue_search_matches_email(): void
    {
        $this->tenant->update(['email' => 'findme@example.test']);
        VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);
        VerificationRequest::create(['user_id' => $this->landlord->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson('/api/admin/verifications?search=findme');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('user_id')->all();
        $this->assertEquals([$this->tenant->id], $ids);
    }

    public function test_queue_needs_documents_filter(): void
    {
        $tenantReq = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);
        $this->createIdentityDoc($this->tenant, $tenantReq);
        VerificationRequest::create(['user_id' => $this->landlord->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson('/api/admin/verifications?needs_documents=1');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('user_id')->all();
        $this->assertEquals([$this->landlord->id], $ids);
    }

    // =========================================================================
    // Document linkage (real submit() flow, not directly-created rows)
    // =========================================================================

    /**
     * Documents are uploaded via the generic /documents endpoint before a
     * verification request exists, so nothing else associates them to a case.
     * submit() must link the applicant's not-yet-linked documents to the new
     * request, or the admin case-review page would show "no documents" for
     * every real submission.
     */
    public function test_submit_links_existing_documents_to_the_new_request(): void
    {
        $this->createIdentityDoc($this->tenant); // uploaded before any request exists

        Sanctum::actingAs($this->tenant, [], 'sanctum');
        $submitResponse = $this->postJson('/api/tenant/verification/submit', []);
        $submitResponse->assertStatus(201);

        $req = VerificationRequest::find($submitResponse->json('verification_request.id'));

        $this->actingAs($this->admin, 'admin');
        $detail = $this->getJson("/api/admin/verifications/{$req->id}");
        $detail->assertStatus(200);
        $this->assertCount(1, $detail->json('documents'));
        $this->assertEmpty($detail->json('warnings'));
    }

    // =========================================================================
    // Detail payload
    // =========================================================================

    public function test_detail_checklist_flags_missing_identity_document(): void
    {
        $req = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson("/api/admin/verifications/{$req->id}");

        $response->assertStatus(200);
        $checklist = collect($response->json('checklist'));
        $idCheck = $checklist->firstWhere('key', 'identity_document_submitted');
        $this->assertSame('failed', $idCheck['result']);

        $this->assertContains(
            'No documents were submitted with this request.',
            $response->json('warnings')
        );
    }

    public function test_detail_reports_no_warnings_when_case_is_clean(): void
    {
        $req = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);
        $this->createIdentityDoc($this->tenant, $req);

        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson("/api/admin/verifications/{$req->id}");

        $response->assertStatus(200)->assertJson(['warnings' => []]);
    }

    public function test_detail_includes_previous_attempts_and_history(): void
    {
        $rejected = VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'rejected',
            'submitted_at' => now()->subDays(5),
            'reviewed_by_admin_id' => $this->admin->id,
            'reviewed_at' => now()->subDays(4),
            'decision_reason' => 'Blurry ID.',
        ]);
        $current = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);
        $this->createIdentityDoc($this->tenant, $current);

        $this->actingAs($this->admin, 'admin');
        $response = $this->getJson("/api/admin/verifications/{$current->id}");

        $response->assertStatus(200);
        $previous = collect($response->json('previous_attempts'));
        $this->assertCount(1, $previous);
        $this->assertSame($rejected->id, $previous->first()['id']);
    }

    // =========================================================================
    // Internal notes
    // =========================================================================

    public function test_admin_can_add_and_see_internal_note(): void
    {
        $req = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/verifications/{$req->id}/notes", [
            'body' => 'Called the applicant to confirm details.',
        ])->assertStatus(201);

        $response = $this->getJson("/api/admin/verifications/{$req->id}");
        $notes = collect($response->json('notes'));
        $this->assertCount(1, $notes);
        $this->assertSame('Called the applicant to confirm details.', $notes->first()['body']);

        $history = collect($response->json('history'));
        $this->assertTrue($history->contains(fn ($h) => $h['action'] === 'verification_note_added'));
    }

    public function test_note_requires_body(): void
    {
        $req = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin, 'admin');
        $this->postJson("/api/admin/verifications/{$req->id}/notes", [])
            ->assertStatus(422);
    }

    // =========================================================================
    // Status-transition / document / account guards
    // =========================================================================

    public function test_cannot_re_decide_an_already_approved_request(): void
    {
        $this->createIdentityDoc($this->tenant);
        $req = VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'approved',
            'submitted_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
            'reviewed_by_admin_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/verifications/{$req->id}/reject", [
            'reason' => 'Changed my mind.',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('verification_requests', ['id' => $req->id, 'status' => 'approved']);
    }

    public function test_cannot_approve_without_identity_document(): void
    {
        $req = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/verifications/{$req->id}/approve", ['reason' => 'Looks fine.']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('verification_requests', ['id' => $req->id, 'status' => 'pending']);
    }

    public function test_cannot_approve_when_account_is_not_active(): void
    {
        $this->createIdentityDoc($this->tenant);
        $this->tenant->update(['account_status' => 'suspended']);
        $req = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin, 'admin');
        $response = $this->postJson("/api/admin/verifications/{$req->id}/approve", ['reason' => 'Looks fine.']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('verification_requests', ['id' => $req->id, 'status' => 'pending']);
    }

    // =========================================================================
    // Capability enforcement
    // =========================================================================

    public function test_admin_without_review_verifications_capability_is_forbidden(): void
    {
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => [AdminCapability::MANAGE_USERS->value]]);
        $req = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($scoped, 'admin');

        $this->getJson('/api/admin/verifications/summary')->assertStatus(403);
        $this->getJson("/api/admin/verifications/{$req->id}")->assertStatus(403);
        $this->postJson("/api/admin/verifications/{$req->id}/notes", ['body' => 'x'])->assertStatus(403);
    }
}
