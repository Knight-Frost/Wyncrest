<?php

namespace App\Enums;

/**
 * PropertyAmenity Enum
 *
 * Building/property-level amenities, grouped into fixed categories so the
 * frontend can render structured checkbox groups instead of a free-text box.
 * Distinct from Unit::$amenities (interior, per-unit amenities).
 */
enum PropertyAmenity: string
{
    // Safety
    case GATED = 'gated';
    case SECURITY_GUARD = 'security_guard';
    case CCTV = 'cctv';
    case FIRE_EXTINGUISHER = 'fire_extinguisher';
    case SMOKE_DETECTOR = 'smoke_detector';

    // Utilities
    case WATER = 'water';
    case ELECTRICITY = 'electricity';
    case BACKUP_GENERATOR = 'backup_generator';
    case INTERNET_READY = 'internet_ready';
    case WASTE_COLLECTION = 'waste_collection';

    // Comfort
    case AIR_CONDITIONING = 'air_conditioning';
    case FURNISHED = 'furnished';
    case BALCONY = 'balcony';
    case LAUNDRY = 'laundry';
    case ELEVATOR = 'elevator';

    // Parking
    case STREET_PARKING = 'street_parking';
    case PRIVATE_PARKING = 'private_parking';
    case COVERED_PARKING = 'covered_parking';

    // Outdoor / common
    case COMPOUND = 'compound';
    case GARDEN = 'garden';
    case POOL = 'pool';
    case GYM = 'gym';
    case SHARED_COURTYARD = 'shared_courtyard';

    public function category(): string
    {
        return match ($this) {
            self::GATED, self::SECURITY_GUARD, self::CCTV, self::FIRE_EXTINGUISHER, self::SMOKE_DETECTOR => 'safety',
            self::WATER, self::ELECTRICITY, self::BACKUP_GENERATOR, self::INTERNET_READY, self::WASTE_COLLECTION => 'utilities',
            self::AIR_CONDITIONING, self::FURNISHED, self::BALCONY, self::LAUNDRY, self::ELEVATOR => 'comfort',
            self::STREET_PARKING, self::PRIVATE_PARKING, self::COVERED_PARKING => 'parking',
            self::COMPOUND, self::GARDEN, self::POOL, self::GYM, self::SHARED_COURTYARD => 'outdoor',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GATED => 'Gated',
            self::SECURITY_GUARD => 'Security guard',
            self::CCTV => 'CCTV',
            self::FIRE_EXTINGUISHER => 'Fire extinguisher',
            self::SMOKE_DETECTOR => 'Smoke detector',
            self::WATER => 'Water',
            self::ELECTRICITY => 'Electricity',
            self::BACKUP_GENERATOR => 'Backup generator',
            self::INTERNET_READY => 'Internet ready',
            self::WASTE_COLLECTION => 'Waste collection',
            self::AIR_CONDITIONING => 'Air conditioning',
            self::FURNISHED => 'Furnished',
            self::BALCONY => 'Balcony',
            self::LAUNDRY => 'Laundry',
            self::ELEVATOR => 'Elevator',
            self::STREET_PARKING => 'Street parking',
            self::PRIVATE_PARKING => 'Private parking',
            self::COVERED_PARKING => 'Covered parking',
            self::COMPOUND => 'Compound',
            self::GARDEN => 'Garden',
            self::POOL => 'Pool',
            self::GYM => 'Gym',
            self::SHARED_COURTYARD => 'Shared courtyard',
        };
    }

    /**
     * Grouped for frontend rendering: ['safety' => [...], 'utilities' => [...], ...]
     *
     * @return array<string,array<int,array{value:string,label:string}>>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::cases() as $case) {
            $groups[$case->category()][] = ['value' => $case->value, 'label' => $case->label()];
        }

        return $groups;
    }
}
