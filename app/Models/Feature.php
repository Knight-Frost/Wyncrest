<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Feature Model
 * 
 * Master list of all gateable features.
 * Features are enabled/disabled per landlord via LandlordFeature pivot.
 */
class Feature extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'requires_features',
        'requires_identity_verification',
        'enabled_by_default',
        'is_available',
    ];

    protected $casts = [
        'requires_features' => 'array',
        'requires_identity_verification' => 'boolean',
        'enabled_by_default' => 'boolean',
        'is_available' => 'boolean',
    ];

    /**
     * Scope: Only available features
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    /**
     * Relationships
     */
    
    public function landlords()
    {
        return $this->belongsToMany(User::class, 'landlord_features', 'feature_id', 'landlord_id')
            ->withPivot(['enabled', 'enabled_by', 'enabled_at', 'disabled_by', 'disabled_at', 'notes'])
            ->withTimestamps();
    }

    public function enabledForLandlords()
    {
        return $this->landlords()->wherePivot('enabled', true);
    }
}

/**
 * LandlordFeature Model
 * 
 * Pivot model for feature gating.
 * Tracks which features are enabled per landlord with full audit trail.
 */
class LandlordFeature extends Model
{
    use HasFactory;

    protected $fillable = [
        'landlord_id',
        'feature_id',
        'enabled',
        'enabled_by',
        'enabled_at',
        'disabled_by',
        'disabled_at',
        'notes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    
    public function landlord()
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function feature()
    {
        return $this->belongsTo(Feature::class);
    }

    public function enabledByAdmin()
    {
        return $this->belongsTo(Admin::class, 'enabled_by');
    }

    public function disabledByAdmin()
    {
        return $this->belongsTo(Admin::class, 'disabled_by');
    }
}
