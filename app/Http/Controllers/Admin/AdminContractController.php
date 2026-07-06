<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Enums\NotificationType;
use App\Enums\TerminatedBy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminTerminateContractRequest;
use App\Models\Contract;
use App\Models\ContractNote;
use App\Services\AuditService;
use App\Services\ContractCaseFileService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminContractController
 *
 * Powers the admin Contracts command centre: queue with truthful segment
 * counts, a full case-file detail view, contract-scoped ledger/payments/
 * billing-schedule/timeline, internal admin notes, and force-termination.
 * Every dangerous action is audited; every figure traces to
 * LedgerComputationEngine or a real column — nothing is computed client-side.
 */
class AdminContractController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected NotificationService $notificationService,
        protected ContractCaseFileService $caseFileService,
    ) {}

    /**
     * The contracts queue: truthful counts plus a filtered/searched/sorted list.
     * GET /admin/contracts
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:all,active,awaiting,expiring,overdue,ended,draft'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:ending_soonest,newest,rent,property'],
            // why: landlord_id/tenant_id are bigint users.id FKs, not UUIDs. (June 2026)
            'landlord_id' => ['sometimes', 'integer'],
            'tenant_id' => ['sometimes', 'integer'],
        ]);

        return response()->json($this->caseFileService->queue($filters));
    }

    /**
     * Truthful segment counts for the queue's header cards.
     * GET /admin/contracts/summary
     */
    public function summary(): JsonResponse
    {
        return response()->json($this->caseFileService->counts());
    }

    /**
     * Full case-file detail for a single contract.
     * GET /admin/contracts/{contract}
     */
    public function show(Contract $contract): JsonResponse
    {
        return response()->json($this->caseFileService->detail($contract));
    }

    /**
     * Contract-scoped, decorated ledger entries plus financial summary.
     * GET /admin/contracts/{contract}/ledger
     */
    public function ledger(Contract $contract): JsonResponse
    {
        return response()->json($this->caseFileService->ledger($contract));
    }

    /**
     * Contract-scoped payment history.
     * GET /admin/contracts/{contract}/payments
     */
    public function payments(Contract $contract): JsonResponse
    {
        return response()->json(['data' => $this->caseFileService->payments($contract)]);
    }

    /**
     * Computed billing schedule (real generated periods + at most one
     * projected upcoming period).
     * GET /admin/contracts/{contract}/billing-schedule
     */
    public function billingSchedule(Contract $contract): JsonResponse
    {
        return response()->json(['data' => $this->caseFileService->billingSchedule($contract)]);
    }

    /**
     * Real lifecycle timeline, sourced from the audit log.
     * GET /admin/contracts/{contract}/timeline
     */
    public function timeline(Contract $contract): JsonResponse
    {
        return response()->json(['data' => $this->caseFileService->timeline($contract)]);
    }

    /**
     * Real contract-attached documents (truthfully empty until the media
     * pipeline is used to attach one).
     * GET /admin/contracts/{contract}/documents
     */
    public function documents(Contract $contract): JsonResponse
    {
        return response()->json(['data' => $this->caseFileService->documents($contract)]);
    }

    /**
     * Add an internal, admin-only note to a contract's case file.
     * POST /admin/contracts/{contract}/notes
     */
    public function storeNote(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $note = ContractNote::create([
            'contract_id' => $contract->id,
            'admin_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $note->load('admin');

        return response()->json([
            'message' => 'Note added',
            'note' => [
                'id' => $note->id,
                'body' => $note->body,
                'admin_id' => $note->admin_id,
                'admin_name' => $note->admin?->name,
                'created_at' => $note->created_at?->toIso8601String(),
            ],
        ], 201);
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

        // Notify both parties of admin-forced termination
        $reason = $request->reason;

        $tenantEventId = "contract-terminated:{$contract->id}:tenant";
        if (! $this->notificationService->exists($contract->tenant, $tenantEventId)) {
            $this->notificationService->create(
                user: $contract->tenant,
                type: NotificationType::CONTRACT_TERMINATED,
                title: 'Contract Terminated',
                message: "Your contract for \"{$contract->listing->title}\" has been terminated by the platform. Reason: {$reason}",
                data: [
                    'event_id' => $tenantEventId,
                    'contract_id' => $contract->id,
                    'terminated_by' => 'admin',
                    'reason' => $reason,
                ]
            );
        }

        $landlordEventId = "contract-terminated:{$contract->id}:landlord";
        if (! $this->notificationService->exists($contract->landlord, $landlordEventId)) {
            $this->notificationService->create(
                user: $contract->landlord,
                type: NotificationType::CONTRACT_TERMINATED,
                title: 'Contract Terminated',
                message: "The contract for \"{$contract->listing->title}\" has been terminated by the platform. Reason: {$reason}",
                data: [
                    'event_id' => $landlordEventId,
                    'contract_id' => $contract->id,
                    'terminated_by' => 'admin',
                    'reason' => $reason,
                ]
            );
        }

        return response()->json([
            'message' => 'Contract terminated by admin',
            'contract' => $contract->fresh(),
        ]);
    }
}
