<?php

namespace App\Support\Audit;

/**
 * AuditClassifier
 *
 * Single source of truth for ALL derived/presentation fields on audit log entries.
 * All methods are pure static — no DB access, no side effects, fully unit-testable.
 */
class AuditClassifier
{
    /**
     * Map every known action to its display area.
     * Unknown actions fall through to 'System'.
     */
    public const AREAS = [
        // Access
        'admin_login' => 'Access',
        'user_login' => 'Access',
        'login_rate_limited' => 'Access',
        'admin_access_denied' => 'Access',

        // Access control (admin team & permissions)
        'admin_invited' => 'Access',
        'admin_invite_resent' => 'Access',
        'admin_invite_revoked' => 'Access',
        'admin_invite_accepted' => 'Access',
        'admin_capabilities_updated' => 'Access',
        'admin_promoted_super' => 'Access',
        'admin_demoted_super' => 'Access',
        'admin_deactivated' => 'Access',
        'admin_reactivated' => 'Access',

        // Users
        'user_created' => 'Users',
        'email_verified' => 'Users',
        'identity_verified' => 'Users',
        'account_suspended' => 'Users',
        'account_reactivated' => 'Users',
        'account_blocked' => 'Users',
        'account_archived' => 'Users',
        'tenant_profile_updated' => 'Users',
        'verification_submitted' => 'Users',
        'verification_approved' => 'Users',
        'verification_rejected' => 'Users',
        'verification_needs_info' => 'Users',

        // Listings
        'listing_created' => 'Listings',
        'listing_updated' => 'Listings',
        'listing_submitted' => 'Listings',
        'listing_published' => 'Listings',
        'listing_rejected' => 'Listings',
        'listing_changes_requested' => 'Listings',
        'listing_deleted' => 'Listings',

        // Properties
        'property_created' => 'Properties',
        'property_updated' => 'Properties',
        'property_deleted' => 'Properties',
        'unit_created' => 'Properties',
        'unit_updated' => 'Properties',
        'unit_deleted' => 'Properties',

        // Contracts
        'contract_created' => 'Contracts',
        'contract_sent' => 'Contracts',
        'contract_accepted' => 'Contracts',
        'contract_terminated' => 'Contracts',
        'contract_force_terminated' => 'Contracts',

        // Ledger
        'payment_intent_created' => 'Ledger',
        'payment_intent_failed' => 'Ledger',
        'payment_recorded' => 'Ledger',
        'payment_failed' => 'Ledger',
        'rent_entry_created' => 'Ledger',
        'late_fee_applied' => 'Ledger',
        'entry_marked_overdue' => 'Ledger',
        'ledger_entry_marked_overdue' => 'Ledger',
        'entry_paid' => 'Ledger',
        'entry_waived' => 'Ledger',
        'rent_entry_automated' => 'Ledger',
        'ledger_exported' => 'Ledger',

        // Applications
        'application_submitted' => 'Applications',
        'application_withdrawn' => 'Applications',
        'application_decided' => 'Applications',

        // Maintenance
        'maintenance_request_created' => 'Maintenance',
        'maintenance_request_cancelled' => 'Maintenance',
        'maintenance_status_updated' => 'Maintenance',

        // Documents
        'document_uploaded' => 'Documents',
        'document_downloaded' => 'Documents',
        'document_deleted' => 'Documents',

        // Messages
        'conversation_started' => 'Messages',
        'message_sent' => 'Messages',

        // Settings
        'feature_enabled' => 'Settings',
        'feature_disabled' => 'Settings',
    ];

