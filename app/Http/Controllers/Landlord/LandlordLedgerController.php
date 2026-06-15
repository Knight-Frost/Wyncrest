<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordLedgerController
 *
 * Handles landlord ledger viewing (read-only).
 */
class LandlordLedgerController extends Controller
{
    /**
     * Display landlord's ledger entries
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LedgerEntry::class);

        $entries = LedgerEntry::byLandlord($request->user()->id)
            ->with(['contract.listing', 'tenant', 'relatedRentEntry'])
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

        return response()->json($ledgerEntry->load(['contract.listing', 'tenant', 'relatedRentEntry']));
    }
}
