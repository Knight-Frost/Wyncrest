<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AuditLog Model
 *
 * Immutable audit trail of all critical actions.
 *
 * Tamper evidence: every row is SHA-256 hash-chained to the one before it. On
 * INSERT (via the `creating` boot hook, so factories/seeders/service all get it)
 * we compute `hash = sha256(previous_hash | canonical(row))`, where
 * `previous_hash` is the most-recent row's hash (or GENESIS_HASH for the first
 * row). Because each hash commits to the prior hash, editing any historical row
 * breaks every hash after it — which `AuditLogService::verifyChain()` detects by
 * recomputing the chain with this SAME `canonicalFields()` serialization.
 */
class AuditLog extends Model
{
    use HasFactory;

    // No updated_at - audit logs are immutable
    const UPDATED_AT = null;

    /** Chain anchor for the very first record (64 zero hex chars). */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Fields committed to the hash, in a FIXED order. Must be identical at write
     * time and verify time or the chain would falsely read as broken.
     */
    private const CANONICAL_KEYS = [
        'actor_type', 'actor_id', 'subject_type', 'subject_id', 'action',
        'description', 'ip_address', 'user_agent', 'old_values', 'new_values',
        'metadata', 'severity', 'created_at',
    ];

    protected static function booted(): void
    {
        // Compute the hash link on the way in, for EVERY create path (service,
        // factory, seeder). Runs before timestamps are auto-set, so we pin
        // created_at ourselves (whole seconds — SQLite datetime precision — so a
        // later reload reproduces the exact bytes that were hashed).
        static::creating(function (self $log) {
            $log->created_at = ($log->created_at ?? Carbon::now())->copy()->micro(0);

            $previous = static::query()->orderByDesc('id')->lockForUpdate()->value('hash')
                ?? self::GENESIS_HASH;

            $log->previous_hash = $previous;
            $log->hash = self::chainHashFor($previous, $log->canonicalFields());
        });
    }

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

    // -------------------------------------------------------------------------
    // Hash chain
    // -------------------------------------------------------------------------

    /**
     * The canonical, hash-committed view of this row (excludes id + the hash
     * columns themselves). Used identically on write and on verify.
     *
     * @return array<string, mixed>
     */
    public function canonicalFields(): array
    {
        return [
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id !== null ? (int) $this->actor_id : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id !== null ? (int) $this->subject_id : null,
            'action' => $this->action,
            'description' => $this->description,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'metadata' => $this->metadata,
            'severity' => $this->severity,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * SHA-256 of the previous hash joined with the canonical row payload.
     * Deterministic: fixed key order + stable JSON encoding.
     */
    public static function chainHashFor(?string $previousHash, array $fields): string
    {
        $canonical = [];
        foreach (self::CANONICAL_KEYS as $key) {
            $canonical[$key] = $fields[$key] ?? null;
        }

        $payload = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', ($previousHash ?? self::GENESIS_HASH).'|'.$payload);
    }
}
