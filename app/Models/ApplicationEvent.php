<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ApplicationEvent
 *
 * Append-only, tenant-visible timeline entry for an application. Unlike
 * audit_logs (privileged/admin-only), these events are safe to show the tenant.
 *
 * Append-only contract: there is no updated_at column (UPDATED_AT = null),
 * mirroring the audit-log convention in this codebase.
 */
class ApplicationEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'application_id',
        'actor_type',
        'actor_id',
        'event',
        'description',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * The actor who caused this event (tenant/landlord User, Admin, or null).
     */
    public function actor(): MorphTo
    {
        return $this->morphTo('actor');
    }
}