    /**
     * Admin-facing event headline, distinct from the raw action key.
     * "rent_entry_created" reads as an engineering event; a reader who has
     * never seen the schema should still understand "Monthly Rent Generated".
     * Falls back to a title-cased action when no mapping exists — never
     * invents a headline that implies more than the action key says.
     */
    private const EVENT_TITLES = [
        // Access
        'admin_login' => 'Admin Signed In',
        'user_login' => 'User Signed In',
        'login_rate_limited' => 'Sign-In Rate Limited',
        'admin_invited' => 'Admin Invited',
        'admin_invite_resent' => 'Admin Invitation Resent',
        'admin_invite_revoked' => 'Admin Invitation Revoked',
        'admin_invite_accepted' => 'Admin Invitation Accepted',
        'admin_capabilities_updated' => 'Admin Permissions Changed',
        'admin_promoted_super' => 'Admin Promoted to Super Admin',
        'admin_demoted_super' => 'Super Admin Demoted',
        'admin_deactivated' => 'Admin Access Deactivated',
        'admin_reactivated' => 'Admin Access Reactivated',
        'admin_access_denied' => 'Admin Access Denied',

        // Users
        'user_created' => 'Account Created',
        'email_verified' => 'Email Verified',
        'identity_verified' => 'Identity Verified',
        'account_suspended' => 'Account Suspended',
        'account_reactivated' => 'Account Reactivated',
        'account_blocked' => 'Account Blocked',
        'account_archived' => 'Account Archived',
        'tenant_profile_updated' => 'Profile Updated',
        'verification_submitted' => 'Verification Submitted',
        'verification_approved' => 'Identity Verification Approved',
        'verification_rejected' => 'Identity Verification Rejected',
        'verification_needs_info' => 'More Information Requested',

        // Listings
        'listing_created' => 'Listing Drafted',
        'listing_updated' => 'Listing Updated',
        'listing_submitted' => 'Listing Submitted for Review',
        'listing_published' => 'Listing Approved',
        'listing_rejected' => 'Listing Rejected',
        'listing_changes_requested' => 'Listing Sent Back for Changes',
        'listing_deleted' => 'Listing Deleted',

        // Properties
        'property_created' => 'Property Added',
        'property_updated' => 'Property Updated',
        'property_deleted' => 'Property Removed',
        'unit_created' => 'Unit Added',
        'unit_updated' => 'Unit Updated',
        'unit_deleted' => 'Unit Removed',

        // Contracts
        'contract_created' => 'Contract Drafted',
        'contract_sent' => 'Contract Sent for Signature',
        'contract_accepted' => 'Contract Activated',
        'contract_terminated' => 'Contract Terminated',
        'contract_force_terminated' => 'Contract Force-Terminated',

        // Ledger
        'payment_intent_created' => 'Payment Started',
        'payment_intent_failed' => 'Payment Setup Failed',
        'payment_recorded' => 'Payment Received',
        'payment_failed' => 'Payment Failed',
        'rent_entry_created' => 'Monthly Rent Generated',
        'late_fee_applied' => 'Late Fee Applied',
        'entry_marked_overdue' => 'Rent Marked Overdue',
        'ledger_entry_marked_overdue' => 'Rent Marked Overdue',
        'entry_paid' => 'Ledger Entry Paid',
        'entry_waived' => 'Charge Waived',
        'rent_entry_automated' => 'Monthly Rent Generated',
        'ledger_exported' => 'Ledger Exported',
        'admin_analytics_exported' => 'Admin Analytics Exported',

        // Applications
        'application_submitted' => 'Application Submitted',
        'application_withdrawn' => 'Application Withdrawn',
        'application_decided' => 'Application Decision Recorded',

        // Maintenance
        'maintenance_request_created' => 'Maintenance Request Submitted',
        'maintenance_request_cancelled' => 'Maintenance Request Cancelled',
        'maintenance_status_updated' => 'Maintenance Status Updated',

        // Documents
        'document_uploaded' => 'Document Uploaded',
        'document_downloaded' => 'Document Downloaded',
        'document_deleted' => 'Document Deleted',

        // Messages
        'conversation_started' => 'Conversation Started',
        'message_sent' => 'Message Sent',

        // Settings
        'feature_enabled' => 'Feature Enabled',
        'feature_disabled' => 'Feature Disabled',
    ];

