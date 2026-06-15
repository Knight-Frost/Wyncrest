<?php

namespace App\Enums;

/**
 * NotificationType
 *
 * Defines all notification types in the system.
 * Phase 3.5 implements: rent_generated, rent_overdue, payment_succeeded, payment_failed
 */
enum NotificationType: string
{
    case RENT_GENERATED = 'rent_generated';
    case RENT_DUE_SOON = 'rent_due_soon';           // Future (Phase 3.6)
    case RENT_OVERDUE = 'rent_overdue';
    case PAYMENT_SUCCEEDED = 'payment_succeeded';
    case PAYMENT_FAILED = 'payment_failed';
    case LATE_FEE_ADDED = 'late_fee_added';         // Future
    case CONTRACT_SIGNED = 'contract_signed';       // Future
    case CONTRACT_TERMINATED = 'contract_terminated'; // Future
}
