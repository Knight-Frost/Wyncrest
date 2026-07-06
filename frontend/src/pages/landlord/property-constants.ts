import type { AddressVisibility, PropertyAmenity, PropertyType, ResponsibilityParty } from '@/lib/types';

/** Property types the backend enum accepts (shared by the page filter + drawer + wizard). */
export const PROPERTY_TYPES: PropertyType[] = [
  'single_family',
  'multi_family',
  'apartment',
  'condo',
  'townhouse',
  'duplex',
  'studio_block',
  'compound_house',
  'commercial',
  'mixed_use',
  'other',
];

export const ADDRESS_VISIBILITY_OPTIONS: { value: AddressVisibility; label: string; hint: string }[] = [
  {
    value: 'area_only',
    label: 'Area only',
    hint: 'Tenants see the city/area before applying. Full street address is never exposed publicly.',
  },
  {
    value: 'full_after_approval',
    label: 'Full address after approval',
    hint: 'Same as area only today — full-address release on approved applications is a future capability.',
  },
  {
    value: 'public',
    label: 'Public',
    hint: 'The full street address is shown on the public listing to anyone.',
  },
];

export const RESPONSIBILITY_OPTIONS: { value: ResponsibilityParty; label: string }[] = [
  { value: 'landlord', label: 'Landlord' },
  { value: 'tenant', label: 'Tenant' },
  { value: 'shared', label: 'Shared' },
];

/** Grouped building-level amenities (mirrors App\Enums\PropertyAmenity::grouped()). */
export const AMENITY_CATEGORIES: { key: string; label: string; options: { value: PropertyAmenity; label: string }[] }[] = [
  {
    key: 'safety',
    label: 'Safety',
    options: [
      { value: 'gated', label: 'Gated' },
      { value: 'security_guard', label: 'Security guard' },
      { value: 'cctv', label: 'CCTV' },
      { value: 'fire_extinguisher', label: 'Fire extinguisher' },
      { value: 'smoke_detector', label: 'Smoke detector' },
    ],
  },
  {
    key: 'utilities',
    label: 'Utilities',
    options: [
      { value: 'water', label: 'Water' },
      { value: 'electricity', label: 'Electricity' },
      { value: 'backup_generator', label: 'Backup generator' },
      { value: 'internet_ready', label: 'Internet ready' },
      { value: 'waste_collection', label: 'Waste collection' },
    ],
  },
  {
    key: 'comfort',
    label: 'Comfort',
    options: [
      { value: 'air_conditioning', label: 'Air conditioning' },
      { value: 'furnished', label: 'Furnished' },
      { value: 'balcony', label: 'Balcony' },
      { value: 'laundry', label: 'Laundry' },
      { value: 'elevator', label: 'Elevator' },
    ],
  },
  {
    key: 'parking',
    label: 'Parking',
    options: [
      { value: 'street_parking', label: 'Street parking' },
      { value: 'private_parking', label: 'Private parking' },
      { value: 'covered_parking', label: 'Covered parking' },
    ],
  },
  {
    key: 'outdoor',
    label: 'Outdoor / common',
    options: [
      { value: 'compound', label: 'Compound' },
      { value: 'garden', label: 'Garden' },
      { value: 'pool', label: 'Pool' },
      { value: 'gym', label: 'Gym' },
      { value: 'shared_courtyard', label: 'Shared courtyard' },
    ],
  },
];
