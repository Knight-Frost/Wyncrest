<?php

namespace App\Services;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\Ledger\PaymentEntryFactory;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

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
        protected AuditService $auditService,
        protected LedgerComputationEngine $engine,
        protected PaymentEntryFactory $paymentEntries,
    ) {
        // Only initialize Stripe client if API key is configured
        // This allows the service to be instantiated in tests without real keys
        $stripeKey = config('services.stripe.secret');
        if (! empty($stripeKey)) {
            $this->stripe = new StripeClient($stripeKey);
        }
    }

    /**
     * Check if Stripe is configured and available.
     *
     * Public so controllers can advertise online-payment availability to the
     * SPA truthfully (e.g. the tenant Payments page hides the card checkout
     * when no gateway is wired) instead of letting the tenant discover it via
     * a failed charge.
     */
    public function isStripeConfigured(): bool
    {
        return $this->stripe !== null;
    }

    /**
     * Get the Stripe client, throwing if not configured.
     *
     * @throws \Exception If Stripe is not configured
     */
    protected function getStripeClient(): StripeClient
    {
        if (! $this->isStripeConfigured()) {
            throw new \Exception('Stripe is not configured. Please set STRIPE_SECRET_KEY in your environment.');
        }

        return $this->stripe;
    }

    /**
     * Create a Stripe PaymentIntent for a ledger entry
     *
     * @param  LedgerEntry  $entry  The obligation to pay (rent or late_fee)
     * @param  User  $tenant  The tenant making the payment
     * @return array ['client_secret' => string, 'payment_intent_id' => string]
     */
    public function createPaymentIntent(LedgerEntry $entry, User $tenant): array
    {
        // Validate entry can be paid
        if (! $entry->canBePaid()) {
            throw new \Exception('This ledger entry cannot be paid');
        }

        // Verify tenant owns this entry (using strict type comparison)
        if ((int) $entry->tenant_id !== (int) $tenant->id) {
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
     * @param  string  $paymentIntentId  Stripe payment intent ID
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
        if (! $ledgerEntryId) {
            throw new \Exception('Payment intent missing ledger_entry_id in metadata');
        }

        $originalEntry = LedgerEntry::find($ledgerEntryId);
        if (! $originalEntry) {
            throw new \Exception("Original ledger entry not found: {$ledgerEntryId}");
        }

        // The ledger records what actually moved: refuse to book a credit
        // unless Stripe confirms the charge succeeded for the exact amount
        // and currency of the obligation.
        if ($intent->status !== 'succeeded') {
            throw new \Exception("Payment intent {$paymentIntentId} is not succeeded (status: {$intent->status})");
        }

        $received = $intent->amount_received ?? $intent->amount;
        if ((int) $received !== (int) $originalEntry->amount_cents
            || strtolower((string) $intent->currency) !== strtolower((string) $originalEntry->currency)) {
            $this->auditService->log(
                actor: null,
                action: 'payment_amount_mismatch',
                subject: $originalEntry,
                description: "Refused to record payment {$paymentIntentId}: Stripe reports {$received} {$intent->currency}, obligation is {$originalEntry->amount_cents} {$originalEntry->currency}",
                metadata: ['stripe_payment_intent_id' => $paymentIntentId],
                severity: 'critical'
            );
            throw new \Exception("Payment intent {$paymentIntentId} amount/currency does not match the obligation");
        }

        $paymentEntry = DB::transaction(function () use ($originalEntry, $paymentIntentId) {
            // Re-read under lock so a concurrent webhook/manual payment
            // cannot settle the same obligation twice.
            $lockedEntry = LedgerEntry::whereKey($originalEntry->id)->lockForUpdate()->first();

            $duplicate = LedgerEntry::where('stripe_payment_intent_id', $paymentIntentId)
                ->where('type', LedgerType::PAYMENT)
                ->lockForUpdate()
                ->first();
            if ($duplicate) {
                return $duplicate;
            }

            if (! $lockedEntry->canBePaid()) {
                throw new \Exception("Ledger entry {$lockedEntry->id} is not payable (status: {$lockedEntry->status->value})");
            }

            // Create PAYMENT ledger entry (negative amount = money received).
            // Shared shape lives in PaymentEntryFactory; the Stripe identity is
            // the settling payment intent id.
            $paymentEntry = LedgerEntry::create(
                $this->paymentEntries->forObligation($lockedEntry, [
                    'stripe_payment_intent_id' => $paymentIntentId,
                ])
            );

            // Settle the obligation itself so it stops being due (and can no
            // longer be marked overdue, fined, or paid a second time).
            if (! $lockedEntry->transitionStatus(LedgerStatus::PAID, $paymentIntentId)) {
                throw new \Exception("Ledger entry {$lockedEntry->id} changed state while recording payment");
            }

            return $paymentEntry;
        });

        // Redelivered/concurrent webhook resolved to the existing entry.
        if (! $paymentEntry->wasRecentlyCreated) {
            return $paymentEntry;
        }

        // Audit log (info severity - successful transaction)
        $this->auditService->log(
            actor: null, // System action from webhook
            action: 'payment_recorded',
            subject: $paymentEntry,
            description: "Payment recorded for ledger entry {$originalEntry->id}: GH₵".number_format($originalEntry->amount_cents / 100, 2),
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
     * @param  string  $paymentIntentId  Stripe payment intent ID
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
        if (! $ledgerEntryId) {
            return; // Nothing to log if no ledger entry
        }

        $originalEntry = LedgerEntry::find($ledgerEntryId);
        if (! $originalEntry) {
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
     * @return int Balance in cents (positive = owes money)
     */
    public function getTenantBalance(User $tenant): int
    {
        return $this->engine->computeTenantBalance((int) $tenant->id);
    }
}
