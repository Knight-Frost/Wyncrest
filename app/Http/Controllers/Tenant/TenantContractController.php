<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\TerminateContractRequest;
use App\Models\Contract;
use App\Services\Contracts\ContractLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TenantContractController
 *
 * Handles tenant contract operations. Status transitions are delegated to the
 * ContractLifecycleService engine; the controller only authorizes and shapes
 * the HTTP response.
 */
class TenantContractController extends Controller
{
    public function __construct(
        protected ContractLifecycleService $lifecycle
    ) {}

    /**
     * Display tenant's contracts
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = Contract::byTenant($request->user()->id)
            ->with(['listing.unit.property', 'landlord'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($contracts);
    }

    /**
     * Display the specified contract
     */
    public function show(Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        return response()->json($contract->load(['listing.unit.property', 'landlord', 'admin']));
    }

    /**
     * Accept pending contract
     */
    public function accept(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('accept', $contract);

        $this->lifecycle->accept($contract, $request->user());

        return response()->json([
            'message' => 'Contract accepted and activated',
            'contract' => $contract->fresh(),
        ]);
    }

    /**
     * Terminate active contract
     */
    public function terminate(TerminateContractRequest $request, Contract $contract): JsonResponse
    {
        // TerminateContractRequest@authorize() already delegates to ContractPolicy@terminate,
        // but we repeat it here for defense-in-depth visibility.
        $this->authorize('terminate', $contract);

        $this->lifecycle->terminateByTenant($contract, $request->user(), $request->reason);

        return response()->json([
            'message' => 'Contract terminated',
            'contract' => $contract->fresh(),
        ]);
    }
}
