<?php

namespace App\Enums;

/**
 * ApplicationStatus Enum
 *
 * Represents the lifecycle of a tenant rental application.
 * SECURITY: Status transitions are enforced in the ApplicationPolicy and
 * centralised in ApplicationService — controllers never set arbitrary states.
 *
 * Lifecycle:
 *   DRAFT ──submit──▶ SUBMITTED ──▶ IN_REVIEW ──▶ LANDLORD_REVIEW ──decide──▶ APPROVED / REJECTED
 *     │                   ▲              │
 *     └──delete           └── resolve ── NEEDS_ACTION ◀── landlord requests info
 *   (any active) ──withdraw──▶ WITHDRAWN
 */
enum ApplicationStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case IN_REVIEW = 'in_review';
    case LANDLORD_REVIEW = 'landlord_review';
    case NEEDS_ACTION = 'needs_action';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case WITHDRAWN = 'withdrawn';

    /**
     * Statuses that count as "in flight" with the landlord — submitted and
     * awaiting/undergoing review (including when the ball is back in the
     * tenant's court via NEEDS_ACTION). A DRAFT is NOT active: it has not yet
     * been sent to anyone.
     *
     * @return array<int, self>
     */
    public static function activeCases(): array
    {
        return [
            self::SUBMITTED,
            self::IN_REVIEW,
            self::LANDLORD_REVIEW,
            self::NEEDS_ACTION,
        ];
    }

    /**
     * Check if the application is in an active (submitted, non-final) state.
     */
    public function isActive(): bool
    {
        return in_array($this, self::activeCases(), true);
    }

    /**
     * Check if the application is a draft the tenant is still preparing.
     */
    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Check if the application is in a final state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::REJECTED,
            self::WITHDRAWN,
        ], true);
    }

    /**
     * Check if the application can be withdrawn by the tenant.
     * Only submitted (active) applications can be withdrawn; a draft is deleted,
     * not withdrawn.
     */
    public function canBeWithdrawn(): bool
    {
        return $this->isActive();
    }

    /**
     * Human-readable label for UI/audit output.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Submitted',
            self::IN_REVIEW => 'Under review',
            self::LANDLORD_REVIEW => 'Landlord review',
            self::NEEDS_ACTION => 'Needs action',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Not selected',
            self::WITHDRAWN => 'Withdrawn',
        };
    }
}
