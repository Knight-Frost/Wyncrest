<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateLateFeeRequest;
use App\Http\Requests\WaiveLedgerEntryRequest;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Services\AuditService;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\Ledger\LedgerReconciliationService;
use App\Services\LedgerService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AdminLedgerController
 *
 * Handles admin ledger operations:
 * - View all entries (with filters), decorated with display-safe amounts
 * - Platform-wide financial summary, computed over the FULL filtered set
 *   (never just the current page) so it can never disagree with the table
 * - Single-entry case file (audit trail, linked entries, notifications)
 * - Generate late fees / waive entries
 * - CSV export (audit-logged)
 *
 * All money math is delegated to LedgerComputationEngine — this controller
 * does not compute totals itself.
 */
class AdminLedgerController extends Controller
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected NotificationService $notificationService,
        protected LedgerComputationEngine $engine,
        protected LedgerReconciliationService $reconciliation,
        protected AuditService $auditService
    ) {}

    /**
     * Display all ledger entries (with filters), plus a platform-wide
     * summary computed independently of pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        $query = $this->engine->applyFilters(
            LedgerEntry::with(['contract.listing.unit.property', 'tenant', 'landlord', 'relatedRentEntry']),
            $filters
        )->orderBy('due_date', 'desc');

        $entries = $query->paginate(50);

        // Running balance is only meaningful within a contract's full
        // history, so scope a targeted lookup to the contracts present on
        // this page rather than replaying the entire platform ledger.
        $contractIds = collect($entries->items())->pluck('contract_id')->unique()->values();
        $contractHistory = LedgerEntry::whereIn('contract_id', $contractIds)->get();
        $balances = $this->engine->computeRunningBalances($contractHistory);

        $decorated = collect($entries->items())->map(
            fn (LedgerEntry $entry) => $this->engine->decorateEntry($entry, $balances[$entry->id] ?? null)
        );

        $paginated = $entries->toArray();
        $paginated['data'] = $decorated->values()->all();

        return response()->json(array_merge($paginated, [
            // Computed over the full filtered set via SQL aggregates, not
            // just this page — this is what fixes the negative "Total
            // Collected" bug (it was previously summed client-side over a
            // single 50-row page of mixed-sign entry types).
            'summary' => $this->engine->computePlatformFinancialSummary($filters),
        ]));
    }

    /**
     * Display the specified ledger entry — the ledger "case file". Every
     * section is derived from real, queryable data: the append-only audit
     * log (this entry's own creation/status-transition history), the
     * notifications actually sent about it, and any other ledger entries
     * that reference it (or that it references) via related_rent_entry_id.
     * There is no dispute/payout/processor section here because Wyncrest's
     * ledger schema does not model those concepts.
     */
    public function show(LedgerEntry $ledgerEntry): JsonResponse
    {
        $ledgerEntry->load(['contract.listing.unit.property', 'tenant', 'landlord', 'relatedRentEntry']);

        $contractHistory = LedgerEntry::where('contract_id', $ledgerEntry->contract_id)->get();
        $balances = $this->engine->computeRunningBalances($contractHistory);

        return response()->json(array_merge(
            $this->engine->decorateEntry($ledgerEntry, $balances[$ledgerEntry->id] ?? null),
            [
                'audit_trail' => $this->auditTrail($ledgerEntry),
                'linked_entries' => $this->linkedEntries($ledgerEntry),
                'notifications' => $this->notificationsFor($ledgerEntry),
            ]
        ));
    }

    /**
     * Full reconciliation report (accepts the same filters as index()).
     * Route: GET /admin/ledger/reconciliation
     */
    public function reconciliation(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tenant_id' => ['sometimes', 'integer'],
            'landlord_id' => ['sometimes', 'integer'],
            'contract_id' => ['sometimes', 'uuid'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);

        return response()->json($this->reconciliation->run($filters));
    }

    /**
     * Generate late fee for an overdue rent entry
     */
    public function generateLateFee(GenerateLateFeeRequest $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        try {
            $lateFee = $this->ledgerService->generateLateFee(
                $ledgerEntry,
                $request->amount_cents,
                auth('admin')->user()
            );

            // Notify the tenant of the late fee
            $tenant = $ledgerEntry->tenant;
            if ($tenant) {
                $eventId = "late-fee-added:{$lateFee->id}";
                if (! $this->notificationService->exists($tenant, $eventId)) {
                    $amount = 'GH₵ '.number_format($request->amount_cents / 100, 2);
                    $this->notificationService->create(
                        user: $tenant,
                        type: NotificationType::LATE_FEE_ADDED,
                        title: 'Late Fee Added',
                        message: "A late fee of {$amount} has been added to your account for an overdue rent payment.",
                        data: [
                            'event_id' => $eventId,
                            'late_fee_entry_id' => $lateFee->id,
                            'related_rent_entry_id' => $ledgerEntry->id,
                            'amount_cents' => $request->amount_cents,
                        ]
                    );
                }
            }

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

    /**
     * Waive a pending/overdue rent or late fee entry. The obligation is not
     * deleted — LedgerEntry::transitionStatus() flips it to WAIVED, which is
     * a terminal state, and the reason is written permanently to the audit
     * log via LedgerService::waiveEntry().
     */
    public function waive(WaiveLedgerEntryRequest $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        if (! $ledgerEntry->type->isObligation() || ! $ledgerEntry->status->isDue()) {
            return response()->json([
                'message' => 'Only a pending or overdue rent/late fee entry can be waived.',
            ], 422);
        }

        $this->ledgerService->waiveEntry($ledgerEntry, $request->reason, auth('admin')->user());

        return response()->json($this->engine->decorateEntry($ledgerEntry->fresh(['contract', 'tenant', 'landlord', 'relatedRentEntry'])));
    }

    /**
     * CSV export of the (optionally filtered) ledger — every filter index()
     * accepts is honored here too, so "export what I'm looking at" is exact.
     * The export itself is written to the audit log (actor, filters, row
     * count) since it can move sensitive financial data out of the app.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);

        $entries = $this->engine->applyFilters(
            LedgerEntry::with(['contract.listing.unit.property', 'tenant', 'landlord']),
            $filters
        )->orderBy('due_date', 'desc')->get();

        $balances = $this->engine->computeRunningBalances(LedgerEntry::whereIn(
            'contract_id',
            $entries->pluck('contract_id')->unique()->values()
        )->get());

        $this->auditService->log(
            actor: auth('admin')->user(),
            action: 'ledger_exported',
            subject: null,
            description: "Ledger export generated: {$entries->count()} entries.",
            severity: 'warning',
            metadata: ['filters' => $filters, 'row_count' => $entries->count()]
        );

        $rows = $entries->map(function (LedgerEntry $entry) use ($balances) {
            $unit = $entry->contract?->listing?->unit;
            $property = $unit?->property;

            return [
                $this->engine->reference($entry),
                $entry->due_date?->format('Y-m-d'),
                $this->engine->displayLabel($entry),
                $entry->tenant?->full_name,
                $entry->landlord?->full_name,
                $property?->name,
                $unit?->unit_number,
                $entry->contract_id,
                number_format($this->engine->displayAmountCents($entry) / 100, 2, '.', ''),
                number_format($this->engine->balanceImpactCents($entry) / 100, 2, '.', ''),
                $entry->status->value,
                number_format(($balances[$entry->id] ?? 0) / 100, 2, '.', ''),
            ];
        })->all();

        $header = ['Reference', 'Due date', 'Type', 'Tenant', 'Landlord', 'Property', 'Unit', 'Contract ID', 'Amount', 'Balance impact', 'Status', 'Balance after'];

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'wyncrest-ledger.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedFilters(Request $request): array
    {
        // Normalize boolean query-string flags before validation: the SPA
        // sends these as JS booleans, which axios/browsers serialize as the
        // literal string "true"/"false" — a value Laravel's `boolean` rule
        // rejects (it only accepts true/false/1/0/"1"/"0"). $request->boolean()
        // correctly interprets all of those forms, so coerce first.
        foreach (['overdue_only', 'payments_only', 'charges_only'] as $flag) {
            if ($request->has($flag)) {
                $request->merge([$flag => $request->boolean($flag)]);
            }
        }

        return $request->validate([
            'type' => ['sometimes', 'string', 'in:rent,late_fee,payment,refund'],
            'status' => ['sometimes', 'string', 'in:pending,paid,overdue,waived'],
            // why: tenant_id/landlord_id are bigint users.id FKs; contract_id is a UUID PK. (June 2026)
            'tenant_id' => ['sometimes', 'integer'],
            'landlord_id' => ['sometimes', 'integer'],
            'contract_id' => ['sometimes', 'uuid'],
            'property_id' => ['sometimes', 'integer'],
            'unit_id' => ['sometimes', 'integer'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'overdue_only' => ['sometimes', 'boolean'],
            'payments_only' => ['sometimes', 'boolean'],
            'charges_only' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:255'],
        ]);
    }

    /**
     * This entry's own creation/status-transition history from the
     * append-only audit log — never a fabricated "case activity" feed.
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

    /**
     * Real notifications actually sent about this entry — matched by every
     * data key the notification listeners use when they reference a ledger
     * entry (they are not consistent, so all are checked).
     *
     * @return array<int,array<string,mixed>>
     */
    private function notificationsFor(LedgerEntry $entry): array
    {
        if (! $entry->tenant_id) {
            return [];
        }

        return Notification::where('user_id', $entry->tenant_id)
            ->where(function ($q) use ($entry) {
                $q->where('data->ledger_entry_id', $entry->id)
                    ->orWhere('data->payment_entry_id', $entry->id)
                    ->orWhere('data->rent_entry_id', $entry->id)
                    ->orWhere('data->late_fee_entry_id', $entry->id)
                    ->orWhere('data->related_rent_entry_id', $entry->id);
            })
            ->orderBy('created_at')
            ->get()
            ->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type->value,
                'title' => $n->title,
                'message' => $n->message,
                'delivered_at' => $n->delivered_at?->toIso8601String(),
                'sms_delivered_at' => $n->sms_delivered_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
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
