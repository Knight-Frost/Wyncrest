<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Enums\TerminatedBy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminTerminateContractRequest;
use App\Models\Contract;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminContractController
 *
 * Handles admin contract operations.
 */
class AdminContractController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Display all contracts
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string'],
            'landlord_id' => ['sometimes', 'uuid'],
            'tenant_id' => ['sometimes', 'uuid'],
        ]);

        $query = Contract::with(['listing', 'landlord', 'tenant', 'admin'])
            ->orderBy('created_at', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['landlord_id'])) {
            $query->where('landlord_id', $filters['landlord_id']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        $contracts = $query->paginate(50);

        return response()->json($contracts);
    }

    /**
     * Display the specified contract
     */
    public function show(Contract $contract): JsonResponse
    {
        return response()->json($contract->load(['listing.unit.property', 'landlord', 'tenant', 'admin']));
    }

    /**
     * Force terminate contract (admin only)
     */
    public function terminate(AdminTerminateContractRequest $request, Contract $contract): JsonResponse
    {
        if (! $contract->canBeTerminated()) {
            return response()->json([
                'message' => 'Only active contracts can be terminated',
            ], 422);
        }

        $contract->update([
            'status' => ContractStatus::TERMINATED,
            'terminated_by' => TerminatedBy::ADMIN,
            'termination_reason' => $request->reason,
            'admin_id' => $request->user()->id,
        ]);

        // Audit log (critical - admin forced termination)
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_force_terminated',
            subject: $contract,
            description: 'Admin force terminated contract',
            severity: 'critical'
        );

        return response()->json([
            'message' => 'Contract terminated by admin',
            'contract' => $contract->fresh(),
        ]);
    }
}
