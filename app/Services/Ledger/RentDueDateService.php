<?php

namespace App\Services\Ledger;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\LedgerDueDateRevision;
use App\Models\LedgerEntry;
use App\Models\RentDueScheduleChange;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Support\Billing\BillingClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RentDueDateService — the admin due-date controls (launch closeout).
 *
 * 1. adjust():          move ONE unpaid rent charge's effective due date.
 * 2. changeSchedule():  from a future billing month on, rent for a lease is
 *                       due on day N (29–31 clamp to short months).
 *
 * Invariants (each is pinned by tests/Feature/Ledger/RentDueDate*Test.php):
 *  - A due-date change never creates, deletes or re-amounts a charge and
 *    never moves it to another billing period (identity = contract + period
 *    start). Posted terms are DB-immutable; only due_date (+ status, when an
 *    extension makes an overdue charge current) changes.
 *  - The new date is never in the past, so a change alone can never make a
 *    charge overdue or expose it to a late fee. An existing late fee is a
 *    separate record and is never touched (waive it separately).
 *  - Paid and waived charges are refused, so receipts and statements of
 *    settled history cannot change.
 *  - Every change is a compare-and-swap on the state the admin previewed
 *    (stale → DueDateConflict / 409); repeating the same request is a no-op.
 *  - Every change has a reason, an append-only history row, an audit row
 *    (actor, before, after) and a notice to the tenant and the landlord.
 *
 * Authorization (manage_ledger) is enforced by route middleware; this service
 * is the eligibility gate, not the permission gate.
 */
class RentDueDateService
{
    public function __construct(
        protected BillingPeriodCalculator $calculator,
        protected RentDueDayResolver $dueDays,
        protected AuditService $audit,
        protected NotificationService $notifications,
    ) {}

    /* ───────────────────────── One charge ───────────────────────── */

    /**
     * What would happen if $entry were due on $newDate. Never writes.
     *
     * @return array<string, mixed>
     */
    public function previewAdjustment(LedgerEntry $entry, string $newDate): array
    {
        $entry->refresh();
        $proposed = $this->parseDate($newDate);
        $problems = $this->adjustmentProblems($entry, $proposed);
        $resulting = $this->resultingStatus($entry, $proposed);
        $lateFee = $this->lateFeeFor($entry);

        $impact = [];
        if (! $problems) {
            if ($entry->status === LedgerStatus::OVERDUE && $resulting === LedgerStatus::PENDING) {
                $impact[] = 'This charge is overdue today and will become current (pending) again.';
            }
            $impact[] = 'If still unpaid, it becomes overdue after '.BillingClock::overdueCutoffFor($proposed)->toDateString().'; only then can a late fee be applied.';
            if ($lateFee) {
                $impact[] = 'The existing late fee ('.$lateFee->status->value.') is a separate record and is NOT changed. Waive it separately if it was charged in error.';
            }
            $impact[] = 'Amount, billing period and invoice reference do not change.';
        }

        return [
            'eligible' => $problems === [],
            'problems' => $problems,
            'entry_id' => $entry->id,
            'contract_id' => $entry->contract_id,
            'billing_period_start' => $entry->billing_period_start?->toDateString(),
            'billing_period_end' => $entry->billing_period_end?->toDateString(),
            'amount_cents' => $entry->amount_cents,
            'original_due_date' => $entry->original_due_date?->toDateString(),
            'current_due_date' => $entry->due_date?->toDateString(),
            'proposed_due_date' => $proposed?->toDateString(),
            'current_status' => $entry->status->value,
            'resulting_status' => $resulting?->value,
            'late_fee' => $lateFee ? [
                'id' => $lateFee->id,
                'status' => $lateFee->status->value,
                'amount_cents' => $lateFee->amount_cents,
            ] : null,
            'impact' => $impact,
            'window' => $this->adjustmentWindow($entry),
            // Echo these back on submit: they are the compare-and-swap token.
            'expected_due_date' => $entry->due_date?->toDateString(),
            'expected_status' => $entry->status->value,
        ];
    }

