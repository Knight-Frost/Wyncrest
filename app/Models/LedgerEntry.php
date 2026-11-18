<?php

namespace App\Models;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Support\Billing\BillingClock;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LedgerEntry Model
 *
 * IMMUTABLE FINANCIAL RECORD
 * - Posted terms (amount, type, parties, billing period, original due date)
 *   never change after creation.
 * - Deletes are NOT allowed.
 * - Status changes ONLY via transitionStatus() (compare-and-swap).
 * - The EFFECTIVE due date changes ONLY via adjustDueDate() (admin, audited,
 *   with a ledger_due_date_revisions row); original_due_date keeps the posted
 *   term.
 * - Corrections are waivers (status -> waived, reason in the audit log).
 *   Wyncrest has no compensating/reversing-entry mechanism and no refunds.
 *
 * Enforcement: this model refuses update()/save()/delete(); on SQLite and
 * PostgreSQL database triggers additionally refuse DELETE and any UPDATE of a
 * posted term (migration 2026_11_18_000003). MySQL has the model guard only.
 */
class LedgerEntry extends Model
{
    use HasFactory, HasUuids;

    /**
     * Disable updated_at timestamp (entries are immutable).
     */
    const UPDATED_AT = null;

    /**
     * Valid status transitions.
     * Key: current status, Value: array of allowed next statuses.
     */
    private const STATUS_TRANSITIONS = [
        'pending' => ['paid', 'overdue', 'waived'],
        'overdue' => ['paid', 'waived'],
        'paid' => [], // Terminal state
        'waived' => [], // Terminal state
    ];

    protected $fillable = [
        'contract_id',
        'tenant_id',
        'landlord_id',
        'type',
        'amount_cents',
        'currency',
        'billing_period_start',
        'billing_period_end',
        'due_date',
        'original_due_date',
        'status',
        'related_rent_entry_id',
        'stripe_payment_intent_id',
        'payment_method',
        'payment_reference',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'due_date' => 'date',
        'original_due_date' => 'date',
        'type' => LedgerType::class,
        'status' => LedgerStatus::class,
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The posted due date is recorded once, at insert, and never changes.
        static::creating(function (LedgerEntry $entry) {
            if ($entry->original_due_date === null && $entry->due_date !== null) {
                $entry->original_due_date = $entry->due_date;
            }
        });
    }

