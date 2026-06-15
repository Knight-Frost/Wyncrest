<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ContractStatus;
use App\Enums\TerminatedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contract Model
 *
 * Represents a rental contract between landlord and tenant.
 * Immutable after creation - changes require versioning.
 */
class Contract extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'listing_id',
        'landlord_id',
        'tenant_id',
        'rent_amount',
        'currency',
        'billing_cycle',
        'payment_day',
        'start_date',
        'end_date',
        'status',
        'terminated_by',
        'termination_reason',
        'admin_id',
    ];

    protected $casts = [
        'rent_amount' => 'integer',
        'payment_day' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => ContractStatus::class,
        'billing_cycle' => BillingCycle::class,
        'terminated_by' => TerminatedBy::class,
    ];

    /**
     * Get the listing associated with the contract
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Get the landlord (user)
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    /**
     * Get the tenant (user)
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * Get the admin who terminated (if applicable)
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Scope for active contracts
     */
    public function scopeActive($query)
    {
        return $query->where('status', ContractStatus::ACTIVE);
    }

    /**
     * Scope for contracts by landlord
     */
    public function scopeByLandlord($query, int $landlordId)
    {
        return $query->where('landlord_id', $landlordId);
    }

    /**
     * Scope for contracts by tenant
     */
    public function scopeByTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Check if contract can be terminated
     */
    public function canBeTerminated(): bool
    {
        return $this->status->canBeTerminated();
    }

    /**
     * Check if contract can be accepted by tenant
     */
    public function canBeAccepted(): bool
    {
        return $this->status->canBeAccepted();
    }

    /**
     * Get rent amount in dollars (from cents)
     */
    public function getRentInDollarsAttribute(): float
    {
        return $this->rent_amount / 100;
    }
}
