<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\InitiatePaymentRequest;
use App\Models\LedgerEntry;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * TenantPaymentController
 * 
 * Handles tenant payment initiation.
 */
class TenantPaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * Initiate payment for a ledger entry
     * Returns Stripe client_secret for frontend
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
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get tenant's payment balance
     */
    public function balance(Request $request): JsonResponse
    {
        $balance = $this->paymentService->getTenantBalance($request->user());

        return response()->json([
            'balance_cents' => $balance,
            'balance_dollars' => $balance / 100,
            'owes_money' => $balance > 0,
        ]);
    }
}
