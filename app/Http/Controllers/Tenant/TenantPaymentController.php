<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\InitiatePaymentRequest;
use App\Models\LedgerEntry;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * TenantPaymentController
 *
 * Handles tenant payment initiation.
 * SECURITY: Sanitizes error messages to prevent information disclosure.
 */
class TenantPaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Initiate payment for a ledger entry.
     * Returns Stripe client_secret for frontend.
     */
    public function initiate(InitiatePaymentRequest $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        try {
            $result = $this->paymentService->createPaymentIntent(
                $ledgerEntry,
                $request->user()
            );

            return response()->json([
                'message' => 'Payment intent created',
                'client_secret' => $result['client_secret'],
                'payment_intent_id' => $result['payment_intent_id'],
            ]);
        } catch (ApiErrorException $e) {
            // Stripe API error - log full details, return sanitized message
            Log::error('Stripe API error during payment initiation', [
                'ledger_entry_id' => $ledgerEntry->id,
                'user_id' => $request->user()->id,
                'stripe_error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
            ]);

            return response()->json([
                'message' => 'Unable to process payment. Please try again or contact support.'
            ], 422);
        } catch (\InvalidArgumentException $e) {
            // Business logic error (e.g., already paid, invalid state)
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            // Unexpected error - log full details, return generic message
            Log::error('Unexpected error during payment initiation', [
                'ledger_entry_id' => $ledgerEntry->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again later.'
            ], 500);
        }
    }

    /**
     * Get tenant's payment balance.
     */
    public function balance(Request $request): JsonResponse
    {
        try {
            $balance = $this->paymentService->getTenantBalance($request->user());

            return response()->json([
                'balance_cents' => $balance,
                'balance_dollars' => $balance / 100,
                'owes_money' => $balance > 0,
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving tenant balance', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to retrieve balance. Please try again.'
            ], 500);
        }
    }
}
