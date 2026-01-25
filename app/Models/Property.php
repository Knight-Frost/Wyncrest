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
        'is_active',
    ];

    protected $casts = [
        'property_type' => PropertyType::class,
        'year_built' => 'integer',
        'lot_size' => 'decimal:2',
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
        return $query->whereRaw('LOWER(city) LIKE ?', ['%' . strtolower($city) . '%']);
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
}
