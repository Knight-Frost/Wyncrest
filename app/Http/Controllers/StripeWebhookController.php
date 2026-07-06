<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * StripeWebhookController
 *
 * Handles Stripe webhook events.
 * CRITICAL: Must verify webhook signature before processing any events.
 * SECURITY: Rejects webhooks if secret is not properly configured.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Handle incoming Stripe webhooks.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        // SECURITY: Reject if webhook secret is not configured or is placeholder
        if (empty($webhookSecret) || str_starts_with($webhookSecret, 'whsec_placeholder')) {
            Log::critical('Stripe webhook rejected: webhook secret not configured', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        // SECURITY: Reject if signature header is missing
        if (empty($signature)) {
            Log::warning('Stripe webhook rejected: missing signature header', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Missing signature'], 400);
        }

        // Verify webhook signature
        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook invalid payload', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Webhook error'], 400);
        }

        // Handle the event
        try {
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handlePaymentFailed($event->data->object);
                    break;

                default:
                    Log::info('Unhandled Stripe webhook event', [
                        'type' => $event->type,
                    ]);
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Error processing Stripe webhook', [
                'event_type' => $event->type,
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            // Return 5xx so Stripe retries: recordSuccessfulPayment() is
            // idempotent per intent id, and answering 200 on a transient
            // failure would lose a real captured charge forever.
            return response()->json(['status' => 'processing_error'], 500);
        }
    }

    /**
     * Handle successful payment.
     */
    protected function handlePaymentSucceeded($paymentIntent): void
    {
        $this->paymentService->recordSuccessfulPayment($paymentIntent->id);

        Log::info('Payment succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
        ]);
    }

    /**
     * Handle failed payment.
     */
    protected function handlePaymentFailed($paymentIntent): void
    {
        $this->paymentService->recordFailedPayment($paymentIntent->id);

        Log::warning('Payment failed', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $paymentIntent->last_payment_error?->message,
        ]);
    }
}
