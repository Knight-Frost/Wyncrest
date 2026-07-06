<?php

namespace App\Events;

use App\Models\Listing;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ListingChangesRequested Event
 *
 * Fired when an admin sends a listing back to the landlord for changes
 * (a fixable issue, not an outright rejection). Triggers a landlord
 * notification carrying the admin's message.
 */
class ListingChangesRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Listing $listing,
        public string $reason
    ) {}
}
