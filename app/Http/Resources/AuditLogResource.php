<?php

namespace App\Http\Resources;

use App\Models\Admin;
use App\Models\User;
use App\Support\Audit\AuditClassifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AuditLogResource
 *
 * Lean list-item representation of an audit log entry.
 * Only derived/presentation fields — no raw JSON blobs.
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $this->whenLoaded('actor');

        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'area' => AuditClassifier::area($this->action),
            'action' => $this->action,
            'action_label' => AuditClassifier::actionLabel($this->action),
            'severity' => $this->severity,
            'status' => AuditClassifier::status($this->severity),
            'actor' => $this->resolveActor($actor),
            'summary' => $this->description ?? AuditClassifier::actionLabel($this->action),
            'subject_label' => $this->resolveSubjectLabel(),
            'ip_address' => $this->ip_address,
            // Tamper-evidence: this row's SHA-256 chain hash (short form shown as a pill).
            'hash' => $this->hash,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve actor to a normalised {id, role, name, email} object.
     * Null actor (system-generated events) gets role 'system'.
     */
    protected function resolveActor(mixed $actor): array
    {
        if ($actor === null) {
            return [
                'id' => null,
                'role' => 'system',
                'name' => 'System',
                'email' => null,
            ];
        }

        if ($actor instanceof Admin) {
            return [
                'id' => $actor->id,
                'role' => AuditClassifier::actorRole($this->actor_type, null),
                'name' => $actor->name ?? 'Admin',
                'email' => $actor->email,
            ];
        }

        if ($actor instanceof User) {
            $full = trim(($actor->first_name ?? '').' '.($actor->last_name ?? ''));

            return [
                'id' => $actor->id,
                'role' => AuditClassifier::actorRole($this->actor_type, $actor->user_type?->value ?? $actor->user_type),
                'name' => $full !== '' ? $full : ($actor->email ?? 'User'),
                'email' => $actor->email,
            ];
        }

        // Unexpected actor type — safe fallback
        return [
            'id' => $actor->id ?? null,
            'role' => 'user',
            'name' => 'Unknown',
            'email' => $actor->email ?? null,
        ];
    }

    /**
     * Build a short human label for the morph subject.
     * Returns null when no subject is loaded.
     */
    protected function resolveSubjectLabel(): ?string
    {
        $subject = $this->resource->relationLoaded('subject') ? $this->subject : null;

        if ($subject === null) {
            return null;
        }

        $basename = class_basename(get_class($subject));

        // Prefer a meaningful name field over the bare ID
        $name = $subject->title
            ?? $subject->name
            ?? (isset($subject->first_name)
                ? trim(($subject->first_name ?? '').' '.($subject->last_name ?? ''))
                : null)
            ?? $subject->unit_number
            ?? null;

        if ($name && trim($name) !== '') {
            return "{$basename}: {$name}";
        }

        return "{$basename} #{$subject->id}";
    }
}
