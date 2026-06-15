<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EmailVerified Event
 *
 * Fired when user verifies their email.
 * Triggers confirmation email.
 */
class EmailVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user
    ) {}
}
