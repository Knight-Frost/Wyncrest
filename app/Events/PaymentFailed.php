<?php

namespace App\Events;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PaymentFailed
 * 
 * Fired when a Stripe payment fails.
 * Triggered by: PaymentService::recordFailedPayment() or webhook handler
 */
class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $paymentIntentId,
        public LedgerEntry $rentEntry,
        public User $tenant,
        public string $errorMessage
    ) {}
}
