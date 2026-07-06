<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ListingNote
 *
 * Internal, admin-only note attached to a listing during moderation.
 * Never exposed to the landlord or tenant — these are private review notes.
 */
class ListingNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_id',
        'admin_id',
        'body',
    ];

    /**
     * The listing this note belongs to.
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * The admin who authored the note.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
