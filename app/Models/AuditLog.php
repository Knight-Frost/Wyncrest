<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AuditLog Model
 *
 * Immutable audit trail of all critical actions.
 */
class AuditLog extends Model
{
    use HasFactory;

    // No updated_at - audit logs are immutable
    const UPDATED_AT = null;

    protected $fillable = [
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'action',
        'description',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
        'metadata',
        'severity',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Polymorphic relationships
     */
    public function actor()
    {
        return $this->morphTo();
    }

    public function subject()
    {
        return $this->morphTo();
    }

    /**
     * Scope: Critical logs only
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Scope: By action
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }
}