    /**
     * Return the display area for a given action.
     * Falls back to 'System' for unknown actions.
     */
    public static function area(string $action): string
    {
        return self::AREAS[$action] ?? 'System';
    }

    /**
     * Human-readable label for an action slug.
     * e.g. 'account_suspended' => 'Account suspended'
     */
    public static function actionLabel(string $action): string
    {
        return ucfirst(str_replace('_', ' ', $action));
    }

    /**
     * Admin-facing event headline. See EVENT_TITLES. Unknown actions fall
     * back to a title-cased rendering of the action key — readable, but
     * makes no claim about what actually happened beyond the key itself.
     */
    public static function title(string $action): string
    {
        return self::EVENT_TITLES[$action] ?? ucwords(str_replace('_', ' ', $action));
    }

    /**
     * Deterministic classification label, derived purely from the action's
     * area and stored severity — never an invented per-event risk score.
     * A small, explicit allow-list escalates specific high-consequence
     * actions to "Important" even when logged at 'info' severity (e.g. a
     * contract ending is worth an admin's attention regardless of severity).
     */
    public static function classification(string $action, string $severity): string
    {
        $escalated = [
            'contract_terminated', 'contract_force_terminated',
            'admin_promoted_super', 'admin_demoted_super',
            'account_blocked', 'account_archived',
        ];

        if (in_array($action, $escalated, true)) {
            return 'Important';
        }

        return match ($severity) {
            'critical', 'warning' => 'Needs review',
            default => 'Routine',
        };
    }

    /**
     * Deterministic sensitivity tag from the action's area. Null when the
     * area carries no particular sensitivity beyond its severity.
     */
    public static function sensitivity(string $action): ?string
    {
        return match (self::area($action)) {
            'Ledger' => 'Financial record',
            'Access' => 'Security sensitive',
            'Users' => str_starts_with($action, 'admin_') ? 'Security sensitive' : 'Account sensitive',
            default => null,
        };
    }

    /**
     * Deterministic status projection from severity.
     *
     * // why: severity is a stored DB column; 'status' (key+label) is a
     * // presentation concept derived from it — it is NOT a stored workflow
     * // state and must never be persisted or compared to a stored value.
     *
     * @return array{key: string, label: string}
     */
    public static function status(string $severity): array
    {
        return match ($severity) {
            'critical' => ['key' => 'needs_review',      'label' => 'Needs review'],
            'warning' => ['key' => 'review_suggested',  'label' => 'Review suggested'],
            default => ['key' => 'routine',            'label' => 'Routine'],
        };
    }

    /**
     * Parse a User-Agent string into a short "OS · Browser" label.
     * Returns null when $userAgent is null/empty or nothing can be matched.
     *
     * No external packages — pure regex. Version numbers are deliberately
     * omitted; an OS · Browser pair is honest and sufficient.
     */
    public static function device(?string $userAgent): ?string
    {
        if (empty($userAgent)) {
            return null;
        }

        // --- OS detection (order matters: more specific before more generic) ---
        $os = null;
        if (preg_match('/\bWindows\b/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/\biPhone|iPad\b/i', $userAgent) || preg_match('/\bCPU (iPhone|OS)\b/i', $userAgent)) {
            $os = 'iOS';
        } elseif (preg_match('/\bAndroid\b/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/\bMac OS X\b/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/\bLinux\b/i', $userAgent)) {
            $os = 'Linux';
        }

        // --- Browser detection (order matters: Edge before Chrome, Chrome before Safari) ---
        $browser = null;
        if (preg_match('/\bEdg(e|\/)\b/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/\bChrome\b/i', $userAgent) && ! preg_match('/\bChromium\b/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/\bFirefox\b/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/\bSafari\b/i', $userAgent)) {
            $browser = 'Safari';
        }

        if ($os === null && $browser === null) {
            return null;
        }

        $parts = array_filter([$os, $browser]);

        return implode(' · ', $parts);
    }

