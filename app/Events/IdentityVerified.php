<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
