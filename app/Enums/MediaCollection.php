<?php

namespace App\Enums;

enum MediaCollection: string
{
    case PropertyGallery = 'property_gallery';
    case UnitGallery = 'unit_gallery';
    case ListingGallery = 'listing_gallery';
    case Avatar = 'avatar';
    case MaintenanceEvidence = 'maintenance_evidence';
    case ContractDocument = 'contract_document';

    /**
     * Collections that are attached to landlord-owned resources.
     * Tenants may NOT upload to these.
     */
    public function isGallery(): bool
    {
        return in_array($this, [
            self::PropertyGallery,
            self::UnitGallery,
            self::ListingGallery,
        ], true);
    }
}
