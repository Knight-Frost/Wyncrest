<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ContractStatus;
use App\Enums\TerminatedBy;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\TerminateContractRequest;
use App\Models\Contract;
use App\Models\Listing;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordContractController
 *
 * Handles landlord contract operations.
 */
class LandlordContractController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Display landlord's contracts
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = Contract::byLandlord($request->user()->id)
            ->with(['listing', 'tenant'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($contracts);
    }

    /**
     * Store a newly created contract (draft status)
     */
    public function store(StoreContractRequest $request): JsonResponse
    {
        // Check if listing already has a contract
        $existingContract = Contract::where('listing_id', $request->listing_id)->first();
        if ($existingContract) {
            return response()->json([
                'message' => 'This listing already has a contract',
            ], 422);
        }

        // Verify listing belongs to landlord
        $listing = Listing::findOrFail($request->listing_id);
        if ($listing->landlord_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You do not own this listing',
            ], 403);
        }

        $contract = Contract::create([
            'listing_id' => $request->listing_id,
            'landlord_id' => $request->user()->id,
            'tenant_id' => $request->tenant_id,
            'rent_amount' => $request->rent_amount,
            'currency' => $request->currency ?? 'USD',
            'billing_cycle' => $request->billing_cycle ?? \App\Enums\BillingCycle::MONTHLY,
            'payment_day' => $request->payment_day,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'status' => ContractStatus::DRAFT,
        ]);

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_created',
            subject: $contract,
            description: "Created contract for listing {$listing->title}"
        );

        return response()->json([
            'message' => 'Contract created as draft',
            'contract' => $contract->load(['listing', 'tenant']),
        ], 201);
    }

    /**
     * Display the specified contract
     */
    public function show(Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        return response()->json($contract->load(['listing', 'tenant', 'admin']));
    }

    /**
     * Send contract to tenant for acceptance
     */
    public function send(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('send', $contract);

        $contract->update([
            'status' => ContractStatus::PENDING_TENANT,
        ]);

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_sent',
            subject: $contract,
            description: 'Sent contract to tenant for acceptance'
        );

        return response()->json([
            'message' => 'Contract sent to tenant',
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
            'terminated_by' => TerminatedBy::LANDLORD,
            'termination_reason' => $request->reason,
        ]);

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'contract_terminated',
            subject: $contract,
            description: 'Landlord terminated contract',
            severity: 'warning'
        );

        return response()->json([
            'message' => 'Contract terminated',
            'contract' => $contract->fresh(),
        ]);
    }
}
