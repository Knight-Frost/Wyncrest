<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TenantLedgerController
 *
 * Handles tenant ledger viewing (read-only).
 */
class TenantLedgerController extends Controller
{
    /**
     * Display tenant's ledger entries
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LedgerEntry::class);

        $entries = LedgerEntry::byTenant($request->user()->id)
            ->with(['contract.listing', 'relatedRentEntry'])
            ->orderBy('due_date', 'desc')
            ->get();

        return response()->json($entries);
    }

    /**
     * Display the specified ledger entry
     */
    public function show(LedgerEntry $ledgerEntry): JsonResponse
    {
        $this->authorize('view', $ledgerEntry);

        return response()->json($ledgerEntry->load(['contract.listing', 'relatedRentEntry']));
    }
}
