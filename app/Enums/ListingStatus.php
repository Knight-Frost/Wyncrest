<?php

namespace App\Enums;

/**
 * ListingStatus Enum
 *
 * Defines the lifecycle states of a listing.
 * Enforces moderation workflow.
 */
enum ListingStatus: string
{
    case DRAFT = 'draft';
    case PENDING_REVIEW = 'pending_review';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case REJECTED = 'rejected';
    case ARCHIVED = 'archived';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING_REVIEW => 'Pending Review',
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
            self::REJECTED => 'Rejected',
            self::ARCHIVED => 'Archived',
        };
    }

    /**
     * Check if listing is publicly visible
     */
    public function isPublic(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if listing can be edited
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::REJECTED, self::INACTIVE]);
    }

    /**
     * Check if listing requires admin review
     */
    public function requiresReview(): bool
    {
        return $this === self::PENDING_REVIEW;
    }
}