    /**
     * Apply a one-off due-date adjustment.
     *
     * @return array{entry: LedgerEntry, revision: LedgerDueDateRevision|null, changed: bool}
     *
     * @throws DueDateChangeRefused|DueDateConflict
     */
    public function adjust(LedgerEntry $entry, string $newDate, string $reason, Admin $admin, string $expectedDueDate, string $expectedStatus): array
    {
        $proposed = $this->parseDate($newDate);

        $result = DB::transaction(function () use ($entry, $proposed, $reason, $admin, $expectedDueDate, $expectedStatus) {
            \App\Support\Database\WriteLock::row('ledger_entries', $entry->getKey());
            /** @var LedgerEntry $fresh */
            $fresh = LedgerEntry::whereKey($entry->getKey())->firstOrFail();

            // Idempotent: the same request submitted twice has one effect.
            if ($proposed && $fresh->due_date?->isSameDay($proposed)) {
                return ['entry' => $fresh, 'revision' => null, 'changed' => false];
            }

            if ($fresh->due_date?->toDateString() !== $expectedDueDate || $fresh->status->value !== $expectedStatus) {
                throw new DueDateConflict(
                    'This charge changed after you previewed it. Review the current due date and status, then try again.',
                    ['due_date' => $fresh->due_date?->toDateString(), 'status' => $fresh->status->value]
                );
            }

            if ($problems = $this->adjustmentProblems($fresh, $proposed)) {
                throw new DueDateChangeRefused(implode(' ', $problems));
            }

            $previousStatus = $fresh->status;
            $previousDue = $fresh->due_date->toDateString();
            $newStatus = $this->resultingStatus($fresh, $proposed);

            if (! $fresh->adjustDueDate($proposed, $newStatus, $previousStatus, $previousDue)) {
                throw new DueDateConflict('This charge was changed by another action at the same moment. Refresh and try again.');
            }

            $revision = LedgerDueDateRevision::create([
                'ledger_entry_id' => $fresh->id,
                'contract_id' => $fresh->contract_id,
                'previous_due_date' => $previousDue,
                'new_due_date' => $proposed->toDateString(),
                'previous_status' => $previousStatus->value,
                'new_status' => $newStatus->value,
                'reason' => $reason,
                'admin_id' => $admin->id,
            ]);

            $this->audit->log(
                actor: $admin,
                action: 'rent_due_date_adjusted',
                subject: $fresh,
                description: "Due date of rent charge {$fresh->id} moved from {$previousDue} to {$proposed->toDateString()}. Reason: {$reason}",
                severity: 'warning',
                metadata: [
                    'revision_id' => $revision->id,
                    'contract_id' => $fresh->contract_id,
                    'billing_period_start' => $fresh->billing_period_start->toDateString(),
                    'original_due_date' => $fresh->original_due_date?->toDateString(),
                    'previous_due_date' => $previousDue,
                    'new_due_date' => $proposed->toDateString(),
                    'previous_status' => $previousStatus->value,
                    'new_status' => $newStatus->value,
                    'amount_cents' => $fresh->amount_cents,
                    'reason' => $reason,
                ],
            );

            return ['entry' => $fresh->refresh(), 'revision' => $revision, 'changed' => true];
        });

        if ($result['changed']) {
            $this->notifyAdjustment($result['entry'], $result['revision']);
        }

        return $result;
    }

    /** @return list<string> */
    private function adjustmentProblems(LedgerEntry $entry, ?Carbon $proposed): array
    {
        if ($entry->type !== LedgerType::RENT) {
            return ['Only rent charges can have their due date moved. Late fees are due when applied; waive one instead if it was charged in error.'];
        }
        if (! $entry->status->isDue()) {
            return ["This charge is {$entry->status->value}, so its due date is final and its receipt and statement history are protected."];
        }
        if ($proposed === null) {
            return ['Choose a valid date (YYYY-MM-DD).'];
        }

        $problems = [];
        $window = $this->adjustmentWindow($entry);
        if ($proposed->lt(Carbon::parse($window['earliest']))) {
            $problems[] = "The new due date cannot be before {$window['earliest']} (today on the billing calendar, and never before the lease starts). A date change must not make a charge overdue on its own.";
        }
        if ($proposed->gt(Carbon::parse($window['latest']))) {
            $problems[] = "The new due date cannot be after {$window['latest']} (".config('billing.max_extension_days_after_period').' days after this billing period ends).';
        }

        return $problems;
    }

