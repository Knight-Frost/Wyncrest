<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use App\Models\VerificationNote;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * VerificationCaseService
 *
 * Read model + case-review computations for the admin identity-verification
 * workflow: queue filtering/summary, and the full "case file" payload for the
 * detail page (checklist, warnings, history, previous attempts, notes).
 *
 * Every value here is derived from real columns/relations — nothing is
 * invented. Where the platform genuinely cannot answer a question (e.g.
 * document expiry, since no such field is captured), the checklist says so
 * explicitly rather than reporting a fabricated pass.
 *
 * Mutations (approve/reject/request-info/submit) stay in VerificationService;
 * this service only reads and — for internal notes — appends non-decisional
 * case context.
 */
class VerificationCaseService
{
    /** Document mime types the in-browser viewer can render inline. */
    private const PREVIEWABLE_MIME_TYPES = [
        'application/pdf', 'image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/gif',
    ];

    private const QUEUE_STATUSES = ['pending', 'under_review'];

    public function __construct(protected AuditService $auditService) {}

    /**
     * Truthful counts for the queue's summary cards.
     */
    public function summary(): array
    {
        $missingDocuments = (clone $this->queueBase())
            ->whereDoesntHave('documents', fn (Builder $q) => $q->where('document_type', 'identity_document'))
            ->count();

        $previouslyRejected = (clone $this->queueBase())
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('verification_requests as vr2')
                    ->whereColumn('vr2.user_id', 'verification_requests.user_id')
                    ->whereColumn('vr2.id', '<>', 'verification_requests.id')
                    ->where('vr2.status', 'rejected');
            })
            ->count();

        return [
            'pending' => VerificationRequest::whereIn('status', self::QUEUE_STATUSES)->count(),
            'needs_more_information' => VerificationRequest::where('status', 'needs_more_information')->count(),
            'verified' => VerificationRequest::where('status', 'approved')->count(),
            'rejected' => VerificationRequest::where('status', 'rejected')->count(),
            'missing_documents' => $missingDocuments,
            'previously_rejected_now_active' => $previouslyRejected,
            'pending_by_role' => $this->pendingByRole(),
            'oldest_pending' => $this->oldestPending(),
        ];
    }

    /**
     * Pending/under-review queue count split by applicant role, for the
     * dashboard attention-queue card ("3 tenants · 2 landlords").
     *
     * @return array{tenant:int,landlord:int}
     */
    protected function pendingByRole(): array
    {
        return [
            'tenant' => (clone $this->queueBase())
                ->whereHas('user', fn (Builder $q) => $q->where('user_type', 'tenant'))
                ->count(),
            'landlord' => (clone $this->queueBase())
                ->whereHas('user', fn (Builder $q) => $q->where('user_type', 'landlord'))
                ->count(),
        ];
    }

    /**
     * The longest-waiting pending/under-review request, for the dashboard
     * attention-queue card ("Oldest: Kofi Mensah, waiting 2 days").
     *
     * @return array{user_name:string,role:?string,submitted_at:?string,waiting_days:int}|null
     */
    protected function oldestPending(): ?array
    {
        $oldest = (clone $this->queueBase())
            ->with('user')
            ->orderBy('submitted_at', 'asc')
            ->first();

        if (! $oldest || ! $oldest->user) {
            return null;
        }

        return [
            'user_name' => $oldest->user->full_name,
            'role' => $oldest->user->user_type?->value,
            'submitted_at' => $oldest->submitted_at?->toIso8601String(),
            'waiting_days' => $oldest->submitted_at ? (int) abs(now()->diffInDays($oldest->submitted_at)) : 0,
        ];
    }

    /**
     * Review-speed metrics for the Super Admin Analytics page: how long a
     * decision takes once submitted, and how many queued cases have aged
     * past the platform's 72-hour review expectation.
     */
    public function reviewTimingMetrics(): array
    {
        $decided = VerificationRequest::query()
            ->whereIn('status', ['approved', 'rejected'])
            ->whereNotNull('submitted_at')
            ->whereNotNull('reviewed_at')
            ->get();

        $hours = $decided->map(fn (VerificationRequest $r) => $r->submitted_at->floatDiffInHours($r->reviewed_at));

        return [
            'average_review_time_hours' => $hours->isNotEmpty() ? round($hours->avg(), 2) : 0.0,
            'overdue_count' => (clone $this->queueBase())
                ->whereNotNull('submitted_at')
                ->where('submitted_at', '<=', now()->subHours(72))
                ->count(),
        ];
    }

    protected function queueBase(): Builder
    {
        return VerificationRequest::whereIn('status', self::QUEUE_STATUSES);
    }

    /**
     * Filtered, paginated queue.
     *
     * @param  array{status?:string,role?:string,search?:string,from_date?:string,to_date?:string,needs_documents?:bool,sort?:string,page?:int}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = VerificationRequest::with(['user', 'reviewer'])
            ->withCount('documents');

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'pending') {
                $query->whereIn('status', self::QUEUE_STATUSES);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (! empty($filters['role'])) {
            $query->whereHas('user', fn (Builder $q) => $q->where('user_type', $filters['role']));
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->whereHas('user', function (Builder $q) use ($term) {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        if (! empty($filters['from_date'])) {
            $query->where('submitted_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->where('submitted_at', '<=', $filters['to_date']);
        }

        if (! empty($filters['needs_documents'])) {
            $query->whereDoesntHave('documents', fn (Builder $q) => $q->where('document_type', 'identity_document'));
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('submitted_at', 'asc'),
            'needs_attention_first' => $query
                ->orderByRaw(
                    '(CASE WHEN NOT EXISTS ('.
                    'SELECT 1 FROM documents WHERE documents.owner_user_id = verification_requests.user_id '.
                    "AND documents.document_type = 'identity_document' AND documents.deleted_at IS NULL"
                    .') THEN 0 ELSE 1 END) asc'
                )
                ->orderBy('submitted_at', 'asc'),
            default => $query->orderByDesc('submitted_at'),
        };

        $paginator = $query->paginate(20, page: $filters['page'] ?? 1);
        // full_name/initials/avatar_url are accessors, not auto-serialized —
        // append them so the queue shows real applicant names, not a fallback.
        $paginator->getCollection()->each(function (VerificationRequest $req) {
            $req->user?->append(['full_name', 'initials']);
        });

        return $paginator;
    }

    /**
     * Full case-file payload for the detail page.
     */
    public function caseDetail(VerificationRequest $req): array
    {
        $req->loadMissing(['user', 'reviewer', 'documents', 'notes.admin']);
        $user = $req->user;
        $user->append(['full_name', 'initials']);
        $isLandlord = $user->isLandlord();

        $documents = $req->documents;
        $hasIdentityDoc = $documents->contains(fn ($d) => $d->document_type->value === 'identity_document');
        $hasProofOfAddress = $documents->contains(fn ($d) => $d->document_type->value === 'proof_of_address');

        $previousAttempts = VerificationRequest::where('user_id', $user->id)
            ->where('id', '<>', $req->id)
            ->with('reviewer')
            ->orderByDesc('submitted_at')
            ->get();

        $hasPriorRejection = $previousAttempts->contains(fn ($r) => $r->status === 'rejected');

        $duplicatePhone = $user->phone
            ? User::where('phone', $user->phone)->where('id', '<>', $user->id)->exists()
            : false;

        $accountActive = $user->account_status === null || $user->account_status === AccountStatus::ACTIVE;

        $checklist = $this->buildChecklist(
            user: $user,
            isLandlord: $isLandlord,
            hasIdentityDoc: $hasIdentityDoc,
            hasProofOfAddress: $hasProofOfAddress,
            hasPriorRejection: $hasPriorRejection,
            duplicatePhone: $duplicatePhone,
            accountActive: $accountActive,
        );

        $warnings = $this->buildWarnings(
            documents: $documents,
            hasIdentityDoc: $hasIdentityDoc,
            hasPriorRejection: $hasPriorRejection,
            duplicatePhone: $duplicatePhone,
            accountActive: $accountActive,
            user: $user,
        );

        return [
            'id' => $req->id,
            'status' => $req->status,
            'note' => $req->note,
            'decision_reason' => $req->decision_reason,
            'submitted_at' => $req->submitted_at,
            'reviewed_at' => $req->reviewed_at,
            'created_at' => $req->created_at,
            'reviewable' => in_array($req->status, self::QUEUE_STATUSES, true),
            'reviewer' => $req->reviewer,
            'user' => $user,
            'documents' => $documents->values(),
            'checklist' => $checklist,
            'warnings' => $warnings,
            'history' => $this->buildHistory($req, $documents),
            'previous_attempts' => $previousAttempts->values(),
            'notes' => $req->notes->values(),
            'unlock_description' => $isLandlord
                ? "Approving this request marks {$user->full_name} as verified, which Wyncrest requires before a landlord can submit listings for review."
                : "Approving this request marks {$user->full_name} as verified, which Wyncrest requires before a tenant can submit rental applications.",
        ];
    }

    protected function buildChecklist(
        User $user,
        bool $isLandlord,
        bool $hasIdentityDoc,
        bool $hasProofOfAddress,
        bool $hasPriorRejection,
        bool $duplicatePhone,
        bool $accountActive,
    ): array {
        $items = [
            [
                'key' => 'email_present',
                'label' => 'Email on file',
                'result' => 'passed',
                'detail' => "{$user->email} is on file.",
                'required' => true,
                'role_scope' => 'all',
            ],
            [
                'key' => 'phone_present',
                'label' => 'Phone number on file',
                'result' => $user->phone ? 'passed' : 'warning',
                'detail' => $user->phone ? $user->phone : 'No phone number is on file for this applicant.',
                'required' => false,
                'role_scope' => 'all',
            ],
            [
                'key' => 'identity_document_submitted',
                'label' => 'Required identity document submitted',
                'result' => $hasIdentityDoc ? 'passed' : 'failed',
                'detail' => $hasIdentityDoc
                    ? 'An identity document has been submitted.'
                    : 'No identity document is on file. This request cannot be approved until one is submitted.',
                'required' => true,
                'role_scope' => 'all',
            ],
            [
                'key' => 'document_not_expired',
                'label' => 'Document is not expired',
                'result' => 'not_applicable',
                'detail' => 'Wyncrest does not currently capture document expiry dates.',
                'required' => false,
                'role_scope' => 'all',
            ],
            [
                'key' => 'proof_of_address_submitted',
                'label' => $isLandlord ? 'Proof of address submitted' : 'Proof of address submitted (recommended)',
                'result' => $hasProofOfAddress ? 'passed' : ($isLandlord ? 'failed' : 'warning'),
                'detail' => $hasProofOfAddress
                    ? 'A proof-of-address document has been submitted.'
                    : ($isLandlord
                        ? 'No proof of address is on file. Recommended before approving a landlord who manages a physical property.'
                        : 'No proof of address is on file. Not required for tenant verification, but recommended.'),
                'required' => $isLandlord,
                'role_scope' => 'all',
            ],
            [
                'key' => 'name_matches_document',
                'label' => 'Name matches submitted document',
                'result' => 'manual',
                'detail' => 'Manual check required — Wyncrest does not extract text from uploaded documents. Compare the document photo to the applicant\'s profile name.',
                'required' => true,
                'role_scope' => 'all',
            ],
            [
                'key' => 'no_duplicate_phone',
                'label' => 'No duplicate phone number detected',
                'result' => ! $user->phone ? 'not_applicable' : ($duplicatePhone ? 'warning' : 'passed'),
                'detail' => ! $user->phone
                    ? 'No phone number on file to check.'
                    : ($duplicatePhone
                        ? 'This phone number is also on file for another account.'
                        : 'No other account shares this phone number.'),
                'required' => false,
                'role_scope' => 'all',
            ],
            [
                'key' => 'no_open_prior_rejection',
                'label' => 'No unresolved prior rejection',
                'result' => $hasPriorRejection ? 'warning' : 'passed',
                'detail' => $hasPriorRejection
                    ? 'A previous verification request from this applicant was rejected. Review the reason before deciding again.'
                    : 'No prior rejected verification requests for this applicant.',
                'required' => false,
                'role_scope' => 'all',
            ],
            [
                'key' => 'account_active',
                'label' => 'Account is active',
                'result' => $accountActive ? 'passed' : 'failed',
                'detail' => $accountActive
                    ? 'The applicant\'s account is active.'
                    : "The applicant's account status is \"{$user->account_status?->value}\" — this request cannot be approved until the account is reactivated.",
                'required' => true,
                'role_scope' => 'all',
            ],
        ];

        return $items;
    }

    protected function buildWarnings($documents, bool $hasIdentityDoc, bool $hasPriorRejection, bool $duplicatePhone, bool $accountActive, User $user): array
    {
        $warnings = [];

        if ($documents->isEmpty()) {
            $warnings[] = 'No documents were submitted with this request.';
        } elseif (! $hasIdentityDoc) {
            $warnings[] = 'This request cannot be approved until a required identity document is submitted.';
        }

        if ($duplicatePhone) {
            $warnings[] = 'This applicant\'s phone number is also on file for another account.';
        }

        if ($hasPriorRejection) {
            $warnings[] = 'This applicant was previously rejected on an earlier verification request.';
        }

        if (! $accountActive) {
            $warnings[] = "This applicant's account is not active ({$user->account_status?->value}) — approval is blocked until it is reactivated.";
        }

        foreach ($documents as $doc) {
            if (! in_array($doc->mime_type, self::PREVIEWABLE_MIME_TYPES, true)) {
                $warnings[] = "The file \"{$doc->original_filename}\" has a type ({$doc->mime_type}) that may not preview in-browser — use Download instead.";
            }
        }

        return $warnings;
    }

    /**
     * Real audit-log events tied to this case: actions logged against the
     * request itself, verification_* actions logged against the applicant,
     * and admin document-view events for this case's documents.
     */
    protected function buildHistory(VerificationRequest $req, $documents): Collection
    {
        $documentIds = $documents->pluck('id')->all();

        return AuditLog::with(['actor'])
            ->where(function (Builder $q) use ($req, $documentIds) {
                $q->where(function (Builder $q2) use ($req) {
                    $q2->where('subject_type', VerificationRequest::class)->where('subject_id', $req->id);
                })->orWhere(function (Builder $q2) use ($req) {
                    $q2->where('subject_type', User::class)
                        ->where('subject_id', $req->user_id)
                        ->where('action', 'like', 'verification_%');
                });

                if (! empty($documentIds)) {
                    $q->orWhere(function (Builder $q2) use ($documentIds) {
                        $q2->where('subject_type', Document::class)
                            ->whereIn('subject_id', $documentIds)
                            ->where('action', 'admin_document_viewed');
                    });
                }
            })
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'severity' => $log->severity,
                'created_at' => $log->created_at,
                'actor' => $log->actor ? [
                    'type' => $log->actor instanceof Admin ? 'admin' : 'user',
                    'name' => $log->actor instanceof Admin ? $log->actor->name : $log->actor->full_name,
                ] : null,
                'metadata' => $log->metadata,
            ]);
    }

    public function addNote(VerificationRequest $req, Admin $admin, string $body): VerificationNote
    {
        $note = VerificationNote::create([
            'verification_request_id' => $req->id,
            'admin_id' => $admin->id,
            'body' => $body,
        ]);

        $this->auditService->log(
            actor: $admin,
            action: 'verification_note_added',
            subject: $req,
            description: "Internal note added to verification request for {$req->user->email}",
            severity: 'info'
        );

        return $note->load('admin');
    }
}
