<?php

namespace Tests\Feature\Documents;

use App\Enums\ApplicationStatus;
use App\Models\Admin;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WYN-012: evidence a verification case or a submitted application relies on
 * cannot be permanently deleted by its owner; unsubmitted uploads can. Staff
 * access is unaffected. Synthetic files only.
 */
class DocumentRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = User::factory()->tenant()->create();
    }

    private function upload(string $type = 'identity_document'): Document
    {
        $id = $this->actingAs($this->tenant, 'sanctum')->postJson('/api/tenant/documents', [
            'document_type' => $type,
            'file' => UploadedFile::fake()->create('synthetic-id.pdf', 40, 'application/pdf'),
        ])->assertCreated()->json('id');

        return Document::findOrFail($id);
    }

    private function link(Document $doc, string $type, int|string $id): Document
    {
        $doc->forceFill(['related_type' => $type, 'related_id' => $id])->save();

        return $doc->fresh();
    }

    public function test_unsubmitted_upload_can_be_deleted(): void
    {
        $doc = $this->upload();
        $this->assertNull($doc->deletion_blocked_reason);

        $this->actingAs($this->tenant, 'sanctum')->deleteJson("/api/tenant/documents/{$doc->id}")->assertOk();
        $this->assertSoftDeleted('documents', ['id' => $doc->id]);
        Storage::disk('local')->assertMissing($doc->stored_path);
    }

    public function test_evidence_on_pending_or_approved_verification_is_kept_and_attempt_audited(): void
    {
        foreach (['pending', 'under_review', 'needs_more_information', 'approved'] as $status) {
            $case = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => $status, 'submitted_at' => now()]);
            $doc = $this->link($this->upload(), VerificationRequest::class, $case->id);

            $this->actingAs($this->tenant, 'sanctum')->getJson('/api/tenant/documents')->assertOk()
                ->assertJsonFragment(['id' => $doc->id, 'deletion_blocked_reason' => $doc->deletion_blocked_reason]);
            $this->actingAs($this->tenant, 'sanctum')->deleteJson("/api/tenant/documents/{$doc->id}")
                ->assertStatus(409)->assertJsonPath('code', 'document_retained');

            $this->assertNotSoftDeleted('documents', ['id' => $doc->id]);
            Storage::disk('local')->assertExists($doc->stored_path);
        }
        $this->assertSame(4, AuditLog::where('action', 'document_delete_refused')->count());

        // Staff with review_verifications can still open the evidence.
        $reviewer = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['review_verifications']]);
        $this->actingAs($reviewer, 'admin')->get("/api/admin/documents/{$doc->id}/download")->assertOk();
    }

    public function test_rejected_case_evidence_becomes_removable(): void
    {
        $case = VerificationRequest::create(['user_id' => $this->tenant->id, 'status' => 'rejected', 'submitted_at' => now()]);
        $doc = $this->link($this->upload(), VerificationRequest::class, $case->id);

        $this->actingAs($this->tenant, 'sanctum')->deleteJson("/api/tenant/documents/{$doc->id}")->assertOk();
    }

    public function test_documents_on_submitted_application_are_kept_until_withdrawn(): void
    {
        $application = Application::factory()->create(['tenant_id' => $this->tenant->id, 'status' => ApplicationStatus::SUBMITTED]);
        $doc = $this->link($this->upload('proof_of_income'), Application::class, $application->id);

        $this->actingAs($this->tenant, 'sanctum')->deleteJson("/api/tenant/documents/{$doc->id}")->assertStatus(409);

        $application->forceFill(['status' => ApplicationStatus::WITHDRAWN])->save();
        $this->actingAs($this->tenant, 'sanctum')->deleteJson("/api/tenant/documents/{$doc->id}")->assertOk();
    }

    public function test_draft_application_documents_are_removable(): void
    {
        $application = Application::factory()->create(['tenant_id' => $this->tenant->id, 'status' => ApplicationStatus::DRAFT]);
        $doc = $this->link($this->upload('proof_of_income'), Application::class, $application->id);

        $this->actingAs($this->tenant, 'sanctum')->deleteJson("/api/tenant/documents/{$doc->id}")->assertOk();
    }

    public function test_other_users_cannot_delete_or_download(): void
    {
        $doc = $this->upload();
        $stranger = User::factory()->tenant()->create();

        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/tenant/documents/{$doc->id}")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->get("/api/tenant/documents/{$doc->id}/download")->assertForbidden();
    }
}
