<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Enums\TerminatedBy;
use App\Http\Controllers\Controller;
use App\Http\Requests\TerminateContractRequest;
use App\Models\Contract;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TenantContractController
 *
 * Handles tenant contract operations.
 */
class TenantContractController extends Controller
{
    public function __construct(
        protected AuditService $auditService
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

        $contract->update([
            'status' => ContractStatus::ACTIVE,
        ]);

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_accepted',
            subject: $contract,
            description: 'Tenant accepted contract',
            severity: 'info'
        );

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
        $contract->update([
            'status' => ContractStatus::TERMINATED,
            'terminated_by' => TerminatedBy::TENANT,
            'termination_reason' => $request->reason,
        ]);

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_terminated',
            subject: $contract,
            description: 'Tenant terminated contract',
            severity: 'warning'
        );

        return response()->json([
            'message' => 'Contract terminated',
            'contract' => $contract->fresh(),
        ]);
    }
}
