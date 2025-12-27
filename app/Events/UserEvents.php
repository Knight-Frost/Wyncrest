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

/**
 * IdentityVerified Event
 * 
 * Fired when admin verifies landlord identity.
 * Triggers notification email.
 */
class IdentityVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $landlord
    ) {}
}
