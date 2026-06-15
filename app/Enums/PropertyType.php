<?php

namespace App\Enums;

/**
 * PropertyType Enum
 *
 * Defines property classification types.
 */
enum PropertyType: string
{
    case SINGLE_FAMILY = 'single_family';
    case MULTI_FAMILY = 'multi_family';
    case APARTMENT = 'apartment';
    case CONDO = 'condo';
    case TOWNHOUSE = 'townhouse';
    case COMMERCIAL = 'commercial';
    case OTHER = 'other';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::SINGLE_FAMILY => 'Single Family Home',
            self::MULTI_FAMILY => 'Multi-Family',
            self::APARTMENT => 'Apartment',
            self::CONDO => 'Condo',
            self::TOWNHOUSE => 'Townhouse',
            self::COMMERCIAL => 'Commercial',
            self::OTHER => 'Other',
        };
    }

    /**
     * Get all values for dropdowns
     */
    public static function options(): array
    {
        return array_map(
            fn ($case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
