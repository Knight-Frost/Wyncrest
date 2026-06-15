<?php

namespace App\Events;

use App\Models\Listing;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ListingRejected Event
 *
 * Fired when admin rejects a listing.
 * Triggers landlord notification with reason.
 */
class ListingRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Listing $listing,
        public string $reason
    ) {}
}
