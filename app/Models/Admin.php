<?php

namespace App\Models;

use App\Enums\AdminCapability;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Admin Model
 *
 * Completely separate from User model.
 *
 * Two tiers exist:
 *  - Super Admin (is_super_admin=true): full platform authority. Holds ALL
 *    capabilities implicitly and bypasses every capability check. Only a super
 *    admin may manage the admin team (invite, promote, change capabilities).
 *  - Regular Admin (is_super_admin=false): holds only the granular capabilities
 *    stored in `capabilities` (see App\Enums\AdminCapability), enforced by the
 *    EnsureAdminCan middleware on admin routes.
 */
class Admin extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'password',
        'name',
        'is_super_admin',
        'is_active',
        'capabilities',
        'invited_at',
        'invite_accepted_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_super_admin' => 'boolean',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'invited_at' => 'datetime',
        'invite_accepted_at' => 'datetime',
        'capabilities' => 'array',
        'password' => 'hashed',
    ];

    /**
     * Check if this admin is a super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    /**
     * Does this admin hold the given capability?
     *
     * Super admins implicitly hold every capability. Regular admins hold only
     * what is stored in `capabilities`. This is the ONE method the enforcement
     * middleware and any service-level check should call.
     */
    public function hasCapability(AdminCapability|string $capability): bool
    {
        if ($this->is_super_admin === true) {
            return true;
        }

        $value = $capability instanceof AdminCapability ? $capability->value : $capability;

        return in_array($value, $this->capabilities ?? [], true);
    }

    /**
     * The effective capability values this admin holds.
     *
     * Super admin => all capabilities (so the UI can render them as granted +
     * locked). Regular admin => the stored subset.
     *
     * @return list<string>
     */
    public function grantedCapabilities(): array
    {
        if ($this->is_super_admin === true) {
            return AdminCapability::values();
        }

        return array_values(array_intersect(AdminCapability::values(), $this->capabilities ?? []));
    }

    /**
     * A pending invite = created via the invite flow and never accepted.
     */
    public function isPendingInvite(): bool
    {
        return $this->invited_at !== null && $this->invite_accepted_at === null;
    }

    /**
     * The admin identity payload the SPA consumes for its session.
     *
     * This is the single source of truth for what an authenticated admin looks
     * like on the wire — used by the cookie-session endpoints (login / me). It
     * deliberately exposes only display + authorization fields and never a
     * token: admin auth is carried by the HttpOnly session cookie, not by any
     * value JavaScript can read. `capabilities` are the EFFECTIVE set (a super
     * admin implicitly holds all of them) so the UI can reflect access truthfully.
     *
     * @return array<string, mixed>
     */
    public function toAuthPayload(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'is_super_admin' => $this->is_super_admin,
            'is_active' => $this->is_active,
            'capabilities' => $this->grantedCapabilities(),
            // Admins live in a separate table with no media; always initials.
            'avatar_url' => null,
            'last_login_at' => $this->last_login_at?->toISOString(),
        ];
    }

    /**
     * Update last login timestamp
     */
    public function recordLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Prevent deletion of established admin accounts.
     *
     * Admin actions form a permanent audit trail, so an admin who has ever
     * accepted their invite / logged in can NEVER be deleted (removing them is
     * done via deactivation instead). The ONE exception is revoking a *pending
     * invite* — an admin record that was created by the invite flow and has
     * never been accepted or used to sign in. That carries no history, so a
     * super admin may cleanly revoke it.
     *
     * @throws \RuntimeException when the admin is established (not a pending invite)
     */
    public function delete()
    {
        if (! $this->isPendingInvite() || $this->last_login_at !== null) {
            throw new \RuntimeException(
                'Established admin accounts cannot be deleted. Deactivate the '.
                'admin instead. Only an unaccepted pending invite may be revoked.'
            );
        }

        return parent::delete();
    }

    /**
     * Force-deletion is never permitted (no soft deletes on admins anyway).
     *
     * @throws \RuntimeException Always
     */
    public function forceDelete()
    {
        throw new \RuntimeException('Admin accounts cannot be force-deleted.');
    }

    /**
     * Scope: Only active admins
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Relationships
     */

    // Listings reviewed by this admin
    public function reviewedListings()
    {
        return $this->hasMany(Listing::class, 'reviewed_by');
    }

    // Features enabled by this admin
    public function enabledFeatures()
    {
        return $this->hasMany(LandlordFeature::class, 'enabled_by');
    }

    // Audit trail
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'actor');
    }
}
