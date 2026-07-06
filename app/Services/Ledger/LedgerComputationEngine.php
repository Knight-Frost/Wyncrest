<?php

namespace App\Services\Ledger;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * LedgerComputationEngine
 *
 * THE single source of financial truth for Wyncrest. Every user-facing money
 * figure derived from the ledger — dashboard cards, the admin ledger page,
 * tenant balances, landlord KPIs, analytics totals — must be computed here
 * and nowhere else. No controller, service, or frontend component should
 * reimplement this math.
 *
 * ── Canonical sign convention (do not "fix" this without reading LEDGER.md) ──
 *
 *   rent / late_fee   → amount_cents is POSITIVE   (increases balance owed)
 *   payment           → amount_cents is NEGATIVE   (reduces balance owed)
 *   refund            → amount_cents is POSITIVE   (legacy convention; type
 *                        is defined in the schema but no service currently
 *                        creates refund entries — see class docblock note)
 *
 * A tenant's/contract's balance is always the sum of `amount_cents` across
 * their entries (waived obligations contribute 0). Because a settled month
 * carries both a +rent entry and a matching -payment entry, a fully paid
 * contract naturally nets to zero without any special-casing.
 *
 * ── Why this class exists ──
 *
 * Before this engine, "collected"/"outstanding"/"overdue" were computed
 * ad-hoc in half a dozen places (dashboard controllers, analytics, two
 * separate frontend pages), several of which assumed PAYMENT entries were
 * stored positive when they are actually stored negative. That mismatch
 * produced a negative "Total Collected" on the admin ledger page and an
 * inverted running balance on the landlord ledger. See CLAUDE.md /
 * docs/LEDGER.md for the incident writeup.
 *
 * ── display vs. balance vs. signed ──
 *
 * `signed_amount_cents`  — the raw immutable value exactly as stored.
 * `balance_impact_cents` — the entry's effect on the contract balance
 *                          (same as signed, except waived obligations = 0).
 * `display_amount_cents` — always positive; what a human should see in an
 *                          "Amount" column ("Payment received: GH₵ 6,000").
 *
 * The frontend must render `display_amount_cents` in primary UI and only
 * show `balance_impact_cents` when explicitly labelled "Balance impact".
 */
class LedgerComputationEngine
{
    /* ────────────────────────────────────────────────────────────────────
     * Per-entry display semantics
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * The raw, immutable, signed accounting value — exactly as stored.
     */
    public function signedAmountCents(LedgerEntry $entry): int
    {
        return (int) $entry->amount_cents;
    }

    /**
     * Always-positive, human-readable amount for display in primary UI.
     */
    public function displayAmountCents(LedgerEntry $entry): int
    {
        return abs($this->signedAmountCents($entry));
    }

    /**
     * Signed effect of this single entry on its contract's balance.
     *
     * - rent / late_fee, not waived → +amount (increases balance)
     * - rent / late_fee, waived     → 0 (written off, no balance effect)
     * - payment                     → amount as stored (already negative)
     * - refund                      → +amount (legacy; unused today)
     */
    public function balanceImpactCents(LedgerEntry $entry): int
    {
        $amount = $this->signedAmountCents($entry);

        return match ($entry->type) {
            LedgerType::RENT, LedgerType::LATE_FEE => $entry->status === LedgerStatus::WAIVED ? 0 : $amount,
            LedgerType::PAYMENT => $amount,
            LedgerType::REFUND => $amount,
        };
    }

    /**
     * Direction of money movement, for display iconography/copy.
     * 'charge' (money owed increases) | 'payment' (money received,
     * reduces what's owed) | 'refund' (money returned).
     */
    public function direction(LedgerEntry $entry): string
    {
        return match ($entry->type) {
            LedgerType::RENT, LedgerType::LATE_FEE => 'charge',
            LedgerType::PAYMENT => 'payment',
            LedgerType::REFUND => 'refund',
        };
    }

    /**
     * Financial category, for grouping/filtering.
     */
    public function financialCategory(LedgerEntry $entry): string
    {
        return match ($entry->type) {
            LedgerType::RENT => 'rent',
            LedgerType::LATE_FEE => 'late_fee',
            LedgerType::PAYMENT => 'payment',
            LedgerType::REFUND => 'refund',
        };
    }

    /**
     * Human-readable label, safe to render directly (backend is the
     * authority on what an entry means — the frontend should not guess
     * from a signed number).
     */
    public function displayLabel(LedgerEntry $entry): string
    {
        return match ($entry->type) {
            LedgerType::RENT => 'Rent charge',
            LedgerType::LATE_FEE => 'Late fee',
            LedgerType::PAYMENT => 'Payment received',
            LedgerType::REFUND => 'Refund issued',
        };
    }

