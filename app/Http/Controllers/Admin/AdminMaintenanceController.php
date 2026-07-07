<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignMaintenanceCaseOwnerRequest;
use App\Http\Requests\EscalateMaintenanceRequestRequest;
use App\Http\Requests\OverrideCloseMaintenanceRequestRequest;
use App\Http\Requests\OverrideReopenMaintenanceRequestRequest;
use App\Http\Requests\StoreMaintenanceAdminNoteRequest;
use App\Models\Admin;
use App\Models\MaintenanceAdminNote;
use App\Models\MaintenanceRequest;
use App\Services\Admin\AdminMaintenanceActionService;
use App\Services\Admin\MaintenanceOverviewService;
use App\Services\AuditService;
use App\Services\MaintenanceService;
use App\Support\Csv\CsvWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminMaintenanceController
 *
 * Admin maintenance oversight. Viewing (index/summary/show/analytics) is a
 * baseline admin privilege (no admin.can: gate), matching the existing
 * Users/Contracts/Ledger convention. Mutating actions (assign/escalate/notes/
 * override/export) require the manage_maintenance capability. Platform-wide
 * oversight aggregates are restricted to super admins only — a materially
 * different privilege tier than manage_maintenance, same reasoning as
 * manage_access's team-management actions.
 */
class AdminMaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceOverviewService $service,
        private readonly AdminMaintenanceActionService $actionService,
        private readonly MaintenanceService $maintenanceService,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,urgent,overdue,waiting,escalated,unassigned,all'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json(['data' => $this->service->cases($filters)]);
    }

    public function summary(): JsonResponse
    {
        return response()->json($this->service->summary());
    }

    public function show(MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        return response()->json(['data' => $this->service->detail($maintenanceRequest)]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return response()->json($this->service->analytics($filters));
    }

    /**
     * Platform-wide oversight aggregates — super admin only.
     */
    public function oversight(Request $request): JsonResponse
    {
        abort_unless(
            $request->user() instanceof Admin && $request->user()->is_super_admin,
            403,
            'Platform maintenance oversight is restricted to super admins.',
        );

        return response()->json($this->service->oversight());
    }

    public function assignCaseOwner(AssignMaintenanceCaseOwnerRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $handlingAdmin = Admin::findOrFail($request->validated('handling_admin_id'));

        $updated = $this->actionService->assignCaseOwner($maintenanceRequest, $request->user(), $handlingAdmin);

        return response()->json(['data' => $this->service->detail($updated)]);
    }

    public function escalate(EscalateMaintenanceRequestRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $updated = $this->actionService->escalate($maintenanceRequest, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->service->detail($updated)]);
    }

    public function clearEscalation(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $updated = $this->actionService->clearEscalation($maintenanceRequest, $request->user());

        return response()->json(['data' => $this->service->detail($updated)]);
    }

    public function storeNote(StoreMaintenanceAdminNoteRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $note = MaintenanceAdminNote::create([
            'maintenance_request_id' => $maintenanceRequest->id,
            'admin_id' => $request->user()->id,
            'body' => $request->validated('body'),
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

    public function overrideClose(OverrideCloseMaintenanceRequestRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $updated = $this->maintenanceService->adminOverrideClose($maintenanceRequest, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->service->detail($updated)]);
    }

    public function overrideReopen(OverrideReopenMaintenanceRequestRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $updated = $this->maintenanceService->adminOverrideReopen($maintenanceRequest, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->service->detail($updated)]);
    }

    /**
     * Scoped CSV export of maintenance requests, platform-wide (no landlord
     * constraint). Audited before streaming; the checksum is a real SHA-256
     * of the exported bytes, mirroring LandlordMaintenanceController::export().
     */
    public function export(Request $request): Response
    {
        $filters = $request->validate([
            'scope' => ['required', 'in:filtered,property,landlord,single,full'],
            'status' => ['sometimes', 'string'],
            'priority' => ['sometimes', 'string'],
            'property_id' => ['sometimes', 'integer'],
            'landlord_id' => ['sometimes', 'integer'],
            'maintenance_request_id' => ['sometimes', 'integer'],
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = MaintenanceRequest::query()->with(['tenant', 'landlord', 'property', 'unit', 'handlingAdmin']);

        switch ($filters['scope']) {
            case 'property':
                $query->where('property_id', $filters['property_id'] ?? 0);
                break;
            case 'landlord':
                $query->where('landlord_id', $filters['landlord_id'] ?? 0);
                break;
            case 'single':
                $query->where('id', $filters['maintenance_request_id'] ?? 0);
                break;
            case 'filtered':
                if (! empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (! empty($filters['priority'])) {
                    $query->where('priority', $filters['priority']);
                }
                break;
            case 'full':
            default:
                break;
        }

        $requests = $query->orderByDesc('submitted_at')->get();

        $header = ['ID', 'Title', 'Property', 'Unit', 'Tenant', 'Landlord', 'Category', 'Priority', 'Status', 'Handling Admin', 'Reported', 'Resolved', 'Total cost (GHS)'];
        $rows = $requests->map(fn (MaintenanceRequest $r) => [
            $r->id,
            $r->title,
            $r->property?->name,
            $r->unit?->unit_number,
            $r->tenant?->full_name,
            $r->landlord?->full_name,
            $r->category->value,
            $r->priority->value,
            $r->status->value,
            $r->handlingAdmin?->name,
            $r->submitted_at?->format('Y-m-d'),
            $r->resolved_at?->format('Y-m-d'),
            $r->total_cost_cents !== null ? number_format($r->total_cost_cents / 100, 2, '.', '') : '',
        ])->all();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, CsvWriter::sanitizeRow($header));
        foreach ($rows as $row) {
            fputcsv($handle, CsvWriter::sanitizeRow($row));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $checksum = hash('sha256', $csv);

        $this->auditService->log(
            actor: $request->user(),
            action: 'admin_maintenance_exported',
            subject: null,
            description: "Admin maintenance export generated: {$requests->count()} requests.",
            severity: 'info',
            metadata: [
                'scope' => $filters['scope'],
                'row_count' => $requests->count(),
                'reason' => $filters['reason'] ?? null,
                'checksum' => $checksum,
            ],
        );

        $filename = 'admin-maintenance-'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Export-Checksum' => $checksum,
            'X-Export-Row-Count' => (string) $requests->count(),
            'Access-Control-Expose-Headers' => 'X-Export-Checksum, X-Export-Row-Count',
        ]);
    }
}