    /** @return array{earliest: string, latest: string} */
    private function adjustmentWindow(LedgerEntry $entry): array
    {
        $today = BillingClock::today();
        $leaseStart = $entry->contract?->start_date ? Carbon::parse($entry->contract->start_date->toDateString()) : $today;
        $earliest = $today->max($leaseStart);
        $latest = Carbon::parse($entry->billing_period_end->toDateString())
            ->addDays((int) config('billing.max_extension_days_after_period', 60));

        return ['earliest' => $earliest->toDateString(), 'latest' => $latest->toDateString()];
    }

    private function resultingStatus(LedgerEntry $entry, ?Carbon $proposed): ?LedgerStatus
    {
        if (! $entry->status->isDue() || $proposed === null) {
            return null;
        }

        // The window forbids past dates, so the new date is never past its
        // overdue cutoff: the charge is current (PENDING) afterwards.
        return BillingClock::isPastDue($proposed) ? LedgerStatus::OVERDUE : LedgerStatus::PENDING;
    }

    private function lateFeeFor(LedgerEntry $entry): ?LedgerEntry
    {
        return LedgerEntry::where('related_rent_entry_id', $entry->id)
            ->where('type', LedgerType::LATE_FEE)
            ->first();
    }

    /* ───────────────────────── Future schedule ───────────────────────── */

    /**
     * The lease's due-day schedule: base, timeline, next periods.
     *
     * @return array<string, mixed>
     */
    public function schedule(Contract $contract): array
    {
        $timeline = $this->dueDays->timeline($contract);
        $firstOpen = $this->firstUngeneratedPeriod($contract);

        return [
            'contract_id' => $contract->id,
            'billing_anchor' => $this->calculator->anchor($contract)->value,
            'billing_anchor_label' => $this->calculator->anchor($contract)->label(),
            'signed_payment_day' => (int) $contract->payment_day,
            'default_due_day' => (int) config('billing.default_due_day', 1),
            'current_due_day' => $this->currentDueDay($contract),
            'billing_timezone' => BillingClock::timezone(),
            'grace_days' => (int) config('billing.grace_days', 0),
            'changeable' => $this->scheduleProblems($contract) === [],
            'problems' => $this->scheduleProblems($contract),
            'earliest_effective_month' => $this->earliestEffectiveMonth($contract)?->format('Y-m'),
            'version' => $this->scheduleVersion($contract),
            'timeline' => array_map(fn (array $step) => [
                'id' => $step['change']->id,
                'effective_from' => $step['effective_from']->toDateString(),
                'due_day' => $step['due_day'],
                'is_default' => (bool) $step['change']->is_default,
                'reason' => $step['change']->reason,
                'admin' => $step['change']->admin?->name,
                'created_at' => $step['change']->created_at?->toIso8601String(),
            ], $timeline),
            'history' => RentDueScheduleChange::with('admin')->where('contract_id', $contract->id)->orderBy('id')->get()
                ->map(fn (RentDueScheduleChange $c) => [
                    'id' => $c->id,
                    'effective_from' => $c->effective_from->toDateString(),
                    'due_day' => $c->due_day,
                    'is_default' => $c->is_default,
                    'reason' => $c->reason,
                    'admin' => $c->admin?->name,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])->values()->all(),
            'upcoming' => $firstOpen
                ? array_map(fn (array $p) => $this->periodRow($p), $this->calculator->project($contract, $firstOpen['start'], (int) config('billing.preview_months', 6)))
                : [],
        ];
    }