    /**
     * Normalized entry status. Wire value is unchanged (`pending` etc. is
     * deeply embedded across the frontend/tests) — this exists so a single
     * place governs the mapping if that ever needs to diverge from the
     * stored enum.
     */
    public function deriveEntryStatus(LedgerEntry $entry): string
    {
        return $entry->status->value;
    }

    /**
     * Deterministic, human-readable reference: {PREFIX}-{Ymd}-{first 6 of UUID upper}.
     * PREFIX by type: rent→INV, payment→RCPT, late_fee→FEE, refund→REF.
     */
    public function reference(LedgerEntry $entry): string
    {
        $prefix = match ($entry->type) {
            LedgerType::RENT => 'INV',
            LedgerType::PAYMENT => 'RCPT',
            LedgerType::LATE_FEE => 'FEE',
            LedgerType::REFUND => 'REF',
        };

        $date = ($entry->due_date ?? $entry->created_at)->format('Ymd');
        $idPart = strtoupper(substr((string) $entry->id, 0, 6));

        return "{$prefix}-{$date}-{$idPart}";
    }

    /**
     * Full display-ready row shape for the frontend. Optionally pass a
     * precomputed running balance (from computeRunningBalances()) so
     * callers decorating many rows don't recompute per-row.
     */
    public function decorateEntry(LedgerEntry $entry, ?int $runningBalanceCents = null): array
    {
        return array_merge($entry->toArray(), [
            'signed_amount_cents' => $this->signedAmountCents($entry),
            'display_amount_cents' => $this->displayAmountCents($entry),
            'balance_impact_cents' => $this->balanceImpactCents($entry),
            'direction' => $this->direction($entry),
            'financial_category' => $this->financialCategory($entry),
            'display_label' => $this->displayLabel($entry),
            'status' => $this->deriveEntryStatus($entry),
            'reference' => $this->reference($entry),
            'occurred_at' => $entry->created_at?->toIso8601String(),
            'running_balance_cents' => $runningBalanceCents,
        ]);
    }

    /**
     * Decorate a collection of entries, computing running balances grouped
     * by contract (chronological replay) and mapping them back per row.
     *
     * @param  Collection<int, LedgerEntry>  $entries
     */
    public function decorateEntries(Collection $entries): Collection
    {
        $balances = $this->computeRunningBalances($entries);

        return $entries->map(fn (LedgerEntry $entry) => $this->decorateEntry($entry, $balances[$entry->id] ?? null));
    }

    /* ────────────────────────────────────────────────────────────────────
     * Running balance / contract balance
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Given a set of entries (any mix of contracts), return a map of
     * [entry id => balance_after_cents], replayed chronologically
     * (created_at asc, tie-break by id) per contract so each entry's
     * balance reflects every prior entry on the SAME contract.
     *
     * NOTE: entries must include the FULL history for each contract they
     * belong to for the running balance to be meaningful — a partial slice
     * (e.g. one page of a platform-wide query) will only be internally
     * consistent for contracts whose entries are entirely present.
     *
     * @param  Collection<int, LedgerEntry>  $entries
     * @return array<string, int>
     */
    public function computeRunningBalances(Collection $entries): array
    {
        $balances = [];

        foreach ($entries->groupBy('contract_id') as $group) {
            $sorted = $group->sort(function (LedgerEntry $a, LedgerEntry $b) {
                return [$a->created_at, (string) $a->id] <=> [$b->created_at, (string) $b->id];
            });

            $running = 0;
            foreach ($sorted as $entry) {
                $running += $this->balanceImpactCents($entry);
                $balances[$entry->id] = $running;
            }
        }

        return $balances;
    }

    /**
     * Current balance for a single contract: sum of signed amounts across
     * every entry ever recorded for it (waived obligations contribute 0).
     */
    public function computeContractBalance(string $contractId): int
    {
        return (int) LedgerEntry::where('contract_id', $contractId)
            ->get()
            ->sum(fn (LedgerEntry $e) => $this->balanceImpactCents($e));
    }

    /**
     * Current balance for a tenant across all of their contracts.
     */
    public function computeTenantBalance(int $tenantId): int
    {
        return (int) LedgerEntry::where('tenant_id', $tenantId)
            ->get()
            ->sum(fn (LedgerEntry $e) => $this->balanceImpactCents($e));
    }

    /* ────────────────────────────────────────────────────────────────────
     * Outstanding / overdue / due-soon (per contract)
     * ──────────────────────────────────────────────────────────────────── */

