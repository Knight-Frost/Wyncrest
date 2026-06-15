<?php

namespace App\Events;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentSucceeded
 *
 * Fired when a Stripe payment succeeds.
 * Triggered by: PaymentService::recordSuccessfulPayment()
 */
class PaymentSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LedgerEntry $paymentEntry,
        public LedgerEntry $rentEntry,
        public User $tenant
    ) {}
}
