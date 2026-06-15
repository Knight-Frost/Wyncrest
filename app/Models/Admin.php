<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Admin Model
 *
 * Completely separate from User model.
 * Phase 1: All admins are Super Admins.
 * Phase 4: RBAC expansion.
 */
class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'email',
        'password',
        'name',
        'is_super_admin',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_super_admin' => 'boolean',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
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
     * Update last login timestamp
     */
    public function recordLogin(): void
    {
        $this->update(['last_login_at' => now()]);
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
