<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Document
 *
 * Represents a file uploaded by or on behalf of a user.
 *
 * SECURITY:
 * - stored_path and disk are in $hidden so they are NEVER serialised to JSON.
 * - Downloads must go through DocumentController::download(), which enforces
 *   the DocumentPolicy before streaming the file from the private 'local' disk.
 * - No public URLs or signed paths are ever exposed.
 */
class Document extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id',
        'uploaded_by_id',
        'document_type',
        'original_filename',
        'stored_path',
        'disk',
        'mime_type',
        'size_bytes',
        'verified_at',
        'related_type',
        'related_id',
    ];

    /**
     * SECURITY: stored_path and disk must never appear in JSON responses.
     * The controller streams files after a policy check; paths are internal.
     *
     * @var list<string>
     */
    protected $hidden = [
        'stored_path',
        'disk',
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'verified_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    /**
     * Append computed attributes to JSON output.
     *
     * @var list<string>
     */
    protected $appends = ['is_verified', 'deletion_blocked_reason'];

    /**
     * Verification-case statuses during which (or after which) the evidence
     * must be kept: the case is open, or it was approved on this evidence.
     */
    public const PROTECTED_VERIFICATION_STATUSES = ['pending', 'under_review', 'needs_more_information', 'approved'];

    /**
     * Application statuses after submission in which attached documents are
     * part of what the landlord is (or was) deciding on.
     */
    public const PROTECTED_APPLICATION_STATUSES = ['submitted', 'in_review', 'landlord_review', 'needs_action', 'approved'];

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Whether the document has been verified by an admin or the system.
     */
    public function getIsVerifiedAttribute(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Why the owner may not delete this document right now, or null if they
     * may (forensic audit WYN-012). Unsubmitted uploads, and documents on a
     * draft/withdrawn/rejected application or a rejected verification case,
     * stay removable. Staff access is unaffected.
     */
    public function getDeletionBlockedReasonAttribute(): ?string
    {
        if ($this->related_type === null || $this->related_id === null) {
            return null;
        }

        $related = $this->related;
        $status = $related?->status instanceof \BackedEnum ? $related->status->value : $related?->status;

        if ($this->related_type === VerificationRequest::class && in_array($status, self::PROTECTED_VERIFICATION_STATUSES, true)) {
            return $status === 'approved'
                ? 'This document is evidence for your approved identity verification, so it is kept on file and cannot be deleted.'
                : 'This document is part of a verification request that is still being reviewed, so it cannot be deleted until the review is finished.';
        }

        if ($this->related_type === Application::class && in_array($status, self::PROTECTED_APPLICATION_STATUSES, true)) {
            return 'This document is attached to a submitted rental application, so it cannot be deleted while the application is open or after it was approved. Withdraw the application first if you need to remove it.';
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The user who owns this document.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * The user who uploaded this document (may differ from owner in future).
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * Optional polymorphic link to the entity this document was attached to
     * (e.g. Application, MaintenanceRequest, Contract).
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