    public function computeOutstandingByContract(string $contractId): int
    {
        return $this->netUnpaid(LedgerEntry::where('contract_id', $contractId)->unpaid());
    }

    public function computeOverdueByContract(string $contractId): int
    {
        return $this->netUnpaid($this->overdueQuery(LedgerEntry::where('contract_id', $contractId)->unpaid()));
    }

    public function computeDueSoonByContract(string $contractId): int
    {
        return $this->computeOutstandingByContract($contractId) - $this->computeOverdueByContract($contractId);
    }

    /* ────────────────────────────────────────────────────────────────────
     * Platform / filtered aggregates — all computed with SQL sums, never
     * by loading full row sets into memory.
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Total positive rent charges billed in scope.
     */
    public function computeRentCharged(array $filters = []): int
    {
        return (int) $this->applyFilters(LedgerEntry::where('type', LedgerType::RENT), $filters)->sum('amount_cents');
    }

    /**
     * Total positive late fee charges billed in scope.
     */
    public function computeFeesCharged(array $filters = []): int
    {
        return (int) $this->applyFilters(LedgerEntry::where('type', LedgerType::LATE_FEE), $filters)->sum('amount_cents');
    }

    /**
     * Total successful payments received, ALWAYS positive. Never returns a
     * negative number regardless of how payments are stored internally.
     */
    public function computeCollected(array $filters = []): int
    {
        $sum = (int) $this->applyFilters(LedgerEntry::where('type', LedgerType::PAYMENT), $filters)->sum('amount_cents');

        return abs($sum);
    }

    /**
     * Total unpaid balance (pending + overdue rent/late_fee, not waived),
     * net of any payments already linked to a still-open obligation.
     */
    public function computeOutstanding(array $filters = []): int
    {
        return $this->netUnpaid($this->applyFilters(LedgerEntry::query()->unpaid(), $filters));
    }

    /**
     * Unpaid balance whose due date has passed, net of linked payments.
     */
    public function computeOverdue(array $filters = []): int
    {
        return $this->netUnpaid($this->overdueQuery($this->applyFilters(LedgerEntry::query()->unpaid(), $filters)));
    }

    /**
     * Unpaid balance not yet due. Derived as outstanding - overdue so the
     * two figures are always internally consistent by construction.
     */
    public function computeDueSoon(array $filters = []): int
    {
        return $this->computeOutstanding($filters) - $this->computeOverdue($filters);
    }

    /**
     * The full card set for a ledger summary view (admin/landlord/tenant).
     */
    public function computePlatformFinancialSummary(array $filters = []): array
    {
        $rentCharged = $this->computeRentCharged($filters);
        $feesCharged = $this->computeFeesCharged($filters);
        $collected = $this->computeCollected($filters);
        $outstanding = $this->computeOutstanding($filters);
        $overdue = $this->computeOverdue($filters);
        $dueSoon = $outstanding - $overdue;

        return [
            'rent_charged_cents' => $rentCharged,
            'fees_charged_cents' => $feesCharged,
            'collected_cents' => $collected,
            'outstanding_cents' => $outstanding,
            'overdue_cents' => $overdue,
            'due_soon_cents' => $dueSoon,
            'entry_count' => $this->applyFilters(LedgerEntry::query(), $filters)->count(),
        ];
    }

    /**
     * Overdue entries platform-wide, eager-loaded with everything a dashboard
     * row needs (tenant, landlord, property) and decorated with a `days_late`
     * key. Shared by the admin dashboard's Rent Risk attention-queue card and
     * the Rent Risk Monitor table so the two views can never disagree about
     * which entries are overdue or by how much.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overdueCases(?int $limit = null): Collection
    {
        $query = $this->overdueQuery(LedgerEntry::query()->unpaid())
            ->with(['contract.listing.unit.property', 'tenant', 'landlord'])
            ->orderBy('due_date', 'asc');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $today = Carbon::today();

        return $query->get()->map(function (LedgerEntry $entry) use ($today) {
            $decorated = $this->decorateEntry($entry);
            $decorated['days_late'] = $entry->due_date ? (int) abs($entry->due_date->diffInDays($today)) : 0;

            return $decorated;
        });
    }

    /* ────────────────────────────────────────────────────────────────────
     * Status derivation
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Payment status for a contract's obligations: 'paid' (no unpaid
     * obligations and at least one entry exists), 'overdue' (unpaid past
     * due), 'open' (unpaid, not yet due), or 'no_history'.
     *
     * NOTE: Wyncrest does not currently support partial payment of a single
     * obligation (payments are always full-amount), so 'partially_paid' is
     * not produced. If partial payments are introduced, this is the single
     * place to add that branch.
     */
    public function deriveContractPaymentStatus(string $contractId): string
    {
        $hasHistory = LedgerEntry::where('contract_id', $contractId)->exists();
        if (! $hasHistory) {
            return 'no_history';
        }

        $overdue = $this->computeOverdueByContract($contractId);
        if ($overdue > 0) {
            return 'overdue';
        }

        $outstanding = $this->computeOutstandingByContract($contractId);
        if ($outstanding > 0) {
            return 'open';
        }

        return 'paid';
    }