    /**
     * Preview "from $effectiveMonth (Y-m) rent is due on day $dueDay". Never writes.
     *
     * @return array<string, mixed>
     */
    public function previewScheduleChange(Contract $contract, int $dueDay, string $effectiveMonth): array
    {
        $contract->refresh();
        $from = $this->parseMonth($effectiveMonth);
        $problems = $this->scheduleChangeProblems($contract, $dueDay, $from);

        $rows = [];
        $firstAffected = null;
        if (! $problems) {
            $firstAffected = $this->firstPeriodOnOrAfter($contract, $from);
            if ($firstAffected) {
                $hypothetical = collect([new RentDueScheduleChange(['due_day' => $dueDay, 'effective_from' => $from->toDateString()])]);
                $before = $this->calculator->project($contract, $firstAffected['start'], (int) config('billing.preview_months', 6));
                $after = $this->calculator->project($contract, $firstAffected['start'], (int) config('billing.preview_months', 6), $hypothetical);
                foreach ($after as $i => $period) {
                    $rows[] = array_merge($this->periodRow($period), [
                        'current_due_date' => isset($before[$i]) ? $before[$i]['due_date']->toDateString() : null,
                        'clamped' => $dueDay > $period['start']->daysInMonth,
                    ]);
                }
            }
        }

        return [
            'eligible' => $problems === [],
            'problems' => $problems,
            'contract_id' => $contract->id,
            'due_day' => $dueDay,
            'effective_from' => $from?->toDateString(),
            'first_affected_period_start' => $firstAffected ? $firstAffected['start']->toDateString() : null,
            'current_due_day' => $this->currentDueDay($contract),
            'periods' => $rows,
            'notes' => $problems ? [] : array_values(array_filter([
                $dueDay > 28 ? "Months shorter than {$dueDay} days use their last day, then return to day {$dueDay}." : null,
                'Charges already issued keep their due dates; move one with "Adjust due date" on the charge itself.',
                'Amounts and billing periods do not change.',
            ])),
            'expected_version' => $this->scheduleVersion($contract),
        ];
    }

    /**
     * @return array{change: RentDueScheduleChange|null, changed: bool}
     *
     * @throws DueDateChangeRefused|DueDateConflict
     */
    public function changeSchedule(Contract $contract, int $dueDay, string $effectiveMonth, string $reason, Admin $admin, int $expectedVersion, bool $isDefault = false): array
    {
        $from = $this->parseMonth($effectiveMonth);

        $result = DB::transaction(function () use ($contract, $dueDay, $from, $reason, $admin, $expectedVersion, $isDefault) {
            // Row lock on Postgres/MySQL serializes this with rent generation
            // and other admins; SQLite serializes writers on its own.
            \App\Support\Database\WriteLock::row('contracts', $contract->getKey());
            $fresh = Contract::whereKey($contract->getKey())->firstOrFail();

            // Idempotent: if the latest change already says exactly this, no-op.
            $latest = RentDueScheduleChange::where('contract_id', $fresh->id)->orderByDesc('id')->first();
            if ($from && $latest && $latest->due_day === $dueDay && $latest->effective_from->isSameDay($from)) {
                return ['change' => $latest, 'changed' => false];
            }

            if ($this->scheduleVersion($fresh) !== $expectedVersion) {
                throw new DueDateConflict(
                    'This lease\'s rent schedule changed after you previewed it. Review the current schedule and try again.',
                    ['version' => $this->scheduleVersion($fresh)]
                );
            }

            if ($problems = $this->scheduleChangeProblems($fresh, $dueDay, $from)) {
                throw new DueDateChangeRefused(implode(' ', $problems));
            }

            $previousDay = $this->dueDays->dueDayFor($fresh, $from);

            $change = RentDueScheduleChange::create([
                'contract_id' => $fresh->id,
                'due_day' => $dueDay,
                'effective_from' => $from->toDateString(),
                'is_default' => $isDefault,
                'reason' => $reason,
                'admin_id' => $admin->id,
            ]);

            $this->audit->log(
                actor: $admin,
                action: $isDefault ? 'rent_due_schedule_reset' : 'rent_due_schedule_changed',
                subject: $fresh,
                description: ($isDefault ? 'Rent due date returned to the default (day 1)' : "Rent due day changed to {$dueDay}")
                    ." from billing month {$from->format('Y-m')} (was day {$previousDay}). Reason: {$reason}",
                severity: 'warning',
                metadata: [
                    'change_id' => $change->id,
                    'contract_id' => $fresh->id,
                    'effective_from' => $from->toDateString(),
                    'previous_due_day' => $previousDay,
                    'new_due_day' => $dueDay,
                    'is_default' => $isDefault,
                    'reason' => $reason,
                ],
            );

            return ['change' => $change, 'changed' => true, 'previous_day' => $previousDay, 'contract' => $fresh];
        });

        if ($result['changed']) {
            $this->notifySchedule($result['contract'], $result['change'], $result['previous_day']);
        }

        return ['change' => $result['change'], 'changed' => $result['changed']];
    }

