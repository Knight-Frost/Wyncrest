<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateLateFeeRequest;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminLedgerController
 *
 * Handles admin ledger operations:
 * - View all entries (with filters)
 * - Generate late fees
 */
class AdminLedgerController extends Controller
{
    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    /**
     * Display all ledger entries (with filters)
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'string', 'in:rent,late_fee'],
            'status' => ['sometimes', 'string', 'in:pending,paid,overdue,waived'],
            'tenant_id' => ['sometimes'],
            'landlord_id' => ['sometimes'],
            'contract_id' => ['sometimes'],
        ]);

        $query = LedgerEntry::with(['contract', 'tenant', 'landlord', 'relatedRentEntry'])
            ->orderBy('due_date', 'desc');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (! empty($filters['landlord_id'])) {
            $query->where('landlord_id', $filters['landlord_id']);
        }

        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', $filters['contract_id']);
        }

        $entries = $query->paginate(50);

        return response()->json($entries);
    }

    /**
     * Display the specified ledger entry
     */
    public function show(LedgerEntry $ledgerEntry): JsonResponse
    {
        return response()->json($ledgerEntry->load(['contract', 'tenant', 'landlord', 'relatedRentEntry']));
    }

    /**
     * Generate late fee for an overdue rent entry
     */
    public function generateLateFee(GenerateLateFeeRequest $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        try {
            $lateFee = $this->ledgerService->generateLateFee(
                $ledgerEntry,
                $request->amount_cents
            );

            return response()->json([
                'message' => 'Late fee generated successfully',
                'late_fee' => $lateFee->load(['relatedRentEntry']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
