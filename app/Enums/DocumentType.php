<?php

namespace App\Enums;

enum DocumentType: string
{
    case IDENTITY_DOCUMENT = 'identity_document';
    case PROOF_OF_ADDRESS = 'proof_of_address';
    case PROOF_OF_INCOME = 'proof_of_income';
    case LEASE_DOCUMENT = 'lease_document';
    case APPLICATION_ATTACHMENT = 'application_attachment';
    case MAINTENANCE_ATTACHMENT = 'maintenance_attachment';
    case OTHER = 'other';

    /**
     * Human-readable label for the document type.
     */
    public function label(): string
    {
        return match ($this) {
            self::IDENTITY_DOCUMENT => 'Identity Document',
            self::PROOF_OF_ADDRESS => 'Proof of Address',
            self::PROOF_OF_INCOME => 'Proof of Income',
            self::LEASE_DOCUMENT => 'Lease Document',
            self::APPLICATION_ATTACHMENT => 'Application Attachment',
            self::MAINTENANCE_ATTACHMENT => 'Maintenance Attachment',
            self::OTHER => 'Other',
        };
    }
}
