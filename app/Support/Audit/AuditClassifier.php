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
        'tenant_profile_updated' => 'Users',

        // Listings
        'listing_created' => 'Listings',
        'listing_updated' => 'Listings',
        'listing_submitted' => 'Listings',
        'listing_published' => 'Listings',
        'listing_rejected' => 'Listings',
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

            // Users
            'user_created' => 'A new user registered on the platform.',
            'email_verified' => 'A user verified their email address.',
            'identity_verified' => 'An admin verified a user\'s identity, unlocking landlord features.',
            'account_suspended' => 'A user account was suspended. Confirm this was intentional.',
            'account_reactivated' => 'A suspended account was reactivated.',
            'tenant_profile_updated' => 'A tenant updated their profile information.',

            // Listings
            'listing_created' => 'A new listing was created and saved as a draft.',
            'listing_updated' => 'An existing listing was edited.',
            'listing_submitted' => 'A listing was submitted for admin review and moderation.',
            'listing_published' => 'A listing was approved and is now publicly visible.',
            'listing_rejected' => 'A listing was rejected during moderation.',
            'listing_deleted' => 'A listing was deleted from the platform.',

            // Properties
            'property_created' => 'A new property was added to the platform.',
            'property_updated' => 'Property details were updated.',
            'property_deleted' => 'A property was removed from the platform.',
            'unit_created' => 'A new unit was added to a property.',
            'unit_updated' => 'Unit details were updated.',
            'unit_deleted' => 'A unit was removed from a property.',

            // Contracts
            'contract_created' => 'A new rental contract was drafted.',
            'contract_sent' => 'A contract was sent to the tenant for acceptance.',
            'contract_accepted' => 'A tenant accepted a rental contract.',
            'contract_terminated' => 'A contract was terminated by one of the parties.',
            'contract_force_terminated' => 'An admin force-terminated a contract. Confirm this was authorised.',

            // Ledger
            'payment_intent_created' => 'A Stripe payment intent was created for a rent entry.',
            'payment_intent_failed' => 'A payment intent failed. The tenant may need to retry.',
            'payment_recorded' => 'A rent payment was successfully recorded.',
            'payment_failed' => 'A payment attempt failed. Review if the tenant needs assistance.',
            'rent_entry_created' => 'A scheduled rent ledger entry was created.',
            'late_fee_applied' => 'A late fee was applied to an overdue ledger entry.',
            'entry_marked_overdue' => 'A ledger entry was marked overdue.',
            'ledger_entry_marked_overdue' => 'A ledger entry was marked overdue.',
            'entry_paid' => 'A ledger entry was marked as paid.',
            'entry_waived' => 'A ledger entry was waived by an admin.',
            'rent_entry_automated' => 'A rent entry was automatically generated by the system.',

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
     * @return array<int, array{label: string, to: string|null}>
     */
    public static function recommendedSteps(
        string $action,
        ?string $subjectType,
        ?int $subjectId,
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
        if (in_array($action, ['account_suspended', 'account_reactivated', 'identity_verified', 'user_created', 'email_verified', 'tenant_profile_updated'])) {
            return [['label' => 'View users', 'to' => '/app/users']];
        }

        // Listing moderation
        if (in_array($action, ['listing_submitted', 'listing_published', 'listing_rejected', 'listing_created', 'listing_updated', 'listing_deleted'])) {
            return [['label' => 'Open listing review', 'to' => '/app/moderation']];
        }

        // Ledger / payment events
        if (in_array($action, [
            'payment_intent_created', 'payment_recorded',
            'rent_entry_created', 'late_fee_applied', 'entry_marked_overdue',
            'ledger_entry_marked_overdue', 'entry_paid', 'entry_waived', 'rent_entry_automated',
        ])) {
            return [['label' => 'View ledger', 'to' => '/app/ledger']];
        }

        // Contract events
        if (in_array($action, ['contract_created', 'contract_sent', 'contract_accepted', 'contract_terminated', 'contract_force_terminated'])) {
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
