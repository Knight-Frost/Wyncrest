<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\VerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddVerificationNoteRequest;
use App\Http\Requests\Admin\ApproveVerificationRequest;
use App\Http\Requests\Admin\RejectVerificationRequest;
use App\Http\Requests\Admin\RequestMoreInfoVerificationRequest;
use App\Models\Document;
use App\Models\VerificationRequest;
use App\Services\AuditService;
use App\Services\VerificationCaseService;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminVerificationController extends Controller
{
    public function __construct(
        protected VerificationService $verificationService,
        protected VerificationCaseService $caseService,
    ) {}

    /**
     * List verification requests (paginated, filterable, sortable).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,under_review,approved,rejected,needs_more_information'],
            'role' => ['sometimes', 'string', 'in:tenant,landlord'],
            'search' => ['sometimes', 'string', 'max:255'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'needs_documents' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:newest,oldest,needs_attention_first'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json($this->caseService->paginate($filters));
    }

    /**
     * Truthful counts for the queue's summary cards.
     */
    public function summary(): JsonResponse
    {
        return response()->json($this->caseService->summary());
    }

    /**
     * Full case-file payload for the detail page: applicant profile,
     * documents, computed checklist/warnings, history, previous attempts,
     * and internal notes.
     */
    public function show(VerificationRequest $verificationRequest): JsonResponse
    {
        return response()->json($this->caseService->caseDetail($verificationRequest));
    }

    /**
     * Approve a verification request.
     */
    public function approve(ApproveVerificationRequest $request, VerificationRequest $verificationRequest): JsonResponse
    {
        try {
            $req = $this->verificationService->approve(
                req: $verificationRequest,
                admin: $request->user(),
                reason: $request->validated('reason')
            );
        } catch (VerificationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Verification request approved.',
            'verification_request' => $req,
        ]);
    }

    /**
     * Reject a verification request.
     */
    public function reject(RejectVerificationRequest $request, VerificationRequest $verificationRequest): JsonResponse
    {
        try {
            $req = $this->verificationService->reject(
                req: $verificationRequest,
                admin: $request->user(),
                reason: $request->validated('reason')
            );
        } catch (VerificationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Verification request rejected.',
            'verification_request' => $req,
        ]);
    }

    /**
     * Request more information for a verification request.
     */
    public function requestInfo(RequestMoreInfoVerificationRequest $request, VerificationRequest $verificationRequest): JsonResponse
    {
        try {
            $req = $this->verificationService->requestMoreInfo(
                req: $verificationRequest,
                admin: $request->user(),
                note: $request->validated('note')
            );
        } catch (VerificationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Additional information requested.',
            'verification_request' => $req,
        ]);
    }

    /**
     * Add an internal, admin-only note to a verification request.
     */
    public function addNote(AddVerificationNoteRequest $request, VerificationRequest $verificationRequest): JsonResponse
    {
        $note = $this->caseService->addNote(
            req: $verificationRequest,
            admin: $request->user(),
            body: $request->validated('body')
        );

        return response()->json([
            'message' => 'Note added.',
            'note' => $note,
        ], 201);
    }

    /**
     * Stream a document for admin moderation review.
     *
     * SECURITY: this route lives behind the admin middleware group, so only an
     * authenticated admin reaches it. Admins (super-admins in the current phase)
     * may view applicant documents in the verification-moderation context; every
     * access is audited. The file is streamed directly — no public URL is created.
     * The frontend re-uses this same endpoint for inline preview by fetching it
     * as a blob and constructing an object URL, so no separate preview route is
     * needed.
     */
    public function downloadDocument(Request $request, Document $document): StreamedResponse|JsonResponse
    {
        abort_unless($document->related_type === VerificationRequest::class, 403, 'This document is not part of a verification review.');

        if (! Storage::disk($document->disk)->exists($document->stored_path)) {
            return response()->json(['message' => 'File not found on disk.'], 404);
        }

        app(AuditService::class)->log(
            actor: $request->user(),
            action: 'admin_document_viewed',
            subject: $document,
            description: 'Admin viewed a document during moderation',
            metadata: ['document_type' => $document->document_type->value],
            severity: 'warning'
        );

        return Storage::disk($document->disk)->download(
            $document->stored_path,
            $document->original_filename
        );
    }
}
