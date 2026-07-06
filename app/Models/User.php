<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\MediaCollection;
use App\Enums\UserType;
use App\Enums\VerificationStatus;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
class User extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'user_type',
        'email',
        'email_verified_at',
        'password',
        'google_id',
        'first_name',
        'last_name',
        'phone',
        'city',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'date_of_birth',
        'identity_verified',
        'identity_verified_at',
        'identity_verified_by',
        'verification_status',
        'account_status',
        'is_active',
        'suspended_at',
        'suspension_reason',
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
        'verification_status' => VerificationStatus::class,
        'account_status' => AccountStatus::class,
    ];

    /**
     * Always expose the profile photo URL so every user-identity surface (sidebar,
     * messages, applicant/tenant/review lists, admin) can render the avatar with an
     * initials fallback. Backed by the eager-loadable `avatarAsset` relation, so
     * heavy endpoints can preload it to avoid an N+1.
     */
    protected $appends = ['avatar_url', 'full_name'];

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
     * Check if the user's identity verification has been approved.
     */
    public function isVerified(): bool
    {
        return $this->verification_status === VerificationStatus::VERIFIED;
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get the user's initials (e.g. "AS"). Used for avatar chips.
     */
    public function getInitialsAttribute(): string
    {
        $first = mb_substr((string) $this->first_name, 0, 1);
        $last = mb_substr((string) $this->last_name, 0, 1);
        $initials = mb_strtoupper($first.$last);

        return $initials !== '' ? $initials : 'U';
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

    // Applications submitted by this tenant
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'tenant_id');
    }

    // Applications directed at this landlord's listings
    public function landlordApplications(): HasMany
    {
        return $this->hasMany(Application::class, 'landlord_id');
    }

    // Maintenance requests raised by this tenant
    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'tenant_id');
    }

    // Maintenance requests on this landlord's properties
    public function landlordMaintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'landlord_id');
    }

    // Documents owned by this user
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'owner_user_id');
    }

    // Audit trail
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'actor');
    }

    /**
     * Get user's notification preferences
     *
     * Phase 3.8: User preferences for notification channels
     * Phase 3.9: Includes delivery_mode for digest timing
     */
    public function notificationPreferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

    // Verification requests submitted by this user
    public function verificationRequests(): HasMany
    {
        return $this->hasMany(VerificationRequest::class);
    }

    public function latestVerificationRequest(): HasOne
    {
        return $this->hasOne(VerificationRequest::class)->latestOfMany();
    }

    // -------------------------------------------------------------------------
    // Media / Avatar
    // -------------------------------------------------------------------------

    /**
     * All MediaAssets owned by this user.
     */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'owner_user_id');
    }

    /**
     * The user's current active avatar as a single relation, so it can be
     * eager-loaded (User::with('avatarAsset')) to avoid N+1 when serializing lists.
     */
    public function avatarAsset(): HasOne
    {
        return $this->hasOne(MediaAsset::class, 'owner_user_id')->ofMany(
            ['id' => 'max'],
            fn ($q) => $q->where('collection', MediaCollection::Avatar->value)->where('status', 'active'),
        );
    }

    /**
     * The public URL of the user's current active avatar, or null if none.
     * Uses the eager-loaded relation when present, otherwise lazy-loads it.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatarAsset?->url;
    }

    /**
     * Send a password reset link pointing at the SPA, not a Blade view.
     * Logs the intent to EmailLog.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $url = $frontendUrl.'/reset-password?token='.urlencode($token).'&email='.urlencode($this->email);

        $this->notify(new ResetPasswordNotification($url));

        // Log the intent (status = queued; actual delivery tracked by the mailer).
        // related_* points at the user (self) as the triggering entity.
        EmailLog::create([
            'recipient_type' => self::class,
            'recipient_id' => $this->id,
            'recipient_email' => $this->email,
            'subject' => 'Reset your '.config('brand.display_name').' password',
            'mailable_class' => ResetPasswordNotification::class,
            'email_type' => 'security',
            'related_type' => self::class,
            'related_id' => $this->id,
            'status' => 'queued',
            'sent_at' => now(),
        ]);
    }
}
