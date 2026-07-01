<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogDetailResource;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditController extends Controller
{
    public function __construct(private readonly AuditLogService $service) {}

    /**
     * Paginated list of audit logs with enriched derived fields.
     *
     * Returns flat shape: { data, current_page, last_page, per_page, total }
     * so the frontend can rely on a stable, predictable envelope.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'severity' => ['nullable', 'in:info,warning,critical'],
            'area' => ['nullable', 'string', 'max:100'],
            'actor_role' => ['nullable', 'in:admin,landlord,tenant,user,system'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:newest,oldest'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Client IANA timezone so calendar-day filters resolve on the user's
            // clock (validated against the tz list inside the service).
            'tz' => ['nullable', 'string', 'max:64'],
        ]);

        $paginator = $this->service->paginate($filters);

        // Build flat envelope the frontend expects
        return response()->json([
            'data' => AuditLogResource::collection($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Full detail for a single audit log entry.
     * Returns as flat JSON (no wrapping 'data' key) to match the index envelope.
     */
    public function show(AuditLog $auditLog): JsonResponse
    {
        $resource = new AuditLogDetailResource($auditLog->load(['actor', 'subject']));

        return response()->json($resource->toArray(request()));
    }

    /**
     * Summary metrics and insights for the Audit & Activity Center header.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tz' => ['nullable', 'string', 'max:64'],
        ]);

        return response()->json($this->service->summary($validated['tz'] ?? null));
    }

    /**
     * CSV export of filtered audit logs (max 5 000 rows).
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'severity' => ['nullable', 'in:info,warning,critical'],
            'area' => ['nullable', 'string', 'max:100'],
            'actor_role' => ['nullable', 'in:admin,landlord,tenant,user,system'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:newest,oldest'],
            'tz' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->service->export($filters);
    }
}
