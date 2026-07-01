/**
 * Shared types for the Create Listing builder. The form mirrors the REAL
 * backend records it persists to:
 *   - Unit fields    → PUT /landlord/units/{id}
 *   - Listing fields → POST /landlord/units/{id}/listings, PUT /landlord/listings/{id}
 *   - buildingNotes  → property.description via PUT /landlord/properties/{id}
 */
export interface ListingDraftForm {
  // selection
  propertyId: number | null;
  unitId: number | null;
  // unit-owned
  unitNumber: string;
  bedrooms: string; // numeric-as-string ("0" = studio)
  bathrooms: string;
  squareFeet: string;
  rentAmount: string; // GH₵ major units
  securityDeposit: string;
  availableFrom: string; // yyyy-mm-dd
  amenities: string[];
  // listing-owned
  title: string;
  description: string;
  petsAllowed: boolean;
  petPolicy: string;
  leaseDurationMonths: string;
  moveInDate: string; // yyyy-mm-dd
  // property-owned
  buildingNotes: string;
}

export type FormErrors = Partial<Record<keyof ListingDraftForm, string>>;

export interface StepProps {
  form: ListingDraftForm;
  set: <K extends keyof ListingDraftForm>(key: K, value: ListingDraftForm[K]) => void;
  errors: FormErrors;
}

export interface StepMeta {
  key: string;
  index: number; // 1-based
  name: string;
  desc: string;
}

export const STEPS: StepMeta[] = [
  { key: 'unit', index: 1, name: 'Unit details', desc: 'Add key information about the unit.' },
  { key: 'property', index: 2, name: 'Property details', desc: 'Tell us about the building.' },
  { key: 'pricing', index: 3, name: 'Pricing', desc: 'Set rent and additional fees.' },
  { key: 'amenities', index: 4, name: 'Features & amenities', desc: 'Highlight what makes it great.' },
  { key: 'photos', index: 5, name: 'Photos', desc: 'Upload images of the unit.' },
  { key: 'review', index: 6, name: 'Review & publish', desc: 'Review and publish your listing.' },
];

/** Curated amenity tags persisted to unit.amenities (a real json string[] column). */
export const AMENITY_OPTIONS: string[] = [
  'Furnished',
  'Air conditioning',
  'Parking',
  'Backup generator',
  'Water included',
  'Electricity included',
  'Wi-Fi / Fibre internet',
  'Security',
  'Balcony',
  'Private garden',
  'Washer / Dryer',
  'Dishwasher',
  'Swimming pool',
  'Gym access',
  'Wheelchair accessible',
  'Boys quarters',
];
