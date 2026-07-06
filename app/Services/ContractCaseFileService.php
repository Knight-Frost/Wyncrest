<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\ListingStatus;
use App\Enums\UnitAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Ledger\LedgerComputationEngine;
use Carbon\Carbon;

/**
 * ContractCaseFileService
 *
 * Assembles everything an admin needs to understand a lease contract,
 * computed strictly from real data. There are no invented statuses, scores,
 * or fabricated signals: the checklist, warnings, financial summary, billing
 * schedule, and timeline are all derived from the contract, its listing/
 * unit/property, the tenant/landlord records, the ledger, and the
 * append-only audit log.
 *
 * All money math is delegated to LedgerComputationEngine — this service
 * never re-sums cents itself.
 *
 * One product threshold is named here so the policy is explicit and
 * tunable: EXPIRING_SOON_DAYS.
 */
class ContractCaseFileService
{
    /** An active lease within this many days of its end date is "expiring soon". */
    private const EXPIRING_SOON_DAYS = 60;

    /** Statuses considered "live" for queue segmenting. */
    private const ENDED_STATUSES = [
        ContractStatus::TERMINATED->value,
        ContractStatus::EXPIRED->value,
    ];

    public function __construct(
        protected LedgerComputationEngine $engine
    ) {}

    /**
     * The contracts queue: truthful segment counts plus a filtered, sorted,
     * searchable list of contract summaries.
     *
     * @param  array{status?:string,search?:string,sort?:string}  $filters
     * @return array{counts:array<string,int>,data:array<int,array<string,mixed>>}
     */
    public function queue(array $filters = []): array
    {
        $status = $filters['status'] ?? 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'ending_soonest';

        $query = Contract::query()->with(['listing.unit.property', 'landlord', 'tenant']);

        // Bigint FK filters (kept for existing integrations that filter a
        // single landlord's or tenant's contracts directly).
        if (! empty($filters['landlord_id'])) {
            $query->where('landlord_id', $filters['landlord_id']);
        }
        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereHas('landlord', function ($lq) use ($like) {
                    $lq->whereRaw('LOWER(first_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                })->orWhereHas('tenant', function ($tq) use ($like) {
                    $tq->whereRaw('LOWER(first_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
                })->orWhereHas('listing.unit.property', function ($pq) use ($like) {
                    $pq->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(city) LIKE ?', [$like]);
                });
            });
        }

        match ($sort) {
            'newest' => $query->orderBy('created_at', 'desc'),
            'rent' => $query->orderBy('rent_amount', 'desc'),
            'property' => $query->join('listings', 'contracts.listing_id', '=', 'listings.id')
                ->join('units', 'listings.unit_id', '=', 'units.id')
                ->join('properties', 'units.property_id', '=', 'properties.id')
                ->orderBy('properties.name')
                ->select('contracts.*'),
            default => $query->orderBy('end_date', 'asc'),
        };

        // Admin queues are small enough to scan in one view; a generous cap
        // keeps this bounded without paginating a list the SPA shows in full.
        $contracts = $query->limit(300)->get();

        $data = $contracts
            ->map(fn (Contract $c) => $this->summary($c))
            ->filter(fn (array $row) => $status === 'all' || $row['segment'] === $status || ($status === 'active' && in_array($row['segment'], ['active', 'expiring', 'overdue'], true)))
            ->values();

