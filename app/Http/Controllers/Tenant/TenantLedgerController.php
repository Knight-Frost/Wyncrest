<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerComputationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TenantLedgerController
 *
 * Handles tenant ledger viewing (read-only). Entries are decorated with
 * display-safe amounts and a running balance by LedgerComputationEngine.
 */
class TenantLedgerController extends Controller
{
    public function __construct(
        protected LedgerComputationEngine $engine
    ) {}

    /**
     * Display tenant's ledger entries
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LedgerEntry::class);

        $tenantId = $request->user()->id;

        $filters = $request->validate([
            // why: lets a lease detail page scope the ledger to a single
            // contract; byTenant() below still constrains rows to this
            // tenant regardless of what contract_id is passed.
            'contract_id' => ['sometimes', 'uuid'],
        ]);

        $entries = LedgerEntry::byTenant($tenantId)
            ->when($filters['contract_id'] ?? null, fn ($query, $contractId) => $query->where('contract_id', $contractId))
            ->with(['contract.listing', 'relatedRentEntry'])
            ->orderBy('due_date', 'desc')
            ->get();

        $balances = $this->engine->computeRunningBalances($entries);

        $payload = $entries->map(fn (LedgerEntry $entry) => $this->engine->decorateEntry($entry, $balances[$entry->id] ?? null));

        return response()->json([
            'entries' => $payload->values(),
            'summary' => $this->engine->computePlatformFinancialSummary(array_filter([
                'tenant_id' => $tenantId,
                'contract_id' => $filters['contract_id'] ?? null,
            ])),
        ]);
    }

    /**
     * Display the specified ledger entry
     */
    public function show(LedgerEntry $ledgerEntry): JsonResponse
    {
        $this->authorize('view', $ledgerEntry);

        $ledgerEntry->load(['contract.listing', 'relatedRentEntry']);

        $contractEntries = LedgerEntry::where('contract_id', $ledgerEntry->contract_id)->get();
        $balances = $this->engine->computeRunningBalances($contractEntries);

        return response()->json($this->engine->decorateEntry($ledgerEntry, $balances[$ledgerEntry->id] ?? null));
    }
}
