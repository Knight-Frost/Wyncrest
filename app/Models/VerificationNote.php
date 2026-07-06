<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VerificationNote
 *
 * Internal, admin-only note attached to a verification request during case
 * review. Never exposed to the tenant or landlord — these are private review
 * notes.
 */
class VerificationNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'verification_request_id',
        'admin_id',
        'body',
    ];

    /**
     * The verification request this note belongs to.
     */
    public function verificationRequest(): BelongsTo
    {
        return $this->belongsTo(VerificationRequest::class);
    }

    /**
     * The admin who authored the note.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
