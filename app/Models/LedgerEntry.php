<?php

namespace App\Models;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LedgerEntry Model
 *
 * IMMUTABLE FINANCIAL RECORD
 * - General updates are NOT allowed after creation
 * - Deletes are NOT allowed
 * - Status transitions are ONLY allowed via transitionStatus()
 * - Corrections require compensating entries
 *
 * This is the financial source of truth for Nexus.
 * SECURITY: Strict immutability enforced at model level.
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
        'status',
        'related_rent_entry_id',
        'stripe_payment_intent_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'due_date' => 'date',
        'type' => LedgerType::class,
        'status' => LedgerStatus::class,
        'created_at' => 'datetime',
    ];

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

        return $this->due_date->isPast();
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

        $updated = static::where('id', $this->id)->update($updateData);

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