    /**
     * Get the contract this entry belongs to.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Get the tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * Get the landlord.
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    /**
     * Get the related rent entry (for late fees and payments).
     */
    public function relatedRentEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'related_rent_entry_id');
    }

    /**
     * Scope for tenant's entries.
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope for landlord's entries.
     */
    public function scopeByLandlord($query, $landlordId)
    {
        return $query->where('landlord_id', $landlordId);
    }

    /**
     * Scope for pending entries.
     */
    public function scopePending($query)
    {
        return $query->where('status', LedgerStatus::PENDING);
    }

    /**
     * Scope for overdue entries.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', LedgerStatus::OVERDUE);
    }

    /**
     * Scope for rent entries only.
     */
    public function scopeRent($query)
    {
        return $query->where('type', LedgerType::RENT);
    }

    /**
     * Scope for late fee entries only.
     */
    public function scopeLateFees($query)
    {
        return $query->where('type', LedgerType::LATE_FEE);
    }

    /**
     * Scope for payment entries only.
     */
    public function scopePayments($query)
    {
        return $query->where('type', LedgerType::PAYMENT);
    }

    /**
     * Scope for unpaid obligations.
     */
    public function scopeUnpaid($query)
    {
        return $query->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
            ->whereIn('status', [LedgerStatus::PENDING->value, LedgerStatus::OVERDUE->value]);
    }

    /**
     * Check if entry is overdue.
     */
    public function isOverdue(): bool
    {
        if ($this->status->isSettled()) {
            return false;
        }

        if ($this->status === LedgerStatus::OVERDUE) {
            return true;
        }

        // why: due_date->isPast() was true from 00:00:01 ON the due date, so a
        // charge could be fined on the day it was due while the overdue sweep
        // (correctly) left it pending. Both now use the billing calendar.
        return BillingClock::isPastDue($this->due_date);
    }

    /** Whether the effective due date differs from the posted one. */
    public function hasAdjustedDueDate(): bool
    {
        return $this->original_due_date !== null
            && $this->due_date !== null
            && ! $this->original_due_date->isSameDay($this->due_date);
    }

    /**
     * Mark a PENDING entry OVERDUE only if, at write time, it is still pending
     * AND still past the overdue cutoff. Guarding on the due date as well as
     * the status means an admin extension that lands between the sweep's
     * SELECT and this UPDATE wins, instead of the sweep marking a charge that
     * is no longer late.
     */
    public function markOverdueIfPastDue(string $dueBefore): bool
    {
        $updated = static::where('id', $this->id)
            ->where('status', LedgerStatus::PENDING->value)
            ->whereDate('due_date', '<', $dueBefore)
            ->update(['status' => LedgerStatus::OVERDUE->value]);

        if ($updated) {
            $this->status = LedgerStatus::OVERDUE;
        }

        return (bool) $updated;
    }

    /**
     * Move the effective due date (and, when an extension makes an overdue
     * charge current again, its status back to PENDING) in ONE
     * compare-and-swap on the status and due date the caller previewed.
     * Returns false if another writer (payment, waiver, sweep, a second
     * admin) changed either first. Only RentDueDateService may call this: it
     * owns eligibility, the revision row and the audit log.
     *
     * @internal
     */
    public function adjustDueDate(\DateTimeInterface $newDueDate, LedgerStatus $newStatus, LedgerStatus $expectedStatus, string $expectedDueDate): bool
    {
        if (! in_array($expectedStatus, [LedgerStatus::PENDING, LedgerStatus::OVERDUE], true)
            || ! in_array($newStatus, [LedgerStatus::PENDING, LedgerStatus::OVERDUE], true)) {
            throw new \InvalidArgumentException('Only an unpaid (pending or overdue) charge can have its due date adjusted.');
        }

        $updated = static::where('id', $this->id)
            ->where('status', $expectedStatus->value)
            ->whereDate('due_date', $expectedDueDate)
            ->update([
                'due_date' => $newDueDate->format('Y-m-d'),
                'status' => $newStatus->value,
            ]);

        return (bool) $updated;
    }

    /**
     * Check if this entry can be paid.
     */
    public function canBePaid(): bool
    {
        return $this->type->isObligation() && $this->status->isDue();
    }

    /**
     * Get amount in dollars (from cents).
     */
    public function getAmountInDollarsAttribute(): float
    {
        return $this->amount_cents / 100;
    }

    /**
     * Transition entry to a new status.
     *
     * This is the ONLY way to change status after creation.
     * Validates that the transition is allowed.
     *
     * @param  string|null  $paymentIntentId  Optional Stripe payment intent ID for paid status
     *
     * @throws \InvalidArgumentException If transition is not allowed
     */
    public function transitionStatus(LedgerStatus $newStatus, ?string $paymentIntentId = null): bool
    {
        $currentStatus = $this->status->value;
        $allowedTransitions = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus->value, $allowedTransitions, true)) {
            throw new \InvalidArgumentException(
                "Invalid status transition: {$currentStatus} -> {$newStatus->value}. ".
                'Allowed transitions: '.implode(', ', $allowedTransitions)
            );
        }

        // Use query builder to bypass model immutability
        $updateData = ['status' => $newStatus->value];

        if ($paymentIntentId !== null && $newStatus === LedgerStatus::PAID) {
            $updateData['stripe_payment_intent_id'] = $paymentIntentId;
        }

        // Compare-and-swap on the current status: if a concurrent request
        // already transitioned this entry, the update matches 0 rows and we
        // report failure instead of silently double-applying (e.g. two
        // simultaneous payments both flipping PENDING -> PAID).
        $updated = static::where('id', $this->id)
            ->where('status', $currentStatus)
            ->update($updateData);

        if ($updated) {
            $this->status = $newStatus;
            if ($paymentIntentId !== null) {
                $this->stripe_payment_intent_id = $paymentIntentId;
            }
        }

        return (bool) $updated;
    }

    /**
     * Prevent updates (immutability).
     *
     * @throws \Exception Always - use transitionStatus() for status changes
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception(
            'Ledger entries are immutable and cannot be updated directly. '.
            'Use transitionStatus() for status changes or create a compensating entry.'
        );
    }

    /**
     * Prevent save()/saveQuietly()/touch()/push() on an existing entry.
     *
     * why (forensic audit WYN-006): overriding update() alone left save() open,
     * because Eloquent's save() on an existing model calls performUpdate(), not
     * update(). This is the single funnel for every Eloquent instance update.
     * transitionStatus() is unaffected: it is a query-builder compare-and-swap.
     *
     * @throws \LogicException Always
     */
    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        throw new \LogicException(
            'Ledger entries are immutable and cannot be saved after creation. '.
            'Use transitionStatus() for status changes or create a compensating entry.'
        );
    }

    /**
     * Prevent increment()/decrement() on an entry (they bypass performUpdate).
     *
     * @throws \LogicException Always
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new \LogicException('Ledger entries are immutable: amounts cannot be incremented or decremented.');
    }

    /**
     * Prevent deletes (immutability).
     *
     * @throws \Exception Always
     */
    public function delete()
    {
        throw new \Exception('Ledger entries cannot be deleted. Create a compensating entry instead.');
    }

    /**
     * Prevent force deletes (immutability).
     *
     * @throws \Exception Always
     */
    public function forceDelete()
    {
        throw new \Exception('Ledger entries cannot be deleted. Create a compensating entry instead.');
    }
}
