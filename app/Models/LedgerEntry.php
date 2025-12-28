<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\LedgerType;
use App\Enums\LedgerStatus;

/**
 * LedgerEntry Model
 * 
 * IMMUTABLE FINANCIAL RECORD
 * - No updates allowed after creation
 * - No deletes allowed
 * - Corrections require compensating entries
 * 
 * This is the financial source of truth for Nexus.
 */
class LedgerEntry extends Model
{
    use HasFactory, HasUuids;

    /**
     * Disable updated_at timestamp (entries are immutable)
     */
    const UPDATED_AT = null;

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
     * Get the contract this entry belongs to
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Get the tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * Get the landlord
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    /**
     * Get the related rent entry (for late fees and payments)
     */
    public function relatedRentEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'related_rent_entry_id');
    }

    /**
     * Scope for tenant's entries
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope for landlord's entries
     */
    public function scopeByLandlord($query, $landlordId)
    {
        return $query->where('landlord_id', $landlordId);
    }

    /**
     * Scope for pending entries
     */
    public function scopePending($query)
    {
        return $query->where('status', LedgerStatus::PENDING);
    }

    /**
     * Scope for overdue entries
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', LedgerStatus::OVERDUE);
    }

    /**
     * Scope for rent entries only
     */
    public function scopeRent($query)
    {
        return $query->where('type', LedgerType::RENT);
    }

    /**
     * Scope for late fee entries only
     */
    public function scopeLateFees($query)
    {
        return $query->where('type', LedgerType::LATE_FEE);
    }

    /**
     * Scope for payment entries only
     */
    public function scopePayments($query)
    {
        return $query->where('type', LedgerType::PAYMENT);
    }

    /**
     * Scope for unpaid obligations
     */
    public function scopeUnpaid($query)
    {
        return $query->whereIn('type', [LedgerType::RENT->value, LedgerType::LATE_FEE->value])
            ->whereIn('status', [LedgerStatus::PENDING->value, LedgerStatus::OVERDUE->value]);
    }

    /**
     * Check if entry is overdue
     */
    public function isOverdue(): bool
    {
        if ($this->status->isSettled()) {
            return false;
        }
        
        return $this->due_date->isPast();
    }

    /**
     * Check if this entry can be paid
     */
    public function canBePaid(): bool
    {
        return $this->type->isObligation() && $this->status->isDue();
    }

    /**
     * Get amount in dollars (from cents)
     */
    public function getAmountInDollarsAttribute(): float
    {
        return $this->amount_cents / 100;
    }

    /**
     * Prevent updates (immutability)
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception('Ledger entries are immutable and cannot be updated. Create a compensating entry instead.');
    }

    /**
     * Prevent deletes (immutability)
     */
    public function delete()
    {
        throw new \Exception('Ledger entries cannot be deleted. Create a compensating entry instead.');
    }

    /**
     * Prevent force deletes (immutability)
     */
    public function forceDelete()
    {
        throw new \Exception('Ledger entries cannot be deleted. Create a compensating entry instead.');
    }
}
