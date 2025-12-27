<?php

namespace App\Models;

use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Listing Model
 * 
 * Public-facing representation of units.
 * Moderatable by admins.
 */
class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'unit_id',
        'landlord_id',
        'title',
        'description',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'published_at',
        'expires_at',
        'featured',
        'view_count',
        'pets_allowed',
        'pet_policy',
        'lease_duration_months',
        'move_in_date',
    ];

    protected $casts = [
        'status' => ListingStatus::class,
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'featured' => 'boolean',
        'view_count' => 'integer',
        'pets_allowed' => 'boolean',
        'lease_duration_months' => 'integer',
        'move_in_date' => 'date',
    ];

    /**
     * Increment view count
     */
    public function incrementViews(): void
    {
        $this->increment('view_count');
    }

    /**
     * Check if listing is publicly visible
     */
    public function isPublic(): bool
    {
        return $this->status === ListingStatus::ACTIVE && $this->published_at !== null;
    }

    /**
     * Check if listing can be edited
     */
    public function canBeEdited(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Scope: Only public listings
     */
    public function scopePublic($query)
    {
        return $query->where('status', ListingStatus::ACTIVE)
            ->whereNotNull('published_at')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope: Pending review
     */
    public function scopePendingReview($query)
    {
        return $query->where('status', ListingStatus::PENDING_REVIEW);
    }

    /**
     * Scope: Featured listings
     */
    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    /**
     * Scope: Search by keyword
     */
    public function scopeSearch($query, ?string $keyword)
    {
        if (empty($keyword)) {
            return $query;
        }

        return $query->where(function($q) use ($keyword) {
            $q->where('title', 'ILIKE', "%{$keyword}%")
              ->orWhere('description', 'ILIKE', "%{$keyword}%");
        });
    }

    /**
     * Scope: Filter by location
     */
    public function scopeInLocation($query, ?string $city = null, ?string $state = null, ?string $zipCode = null)
    {
        return $query->whereHas('unit.property', function($q) use ($city, $state, $zipCode) {
            if ($city) {
                $q->where('city', 'ILIKE', "%{$city}%");
            }
            if ($state) {
                $q->where('state', strtoupper($state));
            }
            if ($zipCode) {
                $q->where('zip_code', $zipCode);
            }
        });
    }

    /**
     * Scope: Price range
     */
    public function scopePriceRange($query, ?float $minPrice = null, ?float $maxPrice = null)
    {
        return $query->whereHas('unit', function($q) use ($minPrice, $maxPrice) {
            $q->priceBetween($minPrice, $maxPrice);
        });
    }

    /**
     * Scope: Bedrooms
     */
    public function scopeWithBedrooms($query, ?int $bedrooms = null)
    {
        if ($bedrooms === null) {
            return $query;
        }

        return $query->whereHas('unit', function($q) use ($bedrooms) {
            $q->where('bedrooms', $bedrooms);
        });
    }

    /**
     * Scope: Property type
     */
    public function scopeOfPropertyType($query, ?string $propertyType = null)
    {
        if ($propertyType === null) {
            return $query;
        }

        return $query->whereHas('unit.property', function($q) use ($propertyType) {
            $q->where('property_type', $propertyType);
        });
    }

    /**
     * Relationships
     */
    
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function landlord()
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function photos()
    {
        return $this->hasMany(ListingPhoto::class)->orderBy('sort_order');
    }

    public function primaryPhoto()
    {
        return $this->hasOne(ListingPhoto::class)->where('is_primary', true);
    }

    public function savedByUsers()
    {
        return $this->belongsToMany(User::class, 'saved_listings')
            ->withPivot('notes')
            ->withTimestamps();
    }
}
