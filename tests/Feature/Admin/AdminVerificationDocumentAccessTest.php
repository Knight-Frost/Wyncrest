<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AdminVerificationDocumentAccessTest
 *
 * Regression coverage for C1: AdminVerificationController::downloadDocument()
 * previously streamed ANY Document by route-model binding with no check that
 * it belonged to a verification context. A scoped admin with only
 * review_verifications could therefore pull identity scans, income proof,
 * application attachments, or property documents indiscriminately. The fix
 * restricts the endpoint to documents stamped related_type =
 * VerificationRequest::class (the only shape VerificationService::submit()
 * ever produces).
 */
class AdminVerificationDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $reviewerAdmin;

    protected User $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = User::factory()->tenant()->create();
        $this->reviewerAdmin = Admin::factory()->create([
            'is_super_admin' => false,
            'capabilities' => ['review_verifications'],
        ]);
    }

    protected function fakeStoredDocument(): void
    {
        Storage::disk('local')->put('docs/id.pdf', 'fake-pdf-bytes');
    }

    public function test_scoped_admin_can_download_a_document_attached_to_a_verification_request(): void
    {
        $this->fakeStoredDocument();

        $verificationRequest = VerificationRequest::create([
            'user_id' => $this->tenant->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $document = Document::create([
            'owner_user_id' => $this->tenant->id,
            'uploaded_by_id' => $this->tenant->id,
            'document_type' => 'identity_document',
            'original_filename' => 'id.pdf',
            'stored_path' => 'docs/id.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
            'related_type' => VerificationRequest::class,
            'related_id' => $verificationRequest->id,
        ]);

        $this->actingAs($this->reviewerAdmin, 'admin');

        $response = $this->get("/api/admin/documents/{$document->id}/download");

        $response->assertOk();
    }

    public function test_scoped_admin_cannot_download_a_document_attached_to_an_application(): void
    {
        $this->fakeStoredDocument();

        $application = Application::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $document = Document::create([
            'owner_user_id' => $this->tenant->id,
            'uploaded_by_id' => $this->tenant->id,
            'document_type' => 'identity_document',
            'original_filename' => 'id.pdf',
            'stored_path' => 'docs/id.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
            'related_type' => Application::class,
            'related_id' => $application->id,
        ]);

        $this->actingAs($this->reviewerAdmin, 'admin');

        $response = $this->get("/api/admin/documents/{$document->id}/download");

        $response->assertStatus(403);
    }

    public function test_scoped_admin_cannot_download_a_document_with_no_related_context(): void
    {
        $this->fakeStoredDocument();

        $document = Document::create([
            'owner_user_id' => $this->tenant->id,
            'uploaded_by_id' => $this->tenant->id,
            'document_type' => 'identity_document',
            'original_filename' => 'id.pdf',
            'stored_path' => 'docs/id.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
            'related_type' => null,
            'related_id' => null,
        ]);

        $this->actingAs($this->reviewerAdmin, 'admin');

        $response = $this->get("/api/admin/documents/{$document->id}/download");

        $response->assertStatus(403);
    }
}
