<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Enums\LedgerType;
use App\Enums\LedgerStatus;
use App\Events\PaymentSucceeded;
use App\Events\PaymentFailed;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * PaymentService
 * 
 * Handles Stripe payment processing.
 * Ledger remains the source of truth.
 * 
 * Phase 3.5: Fires domain events for notifications
 */
class PaymentService
{
    protected ?StripeClient $stripe = null;

    public function __construct(
        protected AuditService $auditService
    ) {
        // Only initialize Stripe client if API key is configured
        // This allows the service to be instantiated in tests without real keys
        $stripeKey = config('services.stripe.secret');
        if (!empty($stripeKey)) {
            $this->stripe = new StripeClient($stripeKey);
        }
    }

    /**
     * Check if Stripe is configured and available.
     *
     * @return bool
     */
    protected function isStripeConfigured(): bool
    {
        return $this->stripe !== null;
    }

    /**
     * Get the Stripe client, throwing if not configured.
     *
     * @return StripeClient
     * @throws \Exception If Stripe is not configured
     */
    protected function getStripeClient(): StripeClient
    {
        if (!$this->isStripeConfigured()) {
            throw new \Exception('Stripe is not configured. Please set STRIPE_SECRET_KEY in your environment.');
        }
        return $this->stripe;
    }

    /**
     * Create a Stripe PaymentIntent for a ledger entry
     * 
     * @param LedgerEntry $entry The obligation to pay (rent or late_fee)
     * @param User $tenant The tenant making the payment
     * @return array ['client_secret' => string, 'payment_intent_id' => string]
     */
    public function createPaymentIntent(LedgerEntry $entry, User $tenant): array
    {
        // Validate entry can be paid
        if (!$entry->canBePaid()) {
            throw new \Exception('This ledger entry cannot be paid');
        }

        // Verify tenant owns this entry
        if ($entry->tenant_id != $tenant->id) {
            throw new \Exception('You cannot pay another tenant\'s obligation');
        }

        // Check for existing payment intent (idempotency)
        $existingPayment = LedgerEntry::where('related_rent_entry_id', $entry->id)
            ->where('type', LedgerType::PAYMENT)
            ->whereNotNull('stripe_payment_intent_id')
            ->first();

        if ($existingPayment) {
            // Return existing intent if still usable
            try {
                $intent = $this->getStripeClient()->paymentIntents->retrieve($existingPayment->stripe_payment_intent_id);
                if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'])) {
                    return [
                        'client_secret' => $intent->client_secret,
                        'payment_intent_id' => $intent->id,
                    ];
                }
            } catch (ApiErrorException $e) {
                // Intent doesn't exist or is invalid, create new one
            }
        }

