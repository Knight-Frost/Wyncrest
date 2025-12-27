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

/**
 * ListingSubmittedForReview Event
 * 
 * Fired when landlord submits listing for admin review.
 * Triggers admin notification.
 */
class ListingSubmittedForReview
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Listing $listing
    ) {}
}

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
