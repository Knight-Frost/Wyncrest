<?php

namespace App\Events;

use App\Models\Listing;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ListingPublished Event
 *
 * Fired when a listing is published.
 * Triggers notification to landlord.
 */
class ListingPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Listing $listing
    ) {}
}