        // Create new PaymentIntent
        try {
            $intent = $this->getStripeClient()->paymentIntents->create([
                'amount' => $entry->amount_cents,
                'currency' => strtolower($entry->currency),
                'metadata' => [
                    'ledger_entry_id' => $entry->id,
                    'tenant_id' => $tenant->id,
                    'contract_id' => $entry->contract_id,
                    'entry_type' => $entry->type->value,
                ],
                'description' => "Payment for {$entry->type->value} - {$entry->billing_period_start->format('M Y')}",
            ]);

            // Audit log
            $this->auditService->log(
                actor: $tenant,
                action: 'payment_intent_created',
                subject: $entry,
                description: "Payment intent created for ledger entry {$entry->id}: {$intent->id}",
                metadata: ['stripe_payment_intent_id' => $intent->id],
                severity: 'info'
            );

            return [
                'client_secret' => $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ];
        } catch (ApiErrorException $e) {
            // Audit log failure
            $this->auditService->log(
                actor: $tenant,
                action: 'payment_intent_failed',
                subject: $entry,
                description: "Failed to create payment intent: {$e->getMessage()}",
                severity: 'warning'
            );

            throw new \Exception("Payment processing error: {$e->getMessage()}");
        }
    }

    /**
     * Record successful payment from Stripe webhook
     * Creates a new PAYMENT ledger entry
     * 
     * Phase 3.5: Fires PaymentSucceeded event
     * 
     * @param string $paymentIntentId Stripe payment intent ID
     * @return LedgerEntry The payment ledger entry
     */
    public function recordSuccessfulPayment(string $paymentIntentId): LedgerEntry
    {
        // Retrieve payment intent from Stripe
        try {
            $intent = $this->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        } catch (ApiErrorException $e) {
            throw new \Exception("Could not retrieve payment intent: {$e->getMessage()}");
        }

        // Check for duplicate webhook (idempotency)
        $existingPayment = LedgerEntry::where('stripe_payment_intent_id', $paymentIntentId)
            ->where('type', LedgerType::PAYMENT)
            ->first();

        if ($existingPayment) {
            return $existingPayment; // Already recorded
        }

        // Get original ledger entry from metadata
        $ledgerEntryId = $intent->metadata->ledger_entry_id ?? null;
        if (!$ledgerEntryId) {
            throw new \Exception('Payment intent missing ledger_entry_id in metadata');
        }

        $originalEntry = LedgerEntry::find($ledgerEntryId);
        if (!$originalEntry) {
            throw new \Exception("Original ledger entry not found: {$ledgerEntryId}");
        }

        // Create PAYMENT ledger entry (negative amount = money received)
        $paymentEntry = LedgerEntry::create([
            'contract_id' => $originalEntry->contract_id,
            'tenant_id' => $originalEntry->tenant_id,
            'landlord_id' => $originalEntry->landlord_id,
            'type' => LedgerType::PAYMENT,
            'amount_cents' => -$originalEntry->amount_cents, // Negative = reduces balance
            'currency' => $originalEntry->currency,
            'billing_period_start' => $originalEntry->billing_period_start,
            'billing_period_end' => $originalEntry->billing_period_end,
            'due_date' => now(),
            'status' => LedgerStatus::PAID,
            'related_rent_entry_id' => $originalEntry->id,
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);

        // Audit log (info severity - successful transaction)
        $this->auditService->log(
            actor: null, // System action from webhook
            action: 'payment_recorded',
            subject: $paymentEntry,
            description: "Payment recorded for ledger entry {$originalEntry->id}: \${$originalEntry->amount_in_dollars}",
            metadata: [
                'stripe_payment_intent_id' => $paymentIntentId,
                'original_entry_id' => $originalEntry->id,
            ],
            severity: 'info'
        );

        // Phase 3.5: Fire domain event for notification
        $tenant = User::find($originalEntry->tenant_id);
        event(new PaymentSucceeded($paymentEntry, $originalEntry, $tenant));

        return $paymentEntry;
    }

    /**
     * Record failed payment from Stripe webhook
     * 
     * Phase 3.5: Fires PaymentFailed event
     * 
     * @param string $paymentIntentId Stripe payment intent ID
     */
    public function recordFailedPayment(string $paymentIntentId): void
    {
        // Retrieve payment intent from Stripe
        try {
            $intent = $this->getStripeClient()->paymentIntents->retrieve($paymentIntentId);
        } catch (ApiErrorException $e) {
            throw new \Exception("Could not retrieve payment intent: {$e->getMessage()}");
        }

        // Get original ledger entry from metadata
        $ledgerEntryId = $intent->metadata->ledger_entry_id ?? null;
        if (!$ledgerEntryId) {
            return; // Nothing to log if no ledger entry
        }

        $originalEntry = LedgerEntry::find($ledgerEntryId);
        if (!$originalEntry) {
            return;
        }

        $errorMessage = $intent->last_payment_error?->message ?? 'Unknown error';

        // Audit log (warning severity - failed transaction)
        $this->auditService->log(
            actor: null, // System action from webhook
            action: 'payment_failed',
            subject: $originalEntry,
            description: "Payment failed for ledger entry {$originalEntry->id}: {$errorMessage}",
            metadata: [
                'stripe_payment_intent_id' => $paymentIntentId,
                'error_code' => $intent->last_payment_error?->code,
                'error_message' => $errorMessage,
            ],
            severity: 'warning'
        );

        // Phase 3.5: Fire domain event for notification
        $tenant = User::find($originalEntry->tenant_id);
        event(new PaymentFailed($paymentIntentId, $originalEntry, $tenant, $errorMessage));
    }

    /**
     * Get tenant's payment balance (what they owe)
     * 
     * @param User $tenant
     * @return int Balance in cents (positive = owes money)
     */
    public function getTenantBalance(User $tenant): int
    {
        // Sum all obligations (rent, late fees) - positive
        $obligations = LedgerEntry::byTenant($tenant->id)
            ->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
            ->sum('amount_cents');

        // Sum all payments - negative
        $payments = LedgerEntry::byTenant($tenant->id)
            ->where('type', LedgerType::PAYMENT->value)
            ->sum('amount_cents');

        return $obligations + $payments; // Payments are negative, so this adds correctly
    }
}
