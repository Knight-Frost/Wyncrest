<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
