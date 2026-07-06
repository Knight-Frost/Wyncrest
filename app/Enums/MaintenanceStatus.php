<?php

namespace App\Enums;

/**
 * MaintenanceStatus Enum
 *
 * Tracks the lifecycle of a maintenance request.
 */
enum MaintenanceStatus: string
{
    case OPEN = 'open';
    case ACKNOWLEDGED = 'acknowledged';
    case ASSIGNED = 'assigned';
    case IN_PROGRESS = 'in_progress';
    case WAITING = 'waiting';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    /**
     * Returns true when the request is still active (not yet in a final state).
     */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::OPEN,
            self::ACKNOWLEDGED,
            self::ASSIGNED,
            self::IN_PROGRESS,
            self::WAITING,
        ], true);
    }

    /**
     * Returns true when the request has reached a terminal state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::RESOLVED, self::CLOSED, self::CANCELLED], true);
    }

    /**
     * Returns true when a tenant is permitted to cancel the request.
     * Only OPEN requests may be cancelled by the tenant.
     */
    public function canBeCancelledByTenant(): bool
    {
        return $this === self::OPEN;
    }
}
