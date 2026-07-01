<?php

namespace App\Models;

use App\Enums\ListingStatus;
use App\Enums\UnitAvailabilityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unit Model
 *
 * Units belong to properties.
 * Units are the actual rentable space.
 */
class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'property_id',
        'unit_number',
        'internal_name',
        'bedrooms',
        'bathrooms',
        'square_feet',
        'rent_amount',
        'security_deposit',
        'availability_status',
        'available_from',
        'amenities',
        'is_active',
    ];

    protected $casts = [
        'bedrooms' => 'decimal:1',
        'bathrooms' => 'decimal:1',
        'square_feet' => 'integer',
        'rent_amount' => 'decimal:2',
        'security_deposit' => 'decimal:2',
        'availability_status' => UnitAvailabilityStatus::class,
        'available_from' => 'date',
        'amenities' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get display name for unit
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->unit_number) {
            return "Unit {$this->unit_number}";
        }

        return $this->property->name;
    }

    /**
     * Check if unit can be listed
     */
    public function canBeListed(): bool
    {
        return $this->is_active && $this->availability_status->canBeListed();
    }

    /**
     * Scope: Only active units
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Only available units
     */
    public function scopeAvailable($query)
    {
        return $query->where('availability_status', UnitAvailabilityStatus::AVAILABLE);
    }

    /**
     * Scope: Price range
     */
    public function scopePriceBetween($query, ?float $min, ?float $max)
    {
        if ($min !== null) {
            $query->where('rent_amount', '>=', $min);
        }

        if ($max !== null) {
            $query->where('rent_amount', '<=', $max);
        }

        return $query;
    }

    /**
     * Scope: Bedrooms
     */
    public function scopeWithBedrooms($query, $bedrooms)
    {
        return $query->where('bedrooms', $bedrooms);
    }

    /**
     * Scope: Bathrooms
     */
    public function scopeWithBathrooms($query, $bathrooms)
    {
        return $query->where('bathrooms', '>=', $bathrooms);
    }

    /**
     * Relationships
     */
    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function listings()
    {
        return $this->hasMany(Listing::class);
    }

    public function activeListing()
    {
        return $this->hasOne(Listing::class)->where('status', 'active');
    }

    /**
     * Listing that blocks creation of a new one.
     *
     * why: a unit may only have one listing in-flight at a time. Draft and
     * pending_review listings are in-flight just as much as an active one —
     * the frontend already enforces this; the backend must too. Soft-deleted
     * listings are excluded automatically by SoftDeletes scoping.
     * BLOCKING_STATUSES = ['draft', 'pending_review', 'active']
     */
    public function blockingListing()
    {
        return $this->hasOne(Listing::class)->whereIn('status', [
            ListingStatus::DRAFT->value,
            ListingStatus::PENDING_REVIEW->value,
            ListingStatus::ACTIVE->value,
        ]);
    }

    /**
     * Gallery images for this unit via the media_assets system.
     */
    public function mediaAssets()
    {
        return $this->morphMany(MediaAsset::class, 'attachable')
            ->where('collection', \App\Enums\MediaCollection::UnitGallery->value)
            ->where('status', 'active')
            ->ordered();
    }
}
