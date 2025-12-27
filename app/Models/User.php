<?php

namespace App\Models;

use App\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * User Model
 * 
 * Represents both tenants and landlords.
 * Role is strictly enforced via user_type enum.
 * Admins are NOT in this table.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'user_type',
        'email',
        'password',
        'first_name',
        'last_name',
        'phone',
        'date_of_birth',
        'identity_verified',
        'identity_verified_at',
        'identity_verified_by',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'user_type' => UserType::class,
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'date_of_birth' => 'date',
        'identity_verified' => 'boolean',
        'identity_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'suspended_at' => 'datetime',
    ];

    /**
     * Check if user is a landlord
     */
    public function isLandlord(): bool
    {
        return $this->user_type === UserType::LANDLORD;
    }

    /**
     * Check if user is a tenant
     */
    public function isTenant(): bool
    {
        return $this->user_type === UserType::TENANT;
    }

    /**
     * Check if identity is verified
     */
    public function hasVerifiedIdentity(): bool
    {
        return $this->identity_verified === true;
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Scope: Only landlords
     */
    public function scopeLandlords($query)
    {
        return $query->where('user_type', UserType::LANDLORD);
    }

    /**
     * Scope: Only tenants
     */
    public function scopeTenants($query)
    {
        return $query->where('user_type', UserType::TENANT);
    }

    /**
     * Scope: Only active users
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('suspended_at');
    }

    /**
     * Relationships
     */
    
    // Landlord relationships
    public function properties()
    {
        return $this->hasMany(Property::class, 'landlord_id');
    }

    public function listings()
    {
        return $this->hasMany(Listing::class, 'landlord_id');
    }

    public function enabledFeatures()
    {
        return $this->belongsToMany(Feature::class, 'landlord_features', 'landlord_id')
            ->wherePivot('enabled', true)
            ->withTimestamps();
    }

    // Tenant relationships
    public function savedListings()
    {
        return $this->belongsToMany(Listing::class, 'saved_listings')
            ->withPivot('notes')
            ->withTimestamps();
    }

    // Audit trail
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'actor');
    }
}
