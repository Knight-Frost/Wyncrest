<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

/**
 * StripeWebhookController
 * 
 * Handles Stripe webhook events.
 * CRITICAL: Must verify webhook signature.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Handle incoming Stripe webhooks
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        // Verify webhook signature
        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );
        } catch (SignatureVerificationException $e) {
            \Log::error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            \Log::error('Stripe webhook processing error', [
                'error' => $e->getMessage()
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
                    \Log::info('Unhandled Stripe webhook event', [
                        'type' => $event->type
                    ]);
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            \Log::error('Error processing Stripe webhook', [
                'event_type' => $event->type,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Processing error'], 500);
        }
    }

    /**
     * Handle successful payment
     */
    protected function handlePaymentSucceeded($paymentIntent): void
    {
        $this->paymentService->recordSuccessfulPayment($paymentIntent->id);

        \Log::info('Payment succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
        ]);
    }

    /**
     * Handle failed payment
     */
    protected function handlePaymentFailed($paymentIntent): void
    {
        $this->paymentService->recordFailedPayment($paymentIntent->id);

        \Log::warning('Payment failed', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $paymentIntent->last_payment_error?->message,
        ]);
    }
}
