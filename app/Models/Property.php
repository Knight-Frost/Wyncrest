<?php

namespace App\Models;

use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Property Model
 *
 * Properties are owned by landlords.
 * Properties contain units.
 */
class Property extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'landlord_id',
        'name',
        'property_type',
        'street_address',
        'street_address_2',
        'city',
        'state',
        'zip_code',
        'country',
        'year_built',
        'lot_size',
        'description',
        'parking',
        'pet_policy',
        'smoking_policy',
        'amenities',
        'rules',
        'address_visibility',
        'is_active',
    ];

    protected $casts = [
        'property_type' => PropertyType::class,
        'year_built' => 'integer',
        'lot_size' => 'decimal:2',
        'amenities' => 'array',
        'rules' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get full address.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = [
            $this->street_address,
            $this->street_address_2,
            "{$this->city}, {$this->state} {$this->zip_code}",
        ];

        return implode(', ', array_filter($parts));
    }

    /**
     * Address fields safe to expose to tenants/public viewers, honoring
     * address_visibility. The street address is only ever exposed when the
     * landlord has explicitly opted into 'public'; otherwise viewers only
     * learn the general area. There is no application/contract-aware "after
     * approval" check yet, so 'full_after_approval' behaves like 'area_only'
     * on today's fully-anonymous public listing endpoints.
     *
     * @return array{city:string,state:string,area:string,street_address:?string,street_address_2:?string,full_address:?string}
     */
    public function publicAddress(): array
    {
        $area = trim("{$this->city}, {$this->state}", ', ');

        if ($this->address_visibility === 'public') {
            return [
                'city' => $this->city,
                'state' => $this->state,
                'area' => $area,
                'street_address' => $this->street_address,
                'street_address_2' => $this->street_address_2,
                'full_address' => $this->full_address,
            ];
        }

        return [
            'city' => $this->city,
            'state' => $this->state,
            'area' => $area,
            'street_address' => null,
            'street_address_2' => null,
            'full_address' => null,
        ];
    }

    /**
     * Scope: Only active properties.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: By city (case-insensitive, portable SQL).
     */
    public function scopeInCity($query, string $city)
    {
        // Use portable LOWER() instead of PostgreSQL-specific ILIKE
        return $query->whereRaw('LOWER(city) LIKE ?', ['%'.strtolower($city).'%']);
    }

    /**
     * Scope: By state.
     */
    public function scopeInState($query, string $state)
    {
        return $query->where('state', strtoupper($state));
    }

    /**
     * Scope: By zip code.
     */
    public function scopeInZipCode($query, string $zipCode)
    {
        return $query->where('zip_code', $zipCode);
    }

    /**
     * Relationships
     */
    public function landlord()
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function units()
    {
        return $this->hasMany(Unit::class);
    }

    public function activeUnits()
    {
        return $this->hasMany(Unit::class)->where('is_active', true);
    }

    /**
     * Gallery images for this property via the media_assets system.
     * Coexists with ListingPhoto on Listing; planned future consolidation.
     */
    public function mediaAssets()
    {
        return $this->morphMany(MediaAsset::class, 'attachable')
            ->where('collection', \App\Enums\MediaCollection::PropertyGallery->value)
            ->where('status', 'active')
            ->ordered();
    }

    /**
     * All reviews for this property.
     */
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Approved reviews for this property (publicly visible).
     */
    public function approvedReviews()
    {
        return $this->hasMany(Review::class)->where('status', \App\Enums\ReviewStatus::APPROVED);
    }

    /**
     * Average rating computed from approved reviews only (1 decimal, or null if none).
     */
    public function getAverageRatingAttribute(): ?float
    {
        $avg = $this->approvedReviews()->avg('rating');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Count of approved reviews.
     */
    public function getReviewCountAttribute(): int
    {
        return $this->approvedReviews()->count();
    }
}