    /**
     * Derive the actor's role label from morph type and (for User) user_type.
     *
     * @param  string|null  $actorType  morph class name (e.g. App\Models\Admin)
     * @param  string|null  $userType  'tenant' | 'landlord' | null
     */
    public static function actorRole(?string $actorType, ?string $userType): string
    {
        if ($actorType === null) {
            return 'system';
        }

        if ($actorType === \App\Models\Admin::class) {
            return 'admin';
        }

        if ($actorType === \App\Models\User::class) {
            return $userType ?? 'user';
        }

        return 'user';
    }

    /**
     * A short, honest plain-language sentence explaining why this event matters.
     * Provides specific guidance where useful; falls back by severity.
     */
    public static function whyItMatters(string $action, string $severity): string
    {
        $map = [
            // Access
            'admin_login' => 'An admin signed in to the platform. Review if you do not recognise the actor.',
            'user_login' => 'A user signed in. Review if you do not recognise the actor or location.',
            'login_rate_limited' => 'Sign-in attempts were rate-limited for this account. This may indicate a brute-force attempt.',

            // Access control (admin team & permissions)
            'admin_invited' => 'A new admin was invited to the team. Confirm the inviter and the granted access are correct.',
            'admin_invite_resent' => 'An admin invitation email was resent.',
            'admin_invite_revoked' => 'A pending admin invitation was revoked before it was accepted.',
            'admin_invite_accepted' => 'An invited admin set their password and activated their account.',
            'admin_capabilities_updated' => 'A regular admin\'s capabilities were changed. Review the before/after values.',
            'admin_promoted_super' => 'An admin was promoted to Super Admin — full platform authority. Confirm this was authorised.',
            'admin_demoted_super' => 'A Super Admin was demoted to a regular admin.',
            'admin_deactivated' => 'An admin\'s console access was deactivated.',
            'admin_reactivated' => 'An admin\'s console access was reactivated.',
            'admin_access_denied' => 'A scoped admin attempted to reach a route requiring a capability they do not hold.',

            // Users
            'user_created' => 'A new user registered on the platform.',
            'email_verified' => 'A user verified their email address.',
            'identity_verified' => 'This user\'s identity is now verified, unlocking verification-gated features such as submitting listings or applications.',
            'account_suspended' => 'A user account was suspended. Confirm this was intentional.',
            'account_reactivated' => 'A suspended account was reactivated.',
            'account_blocked' => 'A user account was blocked. This is more severe than suspension — confirm this was authorised.',
            'account_archived' => 'A user account was archived and removed from active use.',
            'tenant_profile_updated' => 'A tenant updated their profile information.',
            'verification_submitted' => 'A user submitted documents for identity verification.',
            'verification_approved' => 'An admin approved a user\'s identity verification, unlocking verification-gated features.',
            'verification_rejected' => 'An admin rejected a user\'s identity verification request.',
            'verification_needs_info' => 'An admin requested more information before deciding a verification request.',

            // Listings
            'listing_created' => 'A new listing was created and saved as a draft.',
            'listing_updated' => 'An existing listing was edited.',
            'listing_submitted' => 'A listing was submitted for admin review and moderation.',
            'listing_published' => 'This listing is now publicly visible and searchable by tenants.',
            'listing_rejected' => 'The landlord must address the stated reason and resubmit before this listing can go live.',
            'listing_changes_requested' => 'The landlord must make the requested changes and resubmit before this listing can be reviewed again.',
            'listing_deleted' => 'A listing was deleted from the platform.',

            // Properties
            'property_created' => 'A new property was added to the platform.',
            'property_updated' => 'Property details were updated.',
            'property_deleted' => 'A property was removed from the platform.',
            'unit_created' => 'A new unit was added to a property.',
            'unit_updated' => 'Unit details were updated.',
            'unit_deleted' => 'A unit was removed from a property.',

            // Contracts
            'contract_created' => 'A new rental contract was drafted. It has no effect on the ledger until it is sent and accepted.',
            'contract_sent' => 'A contract was sent to the tenant for acceptance. Rent generation will not begin until the tenant accepts it.',
            'contract_accepted' => 'The lease is now active. Wyncrest\'s scheduled rent automation will begin generating monthly charges against this contract.',
            'contract_terminated' => 'The lease relationship has ended. No further rent charges will be generated for this contract, and any outstanding balance remains payable.',
            'contract_force_terminated' => 'An admin ended this lease outside the normal tenant/landlord flow. Confirm this was authorised and check the contract\'s outstanding balance.',

            // Ledger
            'payment_intent_created' => 'A Stripe payment was started for this charge. It is not yet reflected as paid — wait for payment_recorded or payment_failed.',
            'payment_intent_failed' => 'Wyncrest could not start a payment for this charge. The tenant may need to retry from their dashboard.',
            'payment_recorded' => 'This payment reduces the tenant\'s outstanding balance on the ledger. It is part of the immutable financial record and cannot be edited directly.',
            'payment_failed' => 'The payment did not go through — the tenant\'s charge remains outstanding. Review whether the tenant needs assistance retrying.',
            'rent_entry_created' => 'This charge increases the tenant\'s balance for the billing period. It is part of the official ledger and cannot be edited directly — a mistake must be corrected with a compensating entry, never a direct edit.',
            'late_fee_applied' => 'This fee adds to the tenant\'s outstanding balance for a rent charge that was already overdue.',
            'entry_marked_overdue' => 'This charge passed its due date unpaid. It now counts toward the tenant\'s and landlord\'s overdue totals until it is paid or waived.',
            'ledger_entry_marked_overdue' => 'This charge passed its due date unpaid. It now counts toward the tenant\'s and landlord\'s overdue totals until it is paid or waived.',
            'entry_paid' => 'This charge is now settled and no longer counts toward the tenant\'s outstanding balance.',
            'entry_waived' => 'This charge was written off. It no longer counts toward the tenant\'s balance, and the ledger keeps a permanent record of the waiver and its reason.',
            'rent_entry_automated' => 'This charge increases the tenant\'s balance for the billing period. It is part of the official ledger and cannot be edited directly.',
            'ledger_exported' => 'An admin exported ledger data (optionally filtered) as a CSV file. This can move sensitive financial and personal data out of the app.',

            // Applications
            'application_submitted' => 'A tenant submitted a rental application.',
            'application_withdrawn' => 'A tenant withdrew their rental application.',
            'application_decided' => 'A landlord made a decision on a rental application.',

            // Maintenance
            'maintenance_request_created' => 'A tenant submitted a new maintenance request.',
            'maintenance_request_cancelled' => 'A maintenance request was cancelled.',
            'maintenance_status_updated' => 'The status of a maintenance request was updated.',

            // Documents
            'document_uploaded' => 'A document was uploaded to the platform.',
            'document_downloaded' => 'A document was downloaded.',
            'document_deleted' => 'A document was deleted.',

            // Messages
            'conversation_started' => 'A new conversation was started between two parties.',
            'message_sent' => 'A message was sent in a conversation.',

            // Settings
            'feature_enabled' => 'A platform feature was enabled for a landlord.',
            'feature_disabled' => 'A platform feature was disabled for a landlord.',
        ];

        if (isset($map[$action])) {
            return $map[$action];
        }

        // Fallback by severity
        return match ($severity) {
            'critical' => 'This is a high-impact event and may need your immediate attention.',
            'warning' => 'This event may warrant a closer look.',
            default => 'A system event was recorded.',
        };
    }

