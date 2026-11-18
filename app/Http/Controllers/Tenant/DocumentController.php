<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DocumentController (Tenant)
 *
 * Handles secure document upload, listing, download, and deletion for tenants.
 *
 * SECURITY:
 * - Files are stored on the PRIVATE 'local' disk (storage/app), never 'public'.
 * - stored_path / disk are in Document::$hidden — they never appear in JSON.
 * - Downloads are gated by DocumentPolicy::view(); only the owner (or uploader)
 *   may retrieve a file.  The file is streamed directly; no public URL is
 *   generated.
 * - Queries use Document::where('owner_user_id', ...) directly rather than a
 *   relationship to avoid implicit data leakage via model scopes.
 */
class DocumentController extends Controller
{
    /**
     * List all documents owned by the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $documents = Document::where('owner_user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($documents);
    }

    /**
     * Upload and store a new document.
     *
     * Path format: documents/{owner_user_id}/{uuid}.{ext}
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            'documents/'.$user->id,
            (string) Str::uuid().'.'.$ext,
            'local'
        );

        $document = Document::create([
            'owner_user_id' => $user->id,
            'uploaded_by_id' => $user->id,
            'document_type' => $validated['document_type'],
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        // Audit: log only non-sensitive metadata, never the stored path or file contents
        app(AuditService::class)->log(
            actor: $user,
            action: 'document_uploaded',
            subject: $document,
            description: 'Document uploaded by tenant',
            metadata: ['document_type' => $document->document_type->value],
            severity: 'info'
        );

        // stored_path and disk are stripped from the response by $hidden
        return response()->json($document, 201);
    }

    /**
     * Stream (download) a document after authorisation.
     *
     * SECURITY: policy check runs before any file access.
     * No signed URL or public path is generated — the file is piped directly
     * through this endpoint.
     */
    public function download(Request $request, Document $document): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $document);

        if (! Storage::disk($document->disk)->exists($document->stored_path)) {
            return response()->json(['message' => 'File not found on disk.'], 404);
        }

        app(AuditService::class)->log(
            actor: $request->user(),
            action: 'document_downloaded',
            subject: $document,
            description: 'Document downloaded',
            severity: 'info'
        );

        return Storage::disk($document->disk)->download(
            $document->stored_path,
            $document->original_filename
        );
    }

    /**
     * Soft-delete a document and remove its file from disk.
     *
     * Both steps happen: physical file removal first, then the soft-delete so
     * the audit trail is preserved even if something goes wrong during deletion.
     */
    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        // WYN-012: evidence that an open or approved verification case, or a
        // submitted application, relies on is kept. The refusal is audited so
        // staff can see attempted removals of evidence.
        if ($reason = $document->deletion_blocked_reason) {
            app(AuditService::class)->log(
                actor: $request->user(),
                action: 'document_delete_refused',
                subject: $document,
                description: 'Owner tried to delete a document that a verification case or application still relies on',
                metadata: [
                    'document_type' => $document->document_type->value,
                    'related_type' => class_basename((string) $document->related_type),
                    'related_id' => $document->related_id,
                ],
                severity: 'warning'
            );

            return response()->json(['message' => $reason, 'code' => 'document_retained'], 409);
        }

        // Remove the physical file from private storage
        Storage::disk($document->disk)->delete($document->stored_path);

        // Soft-delete the model (preserves the audit row)
        $document->delete();

        app(AuditService::class)->log(
            actor: $request->user(),
            action: 'document_deleted',
            subject: $document,
            description: 'Document deleted by owner',
            metadata: ['document_type' => $document->document_type->value],
            severity: 'info'
        );

        return response()->json(['message' => 'Document deleted successfully.']);
    }
}
