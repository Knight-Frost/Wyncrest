<?php

namespace App\Enums;

/**
 * NotificationType
 *
 * Defines all notification types in the system.
 */
enum NotificationType: string
{
    case RENT_GENERATED = 'rent_generated';
    case RENT_DUE_SOON = 'rent_due_soon';
    case RENT_OVERDUE = 'rent_overdue';
    case PAYMENT_SUCCEEDED = 'payment_succeeded';
    case PAYMENT_FAILED = 'payment_failed';
    case LATE_FEE_ADDED = 'late_fee_added';
    case CONTRACT_SIGNED = 'contract_signed';
    case CONTRACT_SENT = 'contract_sent';
    case CONTRACT_TERMINATED = 'contract_terminated';
    case CONTRACT_RENEWED = 'contract_renewed';
    case LISTING_APPROVED = 'listing_approved';
    case LISTING_REJECTED = 'listing_rejected';
    case LISTING_CHANGES_REQUESTED = 'listing_changes_requested';
    case ACCOUNT_SUSPENDED = 'account_suspended';
    case ACCOUNT_REACTIVATED = 'account_reactivated';
    case VERIFICATION_SUBMITTED = 'verification_submitted';
    case VERIFICATION_APPROVED = 'verification_approved';
    case VERIFICATION_REJECTED = 'verification_rejected';
    case VERIFICATION_NEEDS_INFO = 'verification_needs_info';
    case ACCOUNT_BLOCKED = 'account_blocked';
    case ACCOUNT_ARCHIVED = 'account_archived';
    case PASSWORD_CHANGED = 'password_changed';

    // Application lifecycle
    case APPLICATION_SUBMITTED = 'application_submitted';
    case APPLICATION_APPROVED = 'application_approved';
    case APPLICATION_REJECTED = 'application_rejected';
    case APPLICATION_NEEDS_ACTION = 'application_needs_action';
    case APPLICATION_UPDATED = 'application_updated';

    // Reviews
    case REVIEW_SUBMITTED = 'review_submitted';
    case REVIEW_APPROVED = 'review_approved';
    case REVIEW_RESPONSE = 'review_response';

    // Maintenance
    case MAINTENANCE_REQUEST_SUBMITTED = 'maintenance_request_submitted';
    case MAINTENANCE_LOGGED_BY_LANDLORD = 'maintenance_logged_by_landlord';
    case MAINTENANCE_STATUS_UPDATED = 'maintenance_status_updated';

    // Messaging
    case MESSAGE_RECEIVED = 'message_received';
}
