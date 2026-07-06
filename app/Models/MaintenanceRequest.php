<?php

namespace App\Models;

use App\Enums\MaintenanceAccess;
use App\Enums\MaintenanceArea;
use App\Enums\MaintenanceAssigneeType;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceContactMethod;
use App\Enums\MaintenanceOnset;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceReporter;
use App\Enums\MaintenanceSafetyFlag;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceVisitWindow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MaintenanceRequest Model
 *
 * Represents a maintenance issue filed by a tenant against an active lease.
 * Property, unit, and landlord IDs are denormalised from the contract at
 * creation time so that list queries do not require joins to contracts.
 */
class MaintenanceRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = ['total_cost_cents', 'has_severe_safety_flag'];

    protected $fillable = [
        'tenant_id',
        'contract_id',
        'property_id',
        'unit_id',
        'landlord_id',
        'reported_by',
        'title',
        'description',
        'area',
        'specific_location',
        'onset',
        'safety_flags',
        'access_permission',
        'preferred_visit_window',
        'preferred_contact_method',
        'access_instructions',
        'category',
        'priority',
        'status',
        'resolution_notes',
        'assignee_name',
        'assignee_phone',
        'assignee_type',
        'waiting_reason',
        'labor_cost_cents',
        'parts_cost_cents',
        'invoice_reference',
        'cost_notes',
        'cost_paid',
        'submitted_at',
        'acknowledged_at',
        'assigned_at',
        'appointment_at',
        'expected_completion_date',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'status' => MaintenanceStatus::class,
        'priority' => MaintenancePriority::class,
        'category' => MaintenanceCategory::class,
        'reported_by' => MaintenanceReporter::class,
        'assignee_type' => MaintenanceAssigneeType::class,
        'area' => MaintenanceArea::class,
        'onset' => MaintenanceOnset::class,
        'access_permission' => MaintenanceAccess::class,
        'preferred_visit_window' => MaintenanceVisitWindow::class,
        'preferred_contact_method' => MaintenanceContactMethod::class,
        'safety_flags' => 'array',
        'cost_paid' => 'boolean',
        'labor_cost_cents' => 'integer',
        'parts_cost_cents' => 'integer',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'assigned_at' => 'datetime',
        'appointment_at' => 'datetime',
        'expected_completion_date' => 'date',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Total repair cost in cents (labour + parts). Null when no cost recorded.
     */
    public function getTotalCostCentsAttribute(): ?int
    {
        if ($this->labor_cost_cents === null && $this->parts_cost_cents === null) {
            return null;
        }

        return (int) $this->labor_cost_cents + (int) $this->parts_cost_cents;
    }

    /**
     * True when the tenant raised at least one severe safety/damage flag
     * (water leak, no power, security, injury risk). Lets the landlord queue
     * surface a hazard indicator without re-deriving it client-side.
     */
    public function getHasSevereSafetyFlagAttribute(): bool
    {
        foreach ($this->safety_flags ?? [] as $flag) {
            if (MaintenanceSafetyFlag::tryFrom($flag)?->isSevere()) {
                return true;
            }
        }

        return false;
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Append-only, tenant-visible activity trail.
     */
    public function events(): HasMany
    {
        return $this->hasMany(MaintenanceEvent::class)->orderBy('id');
    }

    /**
     * Photo evidence and receipts (MediaCollection::MaintenanceEvidence).
     */
    public function media(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'attachable')
            ->active()
            ->ordered();
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Scope: only requests that are not yet in a final state.
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            MaintenanceStatus::OPEN->value,
            MaintenanceStatus::ACKNOWLEDGED->value,
            MaintenanceStatus::ASSIGNED->value,
            MaintenanceStatus::IN_PROGRESS->value,
            MaintenanceStatus::WAITING->value,
        ]);
    }
}
