<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * UserCreated Event
 *
 * Fired when a new user account is created.
 * Triggers welcome email.
 */
class UserCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user
    ) {}
}
