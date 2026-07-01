<?php

namespace App\Http\Resources;

use App\Support\Audit\AuditClassifier;
use Illuminate\Http\Request;

/**
 * AuditLogDetailResource
 *
 * Full detail representation of an audit log entry.
 * Extends the list shape and adds raw stored fields for the "View raw log" panel,
 * plus device parsing, why-it-matters, and recommended steps.
 */
class AuditLogDetailResource extends AuditLogResource
{
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        $subject = $this->resource->relationLoaded('subject') ? $this->subject : null;

        return array_merge($base, [
            // Device parsing — may be null; never fabricated
            'user_agent' => $this->user_agent,
            'device' => AuditClassifier::device($this->user_agent),

            // Raw morph type for programmatic consumers
            'actor_type' => $this->actor_type,

            // Rich subject object — null when no subject exists
            'subject' => $subject !== null ? [
                'type' => class_basename(get_class($subject)),
                'id' => $subject->id,
                'label' => $this->resolveSubjectLabel(),
            ] : null,

            // Raw stored JSON — for the "View raw log" panel
            'metadata' => $this->metadata,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,

            // Hash-chain link for this entry (this row + the one it commits to).
            'previous_hash' => $this->previous_hash,

            // Classifier-derived guidance
            'why_it_matters' => AuditClassifier::whyItMatters($this->action, $this->severity),
            'recommended_steps' => AuditClassifier::recommendedSteps(
                $this->action,
                $this->subject_type,
                is_numeric($this->subject_id) ? (int) $this->subject_id : null,
                []
            ),
        ]);
    }
}
