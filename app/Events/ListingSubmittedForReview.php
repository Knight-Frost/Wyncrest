<?php

namespace App\Events;

use App\Models\Listing;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