    /** @return list<string> */
    private function scheduleProblems(Contract $contract): array
    {
        if (! in_array($contract->status, [ContractStatus::DRAFT, ContractStatus::PENDING_TENANT, ContractStatus::ACTIVE], true)) {
            return ["This lease is {$contract->status->value}; its rent schedule can no longer change."];
        }
        if ($this->earliestEffectiveMonth($contract) === null) {
            return ['This lease has no future billing month left to change.'];
        }

        return [];
    }

    /** @return list<string> */
    private function scheduleChangeProblems(Contract $contract, int $dueDay, ?Carbon $from): array
    {
        if ($problems = $this->scheduleProblems($contract)) {
            return $problems;
        }
        if ($dueDay < 1 || $dueDay > 31) {
            return ['The due day must be between 1 and 31.'];
        }
        if ($from === null) {
            return ['Choose an effective billing month (YYYY-MM).'];
        }

        $earliest = $this->earliestEffectiveMonth($contract);
        if ($from->lt($earliest)) {
            return ["The earliest month this lease's schedule can change is {$earliest->format('F Y')}: earlier months are already billed or under way. Move an issued charge with \"Adjust due date\" instead."];
        }
        if ($this->firstPeriodOnOrAfter($contract, $from) === null) {
            return ['That month is after the lease ends.'];
        }

        return [];
    }

    /**
     * The first month a schedule change may take effect: the later of next
     * month on the billing calendar and the month after the last issued
     * rent charge's period start (issued charges use the per-charge control).
     */
    private function earliestEffectiveMonth(Contract $contract): ?Carbon
    {
        $candidate = BillingClock::today()->startOfMonth()->addMonthNoOverflow();

        $lastIssued = LedgerEntry::where('contract_id', $contract->id)
            ->where('type', LedgerType::RENT)
            ->max('billing_period_start');
        if ($lastIssued) {
            $afterIssued = Carbon::parse($lastIssued)->startOfMonth()->addMonthNoOverflow();
            $candidate = $candidate->max($afterIssued);
        }

        $leaseStartMonth = Carbon::parse($contract->start_date->toDateString())->startOfMonth();
        $candidate = $candidate->max($leaseStartMonth);

        if ($contract->end_date && $candidate->gt(Carbon::parse($contract->end_date->toDateString()))) {
            return null;
        }

        return $candidate->copy();
    }

