<?php

namespace App\Enums;

/**
 * AdminCapability
 *
 * The granular, ENFORCED capabilities a regular (non-super) admin may hold.
 * This enum is the single source of truth consumed by:
 *   - EnsureAdminCan middleware (backend enforcement on admin routes)
 *   - AdminAccessService / FormRequests (validation of grant/revoke)
 *   - The read-only role/permission matrix (presentation)
 *
 * Super admins implicitly hold ALL capabilities and bypass every check
 * (see Admin::hasCapability). Tenant/Landlord capabilities are NOT modelled
 * here — those are baseline role boundaries enforced by route middleware and
 * per-resource policies, and are shown in the matrix as locked/read-only.
 */
enum AdminCapability: string
{
    case MANAGE_ACCESS = 'manage_access';
    case MANAGE_USERS = 'manage_users';
    case REVIEW_VERIFICATIONS = 'review_verifications';
    case MODERATE_LISTINGS = 'moderate_listings';
    case MODERATE_REVIEWS = 'moderate_reviews';
    case MANAGE_FEATURES = 'manage_features';
    case VIEW_AUDIT = 'view_audit';
    case MANAGE_CONTRACTS = 'manage_contracts';
    case MANAGE_LEDGER = 'manage_ledger';
    case VIEW_ANALYTICS = 'view_analytics';
    case MANAGE_SETTINGS = 'manage_settings';

    /**
     * Human label for the matrix / UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::MANAGE_ACCESS => 'Manage access & admins',
            self::MANAGE_USERS => 'Manage users',
            self::REVIEW_VERIFICATIONS => 'Review verifications',
            self::MODERATE_LISTINGS => 'Moderate listings',
            self::MODERATE_REVIEWS => 'Moderate reviews',
            self::MANAGE_FEATURES => 'Manage landlord features',
            self::VIEW_AUDIT => 'View audit log',
            self::MANAGE_CONTRACTS => 'Manage contracts',
            self::MANAGE_LEDGER => 'Manage ledger',
            self::VIEW_ANALYTICS => 'View analytics',
            self::MANAGE_SETTINGS => 'Manage platform settings',
        };
    }

    /**
     * One-line description of what the capability grants.
     */
    public function description(): string
    {
        return match ($this) {
            self::MANAGE_ACCESS => 'Open this page and manage the admin team',
            self::MANAGE_USERS => 'Suspend, restore, block or archive tenants & landlords (every admin can already view the roster)',
            self::REVIEW_VERIFICATIONS => 'Approve or reject identity verification requests',
            self::MODERATE_LISTINGS => 'Approve or reject listings in the review queue',
            self::MODERATE_REVIEWS => 'Moderate tenant reviews of properties',
            // why: manage_features IS enforced on the feature endpoints, but the
            // admin SPA has no screen that calls them yet — say so honestly.
            self::MANAGE_FEATURES => 'Enable or disable per-landlord platform features (API only; no admin UI surface yet)',
            self::VIEW_AUDIT => 'Read and export the immutable audit log',
            self::MANAGE_CONTRACTS => 'Add case notes and force-terminate contracts (every admin can already view contracts)',
            self::MANAGE_LEDGER => 'Apply late fees on ledger entries (every admin can already view the ledger)',
            self::VIEW_ANALYTICS => 'View platform-wide analytics dashboards',
            self::MANAGE_SETTINGS => 'Change global platform configuration',
        };
    }

    /**
     * Matrix grouping (mirrors the mockup's capability groups).
     */
    public function group(): string
    {
        return match ($this) {
            self::MANAGE_ACCESS, self::MANAGE_USERS => 'Users & access',
            self::REVIEW_VERIFICATIONS, self::MODERATE_LISTINGS, self::MODERATE_REVIEWS => 'Content moderation',
            self::MANAGE_CONTRACTS, self::MANAGE_LEDGER => 'Finance',
            self::MANAGE_FEATURES, self::VIEW_AUDIT, self::VIEW_ANALYTICS, self::MANAGE_SETTINGS => 'Platform',
        };
    }

    /**
     * Whether this capability is actually enforced by a backend route today.
     *
     * // why: manage_settings is defined for forward-compatibility, but no
     * // platform-settings endpoints exist yet, so the matrix shows it as
     * // "defined, not yet enforced" instead of pretending it gates something.
     */
    public function isEnforced(): bool
    {
        return $this !== self::MANAGE_SETTINGS;
    }

    /**
     * All capability string values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