    /**
     * Return suggested next steps as navigable actions.
     * Routes point to REAL existing SPA routes only.
     *
     * $subjectId accepts both integer PKs (User, Admin, Listing) and UUID PKs
     * (Contract, LedgerEntry, VerificationRequest) — do not force-cast to int.
     * $context may carry 'contract_id' when the subject is a LedgerEntry so a
     * ledger event can still deep-link to its contract.
     *
     * @return array<int, array{label: string, to: string|null}>
     */
    public static function recommendedSteps(
        string $action,
        ?string $subjectType,
        int|string|null $subjectId,
        array $context = []
    ): array {
        // Access / sign-in failures
        if (in_array($action, ['login_rate_limited', 'payment_intent_failed', 'payment_failed'])) {
            return [['label' => 'Review users', 'to' => '/app/users']];
        }

        if ($action === 'admin_login') {
            return [['label' => 'No action needed', 'to' => null]];
        }

        if ($action === 'user_login') {
            return [];
        }

        // Access-control (admin team & permissions) events
        if (in_array($action, [
            'admin_invited', 'admin_invite_resent', 'admin_invite_revoked', 'admin_invite_accepted',
            'admin_capabilities_updated', 'admin_promoted_super', 'admin_demoted_super',
            'admin_deactivated', 'admin_reactivated',
        ])) {
            return [['label' => 'Manage users & permissions', 'to' => '/app/manage-access']];
        }

        // User account actions
        if (in_array($action, [
            'account_suspended', 'account_reactivated', 'account_blocked', 'account_archived',
            'identity_verified', 'user_created', 'email_verified', 'tenant_profile_updated',
        ])) {
            return [['label' => 'View users', 'to' => '/app/users']];
        }

        // Verification decisions — link to the specific case when we have its id.
        if (in_array($action, ['verification_submitted', 'verification_approved', 'verification_rejected', 'verification_needs_info'])) {
            if ($subjectType === \App\Models\VerificationRequest::class && $subjectId !== null) {
                return [['label' => 'View verification request', 'to' => "/app/verifications/{$subjectId}"]];
            }

            return [['label' => 'View verifications', 'to' => '/app/verifications']];
        }

        // Listing moderation — link to the specific listing when the subject IS
        // the listing (audit rows whose subject is a LedgerEntry etc. never
        // reach this branch, so subjectId here is always a listing id).
        if (in_array($action, ['listing_submitted', 'listing_published', 'listing_rejected', 'listing_changes_requested', 'listing_created', 'listing_updated', 'listing_deleted'])) {
            if ($subjectType === \App\Models\Listing::class && $subjectId !== null) {
                return [['label' => 'View listing review', 'to' => "/app/listing-review/{$subjectId}"]];
            }

            return [['label' => 'Open listing review', 'to' => '/app/listing-review']];
        }

        // Ledger / payment events — no single-entry ledger route exists today,
        // but a resolved contract (via $context['contract_id']) does have one.
        if (in_array($action, [
            'payment_intent_created', 'payment_intent_failed', 'payment_recorded', 'payment_failed',
            'rent_entry_created', 'late_fee_applied', 'entry_marked_overdue',
            'ledger_entry_marked_overdue', 'entry_paid', 'entry_waived', 'rent_entry_automated',
        ])) {
            $steps = [['label' => 'View ledger', 'to' => '/app/ledger']];
            if (! empty($context['contract_id'])) {
                array_unshift($steps, ['label' => 'View contract', 'to' => "/app/contracts/{$context['contract_id']}"]);
            }

            return $steps;
        }

        // Contract events — link to the specific contract when the subject IS
        // the contract (true for every action in this branch).
        if (in_array($action, ['contract_created', 'contract_sent', 'contract_accepted', 'contract_terminated', 'contract_force_terminated'])) {
            if ($subjectType === \App\Models\Contract::class && $subjectId !== null) {
                return [['label' => 'View contract', 'to' => "/app/contracts/{$subjectId}"]];
            }

            return [['label' => 'View contracts', 'to' => '/app/contracts']];
        }

        return [];
    }

    /**
     * Build a reverse map: area => [action, action, ...]
     * Used by AuditLogService to translate area filter to action whereIn.
     *
     * @return array<string, list<string>>
     */
    public static function areaToActions(): array
    {
        $map = [];
        foreach (self::AREAS as $action => $area) {
            $map[$area][] = $action;
        }

        return $map;
    }
}
