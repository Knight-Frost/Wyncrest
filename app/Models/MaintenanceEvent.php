<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * MaintenanceEvent
 *
 * Append-only, tenant-visible timeline entry for a maintenance request.
 * Unlike audit_logs (privileged/admin-only), these events are safe to show
 * both the tenant and the landlord.
 *
 * Append-only contract: there is no updated_at column (UPDATED_AT = null),
 * mirroring ApplicationEvent and the audit-log convention in this codebase.
 */
class MaintenanceEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'maintenance_request_id',
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

    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    /**
     * The actor who caused this event (tenant/landlord User, or null=system).
     */
    public function actor(): MorphTo
    {
        return $this->morphTo('actor');
    }
}
