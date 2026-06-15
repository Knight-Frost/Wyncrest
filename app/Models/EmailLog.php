<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
