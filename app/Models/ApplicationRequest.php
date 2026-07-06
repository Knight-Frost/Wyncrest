<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ApplicationRequest
 *
 * A landlord (or platform admin) asking the tenant for something on a specific
 * application — a document replacement or more information. An OPEN request
 * (resolved_at === null) puts the application in NEEDS_ACTION.
 */
class ApplicationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'requested_by_type',
        'requested_by_id',
        'requester_role',
        'type',
        'document_type',
        'message',
        'reason',
        'due_at',
        'resolved_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['is_resolved'];

    /**
     * Whether the tenant has already satisfied this request.
     */
    public function getIsResolvedAttribute(): bool
    {
        return $this->resolved_at !== null;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * The actor who raised the request (landlord User or Admin).
     */
    public function requestedBy(): MorphTo
    {
        return $this->morphTo('requested_by');
    }

    /**
     * Scope to only open (unresolved) requests.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }
}