    /* ────────────────────────────────────────────────────────────────────
     * Filtering helpers
     * ──────────────────────────────────────────────────────────────────── */

    /**
     * Apply the common filter set used by admin/landlord/analytics queries.
     * Supported keys: tenant_id, landlord_id, contract_id, property_id,
     * unit_id, status, type, date_from, date_to (against created_at),
     * overdue_only, payments_only, charges_only, search.
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (! empty($filters['landlord_id'])) {
            $query->where('landlord_id', $filters['landlord_id']);
        }

        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', $filters['contract_id']);
        }

        if (! empty($filters['property_id']) || ! empty($filters['unit_id'])) {
            $query->whereHas('contract.listing.unit', function (Builder $q) use ($filters) {
                if (! empty($filters['property_id'])) {
                    $q->where('property_id', $filters['property_id']);
                }
                if (! empty($filters['unit_id'])) {
                    $q->where('id', $filters['unit_id']);
                }
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['overdue_only'])) {
            $this->overdueQuery($query);
        }

        if (! empty($filters['payments_only'])) {
            $query->where('type', LedgerType::PAYMENT);
        }

        if (! empty($filters['charges_only'])) {
            $query->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value]);
        }

        // Platform-wide free-text search (admin ledger). Applied here (not
        // just in the controller) so the summary — computed via the same
        // applyFilters() call — never disagrees with the filtered table.
        if (! empty($filters['search'])) {
            $like = '%'.strtolower(trim((string) $filters['search'])).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereHas('tenant', function (Builder $tq) use ($like) {
                    $tq->whereRaw('LOWER(first_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                })->orWhereHas('landlord', function (Builder $lq) use ($like) {
                    $lq->whereRaw('LOWER(first_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                })->orWhereHas('contract.listing.unit.property', function (Builder $pq) use ($like) {
                    $pq->whereRaw('LOWER(name) LIKE ?', [$like]);
                });
            });
        }

        return $query;
    }

    /**
     * Constrain a query to entries that are overdue: explicitly OVERDUE
     * status, or still PENDING with a due date already in the past (covers
     * the window before the scheduled mark-overdue job has run). Mirrors
     * LedgerEntry::isOverdue().
     */
    protected function overdueQuery(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->where(function (Builder $q) use ($today) {
            $q->where('status', LedgerStatus::OVERDUE)
                ->orWhere(function (Builder $q2) use ($today) {
                    $q2->where('status', LedgerStatus::PENDING)->whereDate('due_date', '<', $today);
                });
        });
    }

    /**
     * Sum a query of still-open (pending/overdue) RENT/LATE_FEE obligations,
     * net of any PAYMENT entries already linked to one of those obligations
     * via related_rent_entry_id.
     *
     * Wyncrest's real payment flow always pays an obligation in full and
     * flips its status to PAID in the same operation (PaymentService::
     * recordSuccessfulPayment), so today this correction is a no-op for
     * every reachable state — a linked payment never coexists with a still-
     * PENDING/OVERDUE obligation. It exists so outstanding/overdue stay
     * correct if partial payments are ever introduced: a $60 charge with a
     * linked $25 payment must show $35 outstanding, not the full $60, even
     * though the charge's own status hasn't (yet) flipped to paid.
     */
    protected function netUnpaid(Builder $obligationsQuery): int
    {
        // The .unpaid() scope already excludes WAIVED entries, so no row in
        // this query is ever waived — a plain SQL sum is safe here (no need
        // to load full rows through balanceImpactCents()).
        $obligationIds = (clone $obligationsQuery)->pluck('id');
        $gross = (int) $obligationsQuery->sum('amount_cents');

        if ($obligationIds->isEmpty()) {
            return $gross;
        }

        $linkedPayments = (int) LedgerEntry::where('type', LedgerType::PAYMENT)
            ->whereIn('related_rent_entry_id', $obligationIds)
            ->sum('amount_cents');

        return $gross + $linkedPayments;
    }
}
