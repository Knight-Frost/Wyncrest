<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\NotificationType;
use App\Enums\VerificationStatus;
use App\Events\IdentityVerified;
use App\Exceptions\VerificationException;
use App\Models\Admin;
use App\Models\User;
use App\Models\VerificationRequest;

class VerificationService
{
    public function __construct(
        protected AuditService $auditService,
        protected NotificationService $notificationService
    ) {}

    public function submit(User $user, ?string $note): VerificationRequest
    {
        // Must have at least one identity document
        $hasIdDoc = $user->documents()
            ->where('document_type', 'identity_document')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasIdDoc) {
            throw new VerificationException(
                'You must upload at least one identity document before submitting for verification.'
            );
        }

        // Cannot submit when already pending/under review
        if (in_array($user->verification_status?->value, ['pending', 'under_review'])) {
            throw new VerificationException(
                'Your verification request is already being processed.'
            );
        }

        $req = VerificationRequest::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'note' => $note,
            'submitted_at' => now(),
        ]);

        // Documents are uploaded via the generic /documents endpoint before a
        // request exists, so nothing else associates them to a case. Attach the
        // applicant's not-yet-linked identity/proof-of-address documents here so
        // the admin case-review page shows what was actually submitted, instead
        // of an empty list. Documents already linked to an earlier (e.g.
        // rejected) request are left alone, preserving per-attempt history.
        $user->documents()
            ->whereIn('document_type', ['identity_document', 'proof_of_address'])
            ->whereNull('related_id')
            ->update(['related_type' => VerificationRequest::class, 'related_id' => $req->id]);

        $user->update(['verification_status' => VerificationStatus::PENDING->value]);

        $this->auditService->log(
            actor: $user,
            action: 'verification_submitted',
            subject: $req,
            description: "User submitted verification request: {$user->email}",
            severity: 'info'
        );

        $eventId = "verification-submitted:{$req->id}";
        if (! $this->notificationService->exists($user, $eventId)) {
            $this->notificationService->create(
                user: $user,
                type: NotificationType::VERIFICATION_SUBMITTED,
                title: 'Verification Request Submitted',
                message: 'Your identity verification request has been submitted and is under review.',
                data: ['event_id' => $eventId, 'verification_request_id' => $req->id]
            );
        }

        return $req;
    }

    /**
     * A verification request can only be decided while it is queued for review.
     * Re-deciding an already-approved/rejected/needs-info request would silently
     * overwrite a prior decision and its audit trail context.
     */
    protected function assertReviewable(VerificationRequest $req): void
    {
        if (! in_array($req->status, ['pending', 'under_review'], true)) {
            throw new VerificationException(
                'This verification request has already been decided and cannot be reviewed again.'
            );
        }
    }

    public function approve(VerificationRequest $req, Admin $admin, ?string $reason): VerificationRequest
    {
        $this->assertReviewable($req);

        $user = $req->user;

        $hasIdDoc = $user->documents()
            ->where('document_type', 'identity_document')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasIdDoc) {
            throw new VerificationException(
                'This request cannot be approved: no identity document is on file for this applicant.'
            );
        }

        if ($user->account_status !== null && $user->account_status !== AccountStatus::ACTIVE) {
            throw new VerificationException(
                'This request cannot be approved: the applicant\'s account is not active.'
            );
        }

        $req->update([
            'status' => 'approved',
            'reviewed_by_admin_id' => $admin->id,
            'reviewed_at' => now(),
            'decision_reason' => $reason,
        ]);

        $user->update([
            'verification_status' => VerificationStatus::VERIFIED->value,
            'identity_verified' => true,
            'identity_verified_at' => now(),
            'identity_verified_by' => $admin->id,
        ]);

        event(new IdentityVerified($user));

        $this->auditService->log(
            actor: $admin,
            action: 'verification_approved',
            subject: $user,
            description: "Identity verification approved for: {$user->email}",
            severity: 'warning'
        );

        $eventId = "verification-approved:{$req->id}";
        if (! $this->notificationService->exists($user, $eventId)) {
            $this->notificationService->create(
                user: $user,
                type: NotificationType::VERIFICATION_APPROVED,
                title: 'Identity Verification Approved',
                message: 'Your identity has been verified. You now have full access to the platform.',
                data: ['event_id' => $eventId, 'verification_request_id' => $req->id]
            );
        }

        return $req->fresh();
    }

    public function reject(VerificationRequest $req, Admin $admin, string $reason): VerificationRequest
    {
        $this->assertReviewable($req);

        $req->update([
            'status' => 'rejected',
            'reviewed_by_admin_id' => $admin->id,
            'reviewed_at' => now(),
            'decision_reason' => $reason,
        ]);

        $user = $req->user;
        $user->update(['verification_status' => VerificationStatus::REJECTED->value]);

        $this->auditService->log(
            actor: $admin,
            action: 'verification_rejected',
            subject: $user,
            description: "Identity verification rejected for: {$user->email}",
            metadata: ['reason' => $reason],
            severity: 'warning'
        );

        $eventId = "verification-rejected:{$req->id}";
        if (! $this->notificationService->exists($user, $eventId)) {
            $this->notificationService->create(
                user: $user,
                type: NotificationType::VERIFICATION_REJECTED,
                title: 'Identity Verification Rejected',
                message: "Your verification request was not approved. Reason: {$reason}",
                data: ['event_id' => $eventId, 'verification_request_id' => $req->id, 'reason' => $reason]
            );
        }

        return $req->fresh();
    }

    public function requestMoreInfo(VerificationRequest $req, Admin $admin, string $note): VerificationRequest
    {
        $this->assertReviewable($req);

        $req->update([
            'status' => 'needs_more_information',
            'reviewed_by_admin_id' => $admin->id,
            'reviewed_at' => now(),
            'decision_reason' => $note,
        ]);

        $user = $req->user;
        $user->update(['verification_status' => VerificationStatus::NEEDS_MORE_INFORMATION->value]);

        $this->auditService->log(
            actor: $admin,
            action: 'verification_needs_info',
            subject: $user,
            description: "More information requested for verification: {$user->email}",
            metadata: ['note' => $note],
            severity: 'info'
        );

        $eventId = "verification-needs-info:{$req->id}";
        if (! $this->notificationService->exists($user, $eventId)) {
            $this->notificationService->create(
                user: $user,
                type: NotificationType::VERIFICATION_NEEDS_INFO,
                title: 'More Information Required',
                message: "Additional information is needed for your verification: {$note}",
                data: ['event_id' => $eventId, 'verification_request_id' => $req->id, 'note' => $note]
            );
        }

        return $req->fresh();
    }
}
