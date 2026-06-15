<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * AuditService
 *
 * Centralized service for creating audit logs.
 * All critical actions must be logged through this service.
 */
class AuditService
{
    /**
     * Log an action
     */
    public function log(
        ?Model $actor,  // Nullable for system-generated actions
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        string $severity = 'info'
    ): AuditLog {
        return AuditLog::create([
            'actor_type' => $actor ? get_class($actor) : null,
            'actor_id' => $actor?->id,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject ? $subject->id : null,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'severity' => $severity,
        ]);
    }

    /**
     * Log user creation
     */
    public function logUserCreated(Model $user, ?Model $actor = null): AuditLog
    {
        return $this->log(
            actor: $actor ?? $user,
            action: 'user_created',
            subject: $user,
            description: "User account created: {$user->email}",
            severity: 'info'
        );
    }

    /**
     * Log email verification
     */
    public function logEmailVerified(Model $user): AuditLog
    {
        return $this->log(
            actor: $user,
            action: 'email_verified',
            subject: $user,
            description: "Email verified: {$user->email}",
            severity: 'info'
        );
    }

    /**
     * Log identity verification
     */
    public function logIdentityVerified(Model $user, Model $admin): AuditLog
    {
        return $this->log(
            actor: $admin,
            action: 'identity_verified',
            subject: $user,
            description: "Identity verified for: {$user->email}",
            severity: 'warning'
        );
    }

    /**
     * Log listing published
     */
    public function logListingPublished(Model $listing, Model $actor): AuditLog
    {
        return $this->log(
            actor: $actor,
            action: 'listing_published',
            subject: $listing,
            description: "Listing published: {$listing->title}",
            severity: 'info'
        );
    }

    /**
     * Log listing rejected
     */
    public function logListingRejected(Model $listing, Model $admin, string $reason): AuditLog
    {
        return $this->log(
            actor: $admin,
            action: 'listing_rejected',
            subject: $listing,
            description: "Listing rejected: {$listing->title}",
            metadata: ['reason' => $reason],
            severity: 'warning'
        );
    }

    /**
     * Log feature enabled
     */
    public function logFeatureEnabled(Model $landlord, string $featureKey, Model $admin): AuditLog
    {
        return $this->log(
            actor: $admin,
            action: 'feature_enabled',
            subject: $landlord,
            description: "Feature '{$featureKey}' enabled for landlord: {$landlord->email}",
            metadata: ['feature_key' => $featureKey],
            severity: 'warning'
        );
    }

    /**
     * Log feature disabled
     */
    public function logFeatureDisabled(Model $landlord, string $featureKey, Model $admin): AuditLog
    {
        return $this->log(
            actor: $admin,
            action: 'feature_disabled',
            subject: $landlord,
            description: "Feature '{$featureKey}' disabled for landlord: {$landlord->email}",
            metadata: ['feature_key' => $featureKey],
            severity: 'warning'
        );
    }

    /**
     * Log admin login
     */
    public function logAdminLogin(Model $admin): AuditLog
    {
        return $this->log(
            actor: $admin,
            action: 'admin_login',
            description: "Admin logged in: {$admin->email}",
            severity: 'warning'
        );
    }

    /**
     * Log account suspension
     */
    public function logAccountSuspended(Model $user, Model $admin, string $reason): AuditLog
    {
        return $this->log(
            actor: $admin,
            action: 'account_suspended',
            subject: $user,
            description: "Account suspended: {$user->email}",
            metadata: ['reason' => $reason],
            severity: 'critical'
        );
    }

    /**
     * Log security event
     */
    public function logSecurityEvent(Model $actor, string $event, string $description, ?array $metadata = null): AuditLog
    {
        return $this->log(
            actor: $actor,
            action: $event,
            description: $description,
            metadata: $metadata,
            severity: 'critical'
        );
    }
}