        return [
            'counts' => $this->counts(),
            // Top-level count of the filtered result set (distinct from
            // counts.total, which is the unfiltered platform-wide total).
            'total' => $data->count(),
            'data' => $data->all(),
        ];
    }

    /**
     * Truthful segment counts for the queue's header cards.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $contracts = Contract::query()->get(['id', 'status', 'end_date']);

        $active = 0;
        $awaiting = 0;
        $expiringSoon = 0;
        $overdue = 0;

        foreach ($contracts as $contract) {
            if ($contract->status === ContractStatus::ACTIVE) {
                $active++;
                if ($this->isExpiringSoon($contract)) {
                    $expiringSoon++;
                }
                if ($this->engine->computeOverdueByContract($contract->id) > 0) {
                    $overdue++;
                }
            } elseif ($contract->status === ContractStatus::PENDING_TENANT) {
                $awaiting++;
            }
        }

        return [
            'total' => $contracts->count(),
            'active' => $active,
            'awaiting_signatures' => $awaiting,
            'expiring_soon' => $expiringSoon,
            'overdue' => $overdue,
            'ended' => $contracts->whereIn('status', array_map(fn ($s) => ContractStatus::from($s), self::ENDED_STATUSES))->count(),
            'draft' => $contracts->where('status', ContractStatus::DRAFT)->count(),
        ];
    }

    /**
     * Full case-file payload for a single contract (everything except
     * ledger/payments/billing-schedule/timeline, which are their own
     * endpoints so the frontend can fetch them independently).
     *
     * @return array<string,mixed>
     */
    public function detail(Contract $contract): array
    {
        $contract->loadMissing([
            'listing.unit.property',
            'landlord',
            'tenant',
            'admin',
            'notes.admin',
        ]);

        $unit = $contract->listing?->unit;
        $property = $unit?->property;
        $signals = $this->signals($contract);

        return [
            'id' => $contract->id,
            'status' => $contract->status->value,
            'status_label' => $this->statusLabel($contract),
            'health' => $signals['health'],
            'rent_amount' => $contract->rent_amount,
            'currency' => $contract->currency,
            'billing_cycle' => $this->enumValue($contract->billing_cycle),
            'payment_day' => $contract->payment_day,
            'start_date' => $this->iso($contract->start_date),
            'end_date' => $this->iso($contract->end_date),
            'terminated_by' => $this->enumValue($contract->terminated_by),
            'termination_reason' => $contract->termination_reason,
            'created_at' => $this->iso($contract->created_at),

            'financials' => $this->financials($contract),
            'checklist' => $signals['checklist'],
            'warnings' => $signals['warnings'],
            'completeness' => $signals['completeness'],

            'parties' => [
                'tenant' => $this->partyBlock($contract->tenant, $contract),
                'landlord' => $this->partyBlock($contract->landlord, $contract),
            ],

            'property' => $property ? [
                'id' => $property->id,
                'name' => $property->name,
                'property_type' => $this->enumValue($property->property_type),
                'full_address' => $property->full_address,
                'city' => $property->city,
                'state' => $property->state,
                'is_active' => (bool) $property->is_active,
            ] : null,

            'unit' => $unit ? [
                'id' => $unit->id,
                'display_name' => $unit->display_name,
                'unit_number' => $unit->unit_number,
                'bedrooms' => $unit->bedrooms,
                'bathrooms' => $unit->bathrooms,
                'square_feet' => $unit->square_feet,
                'security_deposit' => $unit->security_deposit,
                'availability_status' => $this->enumValue($unit->availability_status),
                'availability_label' => $unit->availability_status?->label(),
            ] : null,

            'listing' => $contract->listing ? [
                'id' => $contract->listing->id,
                'title' => $contract->listing->title,
                'status' => $this->enumValue($contract->listing->status),
            ] : null,

            'terms' => $this->termsBlock($contract, $unit),
            'renewal' => $this->renewalBlock($contract),

            'notes' => $this->notes($contract),
            'admin' => $contract->admin ? ['id' => $contract->admin->id, 'name' => $contract->admin->name] : null,
        ];
    }

    /**
     * The financial overview cards — everything sourced from the ledger
     * engine, never computed here.
     *
     * @return array<string,mixed>
     */
    public function financials(Contract $contract): array
    {
        $balance = $this->engine->computeContractBalance($contract->id);
        $overdue = $this->engine->computeOverdueByContract($contract->id);
        $dueSoon = $this->engine->computeDueSoonByContract($contract->id);
        $totalPaid = $this->engine->computeCollected(['contract_id' => $contract->id]);
        $paymentStatus = $this->engine->deriveContractPaymentStatus($contract->id);

        $nextDue = LedgerEntry::where('contract_id', $contract->id)
            ->where('type', 'rent')
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date')
            ->first();

        $daysRemaining = ($contract->status === ContractStatus::ACTIVE && $contract->end_date)
            ? max(0, Carbon::today()->diffInDays($contract->end_date, false))
            : null;

        return [
            'monthly_rent_cents' => $contract->rent_amount,
            'current_balance_cents' => $balance,
            'overdue_cents' => $overdue,
            'due_soon_cents' => $dueSoon,
            'total_paid_cents' => $totalPaid,
            'security_deposit_cents' => $contract->listing?->unit?->security_deposit !== null
                ? (int) round(((float) $contract->listing->unit->security_deposit) * 100)
                : null,
            'payment_status' => $paymentStatus,
            'lease_remaining_days' => $daysRemaining,
            'next_due_date' => $this->iso($nextDue?->due_date),
        ];
    }

    /**
     * The reconciliation checklist + warnings + contract health. Single
     * source of truth so the queue, detail, and warnings section can never
     * disagree.
     *
     * @return array{checklist:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,completeness:array<string,int>,health:string}
     */
    public function signals(Contract $contract): array
    {
        $checklist = [];
        $add = function (string $key, string $label, string $status, ?string $detail = null) use (&$checklist): void {
            $checklist[] = compact('key', 'label', 'status', 'detail');
        };

        if (! $contract->start_date) {
            $add('dates_valid', 'Lease dates are valid (start before end)', 'fail', 'No start date set.');
        } elseif (! $contract->end_date) {
            $add('dates_valid', 'Lease dates are valid (start before end)', 'warn', 'No end date set — open-ended lease.');
        } else {
            $datesValid = $contract->start_date->lt($contract->end_date);
            $add('dates_valid', 'Lease dates are valid (start before end)', $datesValid ? 'pass' : 'fail', $datesValid ? null : 'End date is not after the start date.');
        }

        $overlap = $this->hasOverlappingActiveContract($contract);
        $add('no_overlap', 'No overlapping active contract for this unit', $overlap ? 'fail' : 'pass', $overlap ? 'Another active contract exists for the same unit.' : null);

        $started = in_array($contract->status, [ContractStatus::ACTIVE, ContractStatus::TERMINATED, ContractStatus::EXPIRED], true);
        if ($started) {
            $rentCount = LedgerEntry::where('contract_id', $contract->id)->where('type', 'rent')->count();
            $expected = $this->expectedElapsedPeriods($contract);
            $rentOk = $rentCount >= $expected;
            $add('rent_generated', 'Rent generated for every elapsed billing period', $rentOk ? 'pass' : 'warn', $rentOk ? null : "Expected at least {$expected} rent period(s), found {$rentCount}.");
        } else {
            $add('rent_generated', 'Rent generated for every elapsed billing period', 'pass', 'Lease has not started — nothing due yet.');
        }

        $duplicatePeriod = LedgerEntry::where('contract_id', $contract->id)
            ->where('type', 'rent')
            ->selectRaw('billing_period_start, COUNT(*) as c')
            ->groupBy('billing_period_start')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $add('no_duplicate_rent', 'No duplicate rent charge for the same period', $duplicatePeriod ? 'fail' : 'pass', $duplicatePeriod ? 'More than one rent charge exists for the same billing period.' : null);

        $orphanPayment = LedgerEntry::where('contract_id', $contract->id)
            ->where('type', 'payment')
            ->whereNull('related_rent_entry_id')
            ->exists();
        $add('payments_linked', 'Every payment is linked to a rent obligation', $orphanPayment ? 'fail' : 'pass', $orphanPayment ? 'A payment entry is not linked to a rent charge.' : null);

        $overdueCents = $this->engine->computeOverdueByContract($contract->id);
        $add('no_overdue', 'No overdue balance outstanding', $overdueCents > 0 ? 'warn' : 'pass', $overdueCents > 0 ? 'Tenant has an overdue balance.' : null);

        $tenantVerified = (bool) $contract->tenant?->identity_verified;
        $add('tenant_verified', 'Tenant identity verified', $tenantVerified ? 'pass' : 'warn', $tenantVerified ? null : 'Tenant has not passed identity verification.');

        $landlordVerified = (bool) $contract->landlord?->identity_verified;
        $add('landlord_verified', 'Landlord identity verified', $landlordVerified ? 'pass' : 'warn', $landlordVerified ? null : 'Landlord has not passed identity verification.');

        $unit = $contract->listing?->unit;
        if ($contract->status === ContractStatus::ACTIVE && $unit) {
            $occupied = $unit->availability_status === UnitAvailabilityStatus::OCCUPIED;
            $add('unit_occupancy', 'Unit occupancy matches contract status', $occupied ? 'pass' : 'warn', $occupied ? null : 'Contract is active but the unit is not marked occupied.');
        } else {
            $add('unit_occupancy', 'Unit occupancy matches contract status', 'pass');
        }

        $listingStillPublic = in_array($contract->status, [ContractStatus::PENDING_TENANT, ContractStatus::ACTIVE], true)
            && $contract->listing?->status === ListingStatus::ACTIVE;
        $add('listing_not_public', 'Listing is not still publicly active', $listingStillPublic ? 'warn' : 'pass', $listingStillPublic ? 'The underlying listing is still publicly active even though this contract exists.' : null);

        $warnings = [];
        foreach ($checklist as $item) {
            if ($item['status'] === 'fail') {
                $warnings[] = ['key' => $item['key'], 'label' => $item['detail'] ?? $item['label'], 'severity' => 'high'];
            } elseif ($item['status'] === 'warn') {
                $warnings[] = ['key' => $item['key'], 'label' => $item['detail'] ?? $item['label'], 'severity' => 'medium'];
            }
        }

        if ($this->isExpiringSoon($contract)) {
            $days = (int) Carbon::today()->diffInDays($contract->end_date, false);
            $warnings[] = ['key' => 'expiring_soon', 'label' => "Lease ends in {$days} day(s).", 'severity' => 'medium'];
        }

        $total = count($checklist);
        $passed = count(array_filter($checklist, fn ($i) => $i['status'] === 'pass'));

        return [
            'checklist' => $checklist,
            'warnings' => $warnings,
            'completeness' => [
                'passed' => $passed,
                'total' => $total,
                'percent' => $total > 0 ? (int) round($passed / $total * 100) : 0,
            ],
            'health' => $this->health($contract, $overdueCents),
        ];
    }

    /**
     * Billing schedule: real generated rent periods for this contract, plus
     * (at most) the single next period the automation will generate next,
     * projected with the identical due-date rule LedgerService uses — never
     * a fabricated projection of the full remaining lease term.
     *
     * @return array<int,array<string,mixed>>
     */
    public function billingSchedule(Contract $contract): array
    {
        $entries = LedgerEntry::where('contract_id', $contract->id)
            ->where('type', 'rent')
            ->orderBy('billing_period_start')
            ->get();

        $periods = $entries->map(fn (LedgerEntry $e) => [
            'billing_period_start' => $this->iso($e->billing_period_start),
            'billing_period_end' => $this->iso($e->billing_period_end),
            'due_date' => $this->iso($e->due_date),
            'amount_cents' => $e->amount_cents,
            'status' => $e->status->value,
            'generated' => true,
        ])->values()->all();

        if ($contract->status === ContractStatus::ACTIVE && $contract->end_date?->isFuture()) {
            $last = $entries->last();
            $nextStart = $last
                ? Carbon::parse($last->billing_period_end)->addDay()
                : Carbon::parse($contract->start_date);

            if ($nextStart->lte($contract->end_date)) {
                $nextEnd = $nextStart->copy()->addMonth()->subDay();
                $dueDate = $nextStart->copy()->day($contract->payment_day);
                if ($dueDate->lt($nextStart)) {
                    $dueDate->addMonth();
                }

                $periods[] = [
                    'billing_period_start' => $this->iso($nextStart),
                    'billing_period_end' => $this->iso($nextEnd),
                    'due_date' => $this->iso($dueDate),
                    'amount_cents' => $contract->rent_amount,
                    'status' => 'upcoming',
                    'generated' => false,
                ];
            }
        }

        return $periods;
    }

    /**
     * Real lifecycle timeline: the contract-created moment plus every
     * audited lifecycle event. Never fabricated — if the audit log has no
     * entry, the event does not appear.
     *
     * @return array<int,array<string,mixed>>
     */
    public function timeline(Contract $contract): array
    {
        $events = [[
            'key' => 'created',
            'label' => 'Contract created',
            'at' => $this->iso($contract->created_at),
            'actor' => $contract->landlord?->full_name,
            'detail' => null,
            'severity' => 'info',
        ]];

        // 'contract_created' is deliberately absent: the synthetic seed event
        // above (sourced from the contract's own created_at) already covers
        // creation — including it here would render "Contract created" twice.
        $labels = [
            'contract_sent' => ['Sent to tenant for signature', 'info'],
            'contract_accepted' => ['Accepted by tenant — lease activated', 'success'],
            'contract_terminated' => ['Terminated', 'danger'],
            'contract_force_terminated' => ['Force-terminated by admin', 'danger'],
        ];

        $logs = AuditLog::query()
            ->where('subject_type', Contract::class)
            ->where('subject_id', $contract->id)
            ->orderBy('created_at')
            ->get();

        foreach ($logs as $log) {
            if (! isset($labels[$log->action])) {
                continue;
            }
            [$label, $severity] = $labels[$log->action];
            $events[] = [
                'key' => $log->action,
                'label' => $label,
                'at' => $this->iso($log->created_at),
                'actor' => $this->actorName($log),
                'detail' => $log->new_values['termination_reason'] ?? $log->description,
                'severity' => $severity,
            ];
        }

        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $events;
    }

    /**
     * Contract-scoped, decorated ledger rows plus the same financial summary
     * used in the Overview section, so the two can never disagree.
     *
     * @return array{entries:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function ledger(Contract $contract): array
    {
        $entries = $this->engine->applyFilters(LedgerEntry::query(), ['contract_id' => $contract->id])
            ->orderBy('created_at')
            ->get();

        return [
            'entries' => $this->engine->decorateEntries($entries)->values()->all(),
            'summary' => $this->financials($contract),
        ];
    }

    /**
     * Payment history: payment-type entries only, decorated (display amount
     * is always positive per the engine's convention).
     *
     * @return array<int,array<string,mixed>>
     */
    public function payments(Contract $contract): array
    {
        $entries = $this->engine->applyFilters(LedgerEntry::query(), ['contract_id' => $contract->id, 'payments_only' => true])
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->engine->decorateEntries($entries)->map(fn (array $row) => [
            ...$row,
            'method' => $row['stripe_payment_intent_id'] ? 'Stripe payment' : 'Recorded manually',
        ])->values()->all();
    }

    /**
     * Real contract-attached documents, or an empty array — no document
     * generation exists in Wyncrest today, so this is truthfully empty
     * until something is uploaded via the media pipeline.
     *
     * @return array<int,array<string,mixed>>
     */
    public function documents(Contract $contract): array
    {
        return MediaAsset::query()
            ->where('attachable_type', Contract::class)
            ->where('attachable_id', $contract->id)
            ->active()
            ->ordered()
            ->get()
            ->map(fn (MediaAsset $asset) => [
                'id' => $asset->id,
                'collection' => $this->enumValue($asset->collection),
                'original_filename' => $asset->original_filename,
                'visibility' => $this->enumValue($asset->visibility),
                'url' => $asset->url,
                'created_at' => $this->iso($asset->created_at),
            ])->values()->all();
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function summary(Contract $contract): array
    {
        $unit = $contract->listing?->unit;
        $property = $unit?->property;
        $paymentStatus = $this->engine->deriveContractPaymentStatus($contract->id);
        $overdue = $this->engine->computeOverdueByContract($contract->id);

        $segment = match (true) {
            $contract->status === ContractStatus::DRAFT => 'draft',
            $contract->status === ContractStatus::PENDING_TENANT => 'awaiting',
            in_array($contract->status->value, self::ENDED_STATUSES, true) => 'ended',
            $overdue > 0 => 'overdue',
            $this->isExpiringSoon($contract) => 'expiring',
            default => 'active',
        };

        $totalDays = $contract->start_date && $contract->end_date
            ? max(1, $contract->start_date->diffInDays($contract->end_date))
            : 1;
        $elapsedDays = $contract->start_date ? max(0, $contract->start_date->diffInDays(Carbon::today(), false)) : 0;
        $termProgressPercent = (int) round(min(100, max(0, ($elapsedDays / $totalDays) * 100)));

        return [
            'id' => $contract->id,
            'reference' => 'WYN-'.strtoupper(substr($contract->id, 0, 8)),
            'status' => $contract->status->value,
            'status_label' => $this->statusLabel($contract),
            'segment' => $segment,
            'property_name' => $property?->name,
            'unit_name' => $unit?->display_name,
            'city' => $property?->city,
            'tenant_name' => $contract->tenant?->full_name ?? ('Tenant #'.$contract->tenant_id),
            'landlord_name' => $contract->landlord?->full_name ?? ('Landlord #'.$contract->landlord_id),
            'rent_amount' => $contract->rent_amount,
            'start_date' => $this->iso($contract->start_date),
            'end_date' => $this->iso($contract->end_date),
            'term_progress_percent' => $termProgressPercent,
            'payment_status' => $paymentStatus,
            'warning_count' => count($this->signals($contract)['warnings']),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function partyBlock(?User $user, Contract $contract): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'verification_status' => $this->enumValue($user->verification_status),
            'identity_verified' => (bool) $user->identity_verified,
            'account_status' => $this->enumValue($user->account_status),
            'contract_balance_cents' => $this->engine->computeContractBalance($contract->id),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function termsBlock(Contract $contract, $unit): array
    {
        $notSpecified = 'Not specified';

        return [
            'start_date' => $this->iso($contract->start_date),
            'end_date' => $this->iso($contract->end_date),
            'duration_months' => $contract->start_date && $contract->end_date
                ? max(1, (int) round($contract->start_date->diffInMonths($contract->end_date)))
                : null,
            'rent_amount_cents' => $contract->rent_amount,
            'billing_cycle' => $this->enumValue($contract->billing_cycle),
            'payment_day' => $contract->payment_day,
            'security_deposit_cents' => $unit?->security_deposit !== null ? (int) round(((float) $unit->security_deposit) * 100) : null,
            'grace_period' => $notSpecified,
            'late_fee_rule' => $notSpecified,
            'utilities_responsibility' => $notSpecified,
            'maintenance_responsibility' => $notSpecified,
            'pets_policy' => $notSpecified,
            'smoking_policy' => $notSpecified,
            'occupancy_limit' => $notSpecified,
            'renewal_type' => $notSpecified,
            'termination_notice_period' => $notSpecified,
            'early_termination_penalty' => $notSpecified,
            'special_clauses' => $notSpecified,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function renewalBlock(Contract $contract): array
    {
        $daysRemaining = ($contract->status === ContractStatus::ACTIVE && $contract->end_date)
            ? (int) Carbon::today()->diffInDays($contract->end_date, false)
            : null;

        return [
            'end_date' => $this->iso($contract->end_date),
            'days_remaining' => $daysRemaining,
            'ending_soon' => $this->isExpiringSoon($contract),
            'notice_period' => 'Not specified',
            'renewal_request_status' => 'None — no renewal workflow exists yet',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function notes(Contract $contract): array
    {
        return $contract->notes->map(fn ($note) => [
            'id' => $note->id,
            'body' => $note->body,
            'admin_id' => $note->admin_id,
            'admin_name' => $note->admin?->name,
            'created_at' => $this->iso($note->created_at),
        ])->values()->all();
    }

    private function hasOverlappingActiveContract(Contract $contract): bool
    {
        $unitId = $contract->listing?->unit_id;
        if (! $unitId || ! $contract->start_date) {
            return false;
        }

        return Contract::query()
            ->where('id', '!=', $contract->id)
            ->where('status', ContractStatus::ACTIVE)
            ->whereHas('listing', fn ($q) => $q->where('unit_id', $unitId))
            // A null end_date means an open-ended lease — treat it as
            // covering every date after its start for overlap purposes.
            ->where(function ($q) use ($contract) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $contract->start_date);
            })
            ->when($contract->end_date, fn ($q) => $q->where('start_date', '<=', $contract->end_date))
            ->exists();
    }

    private function expectedElapsedPeriods(Contract $contract): int
    {
        $start = $contract->start_date;
        if (! $start) {
            return 0;
        }

        $cap = Carbon::today();
        if ($contract->end_date && Carbon::parse($contract->end_date)->lt($cap)) {
            $cap = Carbon::parse($contract->end_date);
        }
        if ($cap->lt($start)) {
            return 0;
        }

        return max(1, (int) $start->diffInMonths($cap) + 1);
    }

    private function isExpiringSoon(Contract $contract): bool
    {
        if ($contract->status !== ContractStatus::ACTIVE || ! $contract->end_date) {
            return false;
        }

        $days = Carbon::today()->diffInDays($contract->end_date, false);

        return $days >= 0 && $days <= self::EXPIRING_SOON_DAYS;
    }

    private function health(Contract $contract, int $overdueCents): string
    {
        return match (true) {
            $contract->status === ContractStatus::DRAFT => 'draft',
            $contract->status === ContractStatus::PENDING_TENANT => 'awaiting_signatures',
            in_array($contract->status->value, self::ENDED_STATUSES, true) => 'closed',
            $overdueCents > 0 => 'overdue',
            $this->isExpiringSoon($contract) => 'ending_soon',
            default => 'good_standing',
        };
    }

    private function statusLabel(Contract $contract): string
    {
        return match ($contract->status) {
            ContractStatus::DRAFT => 'Draft',
            ContractStatus::PENDING_TENANT => 'Awaiting signature',
            ContractStatus::ACTIVE => 'Active',
            ContractStatus::TERMINATED => 'Terminated',
            ContractStatus::EXPIRED => 'Expired',
        };
    }

    private function actorName(AuditLog $log): ?string
    {
        if (! $log->actor_type || ! $log->actor_id) {
            return 'System';
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $actor */
        $actor = $log->actor_type::query()->find($log->actor_id);
        if (! $actor) {
            return null;
        }

        return $actor->full_name ?? $actor->name ?? $actor->email ?? null;
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function iso($value): ?string
    {
        return $value instanceof \Carbon\CarbonInterface ? $value->toIso8601String() : ($value ?: null);
    }
}