    /**
     * The first period of the lease whose start is on/after $from.
     *
     * @return array<string, mixed>|null
     */
    private function firstPeriodOnOrAfter(Contract $contract, Carbon $from): ?array
    {
        $period = $this->calculator->firstPeriod($contract);
        for ($i = 0; $period && $i < 1200; $i++) {
            if ($period['start']->gte($from)) {
                return $period;
            }
            $period = $this->calculator->periodAfter($contract, $period['end']);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function firstUngeneratedPeriod(Contract $contract): ?array
    {
        $last = LedgerEntry::where('contract_id', $contract->id)->where('type', LedgerType::RENT)
            ->orderByDesc('billing_period_start')->first();

        if (! $last) {
            return in_array($contract->status, [ContractStatus::DRAFT, ContractStatus::PENDING_TENANT, ContractStatus::ACTIVE], true)
                ? $this->calculator->firstPeriod($contract)
                : null;
        }

        return $contract->status === ContractStatus::ACTIVE
            ? $this->calculator->periodAfter($contract, $last->billing_period_end)
            : null;
    }

    /** Due day that applies to the next ungenerated billing period. */
    public function currentDueDay(Contract $contract): int
    {
        $next = $this->firstUngeneratedPeriod($contract);

        return $this->dueDays->dueDayFor($contract, $next['start'] ?? BillingClock::today());
    }

    /** Optimistic-concurrency version: the id of the latest change (0 = none). */
    public function scheduleVersion(Contract $contract): int
    {
        return (int) RentDueScheduleChange::where('contract_id', $contract->id)->max('id');
    }

    /** @return array<string, mixed> */
    private function periodRow(array $period): array
    {
        return [
            'billing_period_start' => $period['start']->toDateString(),
            'billing_period_end' => $period['end']->toDateString(),
            'due_date' => $period['due_date']->toDateString(),
            'amount_cents' => $period['amount_cents'],
            'prorated' => $period['prorated'],
        ];
    }

    /* ───────────────────────── Notices ───────────────────────── */

    private function notifyAdjustment(LedgerEntry $entry, LedgerDueDateRevision $revision): void
    {
        $period = $entry->billing_period_start->format('F Y');
        $from = Carbon::parse($revision->previous_due_date)->format('j M Y');
        $to = Carbon::parse($revision->new_due_date)->format('j M Y');

        foreach (['tenant' => $entry->tenant, 'landlord' => $entry->landlord] as $role => $user) {
            $this->notifyOnce($user, "rent-due-date-adjusted:{$revision->id}:{$role}", 'Rent due date changed',
                "The {$period} rent charge is now due on {$to} (was {$from}). The amount is unchanged.",
                [
                    'ledger_entry_id' => $entry->id,
                    'contract_id' => $entry->contract_id,
                    'previous_due_date' => $revision->previous_due_date->toDateString(),
                    'new_due_date' => $revision->new_due_date->toDateString(),
                ]);
        }
    }

    private function notifySchedule(Contract $contract, RentDueScheduleChange $change, int $previousDay): void
    {
        $month = $change->effective_from->format('F Y');
        $message = $change->is_default
            ? "From {$month}, rent is due on the 1st of each month again (was day {$previousDay})."
            : "From {$month}, rent is due on day {$change->due_day} of each month (was day {$previousDay})"
                .($change->due_day > 28 ? ', or the last day of shorter months.' : '.');

        foreach (['tenant' => $contract->tenant, 'landlord' => $contract->landlord] as $role => $user) {
            $this->notifyOnce($user, "rent-due-schedule:{$change->id}:{$role}", 'Rent due date schedule changed', $message, [
                'contract_id' => $contract->id,
                'effective_from' => $change->effective_from->toDateString(),
                'due_day' => $change->due_day,
                'previous_due_day' => $previousDay,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function notifyOnce(?User $user, string $eventId, string $title, string $message, array $data): void
    {
        if (! $user || $this->notifications->exists($user, $eventId)) {
            return;
        }

        $this->notifications->create(
            user: $user,
            type: NotificationType::RENT_DUE_DATE_CHANGED,
            title: $title,
            message: $message,
            data: array_merge(['event_id' => $eventId], $data),
        );
    }

    /* ───────────────────────── Parsing ───────────────────────── */

    private function parseDate(string $value): ?Carbon
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed && $parsed->format('Y-m-d') === $value ? Carbon::parse($value)->startOfDay() : null;
    }

    private function parseMonth(string $value): ?Carbon
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m', $value);

        return $parsed && $parsed->format('Y-m') === $value ? Carbon::parse($value.'-01')->startOfDay() : null;
    }
}
