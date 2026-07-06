<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Application Model
 *
 * Represents a tenant's application to a rental listing.
 *
 * SECURITY: landlord_notes is hidden from JSON serialization so it is never
 * leaked to tenants. Controllers that are landlord-scoped may call
 * makeVisible('landlord_notes') when returning data to the landlord.
 */
class Application extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'listing_id',
        'landlord_id',
        'status',
        'cover_note',
        'form_data',
        'landlord_notes',
        'decision_reason',
        'submitted_at',
        'reviewed_at',
        'decided_at',
        'withdrawn_at',
        'shortlisted_at',
    ];

    /**
     * SECURITY: landlord_notes must never appear in tenant-facing JSON responses.
     */
    protected $hidden = ['landlord_notes'];

    protected $casts = [
        'status' => ApplicationStatus::class,
        'form_data' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'decided_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'shortlisted_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['is_shortlisted'];

    /**
     * Whether the landlord has shortlisted this applicant — an internal
     * organisational flag independent of the lifecycle status.
     */
    public function getIsShortlistedAttribute(): bool
    {
        return $this->shortlisted_at !== null;
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The tenant who submitted this application.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * The landlord who owns the listing this application targets.
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    /**
     * The listing this application is for.
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Documents the tenant attached to THIS application (polymorphic).
     * Distinct from the tenant's global identity-verification documents.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'related')->latest();
    }

    /**
     * Landlord/admin requests for more info or document replacement.
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ApplicationRequest::class)->latest();
    }

    /**
     * Open (unresolved) requests — an application with any of these sits in
     * NEEDS_ACTION.
     */
    public function openRequests(): HasMany
    {
        return $this->requests()->whereNull('resolved_at');
    }

    /**
     * Append-only, tenant-visible timeline.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class)->orderBy('id');
    }

    /**
     * The most recent timeline event — for the "recent updates" list without
     * hydrating the whole timeline.
     */
    public function latestEvent(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ApplicationEvent::class)->latestOfMany();
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope to only active (submitted, non-final) applications. Excludes drafts.
     */
    public function scopeActive($query)
    {
        return $query->whereIn(
            'status',
            array_map(fn (ApplicationStatus $s) => $s->value, ApplicationStatus::activeCases())
        );
    }
}
