<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ListingPhoto Model
 */
class ListingPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_id',
        'path',
        'disk',
        'filename',
        'mime_type',
        'file_size',
        'width',
        'height',
        'sort_order',
        'is_primary',
        'alt_text',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'sort_order' => 'integer',
        'is_primary' => 'boolean',
    ];

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }
}

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

/**
 * EmailLog Model
 * 
 * Tracks all emails sent by the system.
 */
class EmailLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'recipient_email',
        'subject',
        'mailable_class',
        'email_type',
        'related_type',
        'related_id',
        'status',
        'sent_at',
        'error_message',
        'opened_at',
        'clicked_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];

    public function recipient()
    {
        return $this->morphTo();
    }

    public function related()
    {
        return $this->morphTo();
    }
}

/**
 * Conversation Model
 * 
 * Phase 1: Schema only (no UI or sending).
 */
class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'participant_one_type',
        'participant_one_id',
        'participant_two_type',
        'participant_two_id',
        'subject_type',
        'subject_id',
        'title',
        'status',
        'last_message_at',
        'last_message_by',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function participantOne()
    {
        return $this->morphTo();
    }

    public function participantTwo()
    {
        return $this->morphTo();
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}

/**
 * Message Model
 * 
 * Phase 1: Schema only (no UI or sending).
 */
class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_type',
        'sender_id',
        'body',
        'is_read',
        'read_at',
        'is_system_message',
        'has_attachments',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'is_system_message' => 'boolean',
        'has_attachments' => 'boolean',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->morphTo();
    }
}
