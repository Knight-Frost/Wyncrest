<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordManualPaymentRequest;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Services\LandlordLedgerService;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordLedgerController
 *
 * Backs the landlord Rent Ledger console (Balances · Transactions ·
 * Statements). Read-only over the immutable ledger, with one write path:
 * recording a manual/offline payment against one of the landlord's own open
 * rent/late-fee entries (LedgerService::recordManualPayment). Every money
 * figure is derived by LedgerComputationEngine / LandlordLedgerService; this
 * controller stays thin and never sums amount_cents itself.
 */
class LandlordLedgerController extends Controller
{
    public function __construct(
        protected LedgerComputationEngine $engine,
        protected LedgerService $ledgerService,
        protected LandlordLedgerService $landlordLedger
    ) {}

    /**
     * Main ledger payload: every entry (Transactions tab), the per-contract
     * balance rollup (Balances / Statements tabs), and the KPI summary.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LedgerEntry::class);

        $landlordId = $request->user()->id;

        $entries = LedgerEntry::byLandlord($landlordId)
            ->with(['contract.listing.unit.property', 'tenant', 'relatedRentEntry'])
            ->orderBy('due_date', 'desc')
            ->get();

        // Running balances must be computed chronologically across the whole set,
        // then mapped back onto the display order (due_date desc).
        $balances = $this->engine->computeRunningBalances($entries);

        $payload = $entries->map(
            fn (LedgerEntry $entry) => $this->engine->decorateEntry($entry, $balances[$entry->id] ?? null)
        );

        $contractBalances = $this->landlordLedger->balances($landlordId);
        $tenantsOverdue = collect($contractBalances)->where('overdue_cents', '>', 0)->count();

        return response()->json([
            'entries' => $payload->values(),
            'balances' => $contractBalances,
            'summary' => $this->landlordLedger->summary($landlordId, $tenantsOverdue),
        ]);
    }

    /**
     * Single-entry "case file": the decorated entry plus its append-only
     * audit trail and any ledger entries in a real relationship with it
     * (its late fee / payment, or the obligation a payment settles).
     */
    public function show(LedgerEntry $ledgerEntry): JsonResponse
    {
        $this->authorize('view', $ledgerEntry);

        $ledgerEntry->load(['contract.listing.unit.property', 'tenant', 'relatedRentEntry']);

        $contractEntries = LedgerEntry::where('contract_id', $ledgerEntry->contract_id)->get();
        $balances = $this->engine->computeRunningBalances($contractEntries);

        return response()->json(array_merge(
            $this->engine->decorateEntry($ledgerEntry, $balances[$ledgerEntry->id] ?? null),
            [
                'audit_trail' => $this->auditTrail($ledgerEntry),
                'linked_entries' => $this->linkedEntries($ledgerEntry),
            ]
        ));
    }

    /**
     * Tenant / contract statement for one billing month (defaults to the
     * current month). Owner-scoped via the contract policy.
     */
    public function contractStatement(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        [$year, $month] = $this->resolvePeriod($request);

        $contract->load(['tenant', 'listing.unit.property']);

        return response()->json($this->landlordLedger->contractStatement($contract, $year, $month));
    }

    /**
     * Property statement for one billing month — money by property, broken
     * down by unit. Owner-scoped via the property policy.
     */
    public function propertyStatement(Request $request, Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        [$year, $month] = $this->resolvePeriod($request);

        return response()->json($this->landlordLedger->propertyStatement($property, $year, $month));
    }

    /**
     * Record a manual/offline payment (cash, mobile money, bank transfer)
     * against one of the landlord's own open rent/late-fee entries. Always
     * settles the entry's full amount — Wyncrest does not support partial
     * payments.
     */
    public function recordPayment(RecordManualPaymentRequest $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        $this->authorize('recordPayment', $ledgerEntry);

        $paymentEntry = $this->ledgerService->recordManualPayment(
            $ledgerEntry,
            PaymentMethod::from($request->validated('method')),
            $request->validated('reference'),
            $request->user()
        );

        $ledgerEntry->refresh();

        // Running balance must be replayed across the FULL contract history,
        // not just these two rows, to stay correct (mirrors index()/show()).
        $contractEntries = LedgerEntry::where('contract_id', $ledgerEntry->contract_id)->get();
        $balances = $this->engine->computeRunningBalances($contractEntries);

        return response()->json([
            'entry' => $this->engine->decorateEntry($ledgerEntry, $balances[$ledgerEntry->id] ?? null),
            'payment' => $this->engine->decorateEntry($paymentEntry, $balances[$paymentEntry->id] ?? null),
        ], 201);
    }

    /**
     * Resolve the requested statement period, defaulting to the current
     * calendar month.
     *
     * @return array{0:int,1:int}
     */
    private function resolvePeriod(Request $request): array
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        return [
            (int) ($validated['year'] ?? now()->year),
            (int) ($validated['month'] ?? now()->month),
        ];
    }

    /**
     * This entry's own creation/status-transition history from the
     * append-only audit log — never a fabricated activity feed.
     *
     * @return array<int,array<string,mixed>>
     */
    private function auditTrail(LedgerEntry $entry): array
    {
        return AuditLog::where('subject_type', LedgerEntry::class)
            ->where('subject_id', $entry->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'severity' => $log->severity,
                'actor' => $this->actorName($log),
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Other ledger entries in a real relationship with this one: children
     * that reference it via related_rent_entry_id (its late fee and/or
     * payment), plus the obligation it settles if this entry IS a payment
     * or late fee.
     *
     * @return array<int,array<string,mixed>>
     */
    private function linkedEntries(LedgerEntry $entry): array
    {
        $linked = LedgerEntry::where('related_rent_entry_id', $entry->id)->get();

        if ($entry->related_rent_entry_id) {
            $parent = LedgerEntry::find($entry->related_rent_entry_id);
            if ($parent) {
                $linked->push($parent);
            }
        }

        return $this->engine->decorateEntries($linked)->values()->all();
    }

    private function actorName(AuditLog $log): string
    {
        if (! $log->actor_type || ! $log->actor_id) {
            return 'System';
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $actor */
        $actor = $log->actor_type::query()->find($log->actor_id);
        if (! $actor) {
            return 'System';
        }

        return $actor->full_name ?? $actor->name ?? $actor->email ?? 'System';
    }
}
