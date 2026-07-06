import type { AddressVisibility, Property, PropertyType } from '@/lib/types';

/* Ghana regions → 2-letter codes (backend requires state size:2, uppercase).
   The codes are valid 2-letter uppercase strings; the list is Ghana-appropriate
   because the app assumes Ghana. Callers can still surface a pre-existing
   code on edit (see `regionOptionsFor`). */
export const GHANA_REGIONS: { code: string; name: string }[] = [
  { code: 'GA', name: 'Greater Accra' },
  { code: 'AS', name: 'Ashanti' },
  { code: 'WE', name: 'Western' },
  { code: 'WN', name: 'Western North' },
  { code: 'CE', name: 'Central' },
  { code: 'EA', name: 'Eastern' },
  { code: 'VO', name: 'Volta' },
  { code: 'OT', name: 'Oti' },
  { code: 'NO', name: 'Northern' },
  { code: 'NE', name: 'North East' },
  { code: 'SV', name: 'Savannah' },
  { code: 'UE', name: 'Upper East' },
  { code: 'UW', name: 'Upper West' },
  { code: 'BO', name: 'Bono' },
  { code: 'BE', name: 'Bono East' },
  { code: 'AH', name: 'Ahafo' },
];

/* ISO-2 country codes (backend requires country size:2, uppercase). */
export const COUNTRIES: { code: string; name: string }[] = [
  { code: 'GH', name: 'Ghana' },
  { code: 'NG', name: 'Nigeria' },
  { code: 'CI', name: 'Côte d’Ivoire' },
  { code: 'TG', name: 'Togo' },
  { code: 'BF', name: 'Burkina Faso' },
  { code: 'GB', name: 'United Kingdom' },
  { code: 'US', name: 'United States' },
];

export function regionOptionsFor(state: string): { code: string; name: string }[] {
  if (state && !GHANA_REGIONS.some((r) => r.code === state)) {
    return [{ code: state, name: state }, ...GHANA_REGIONS];
  }
  return GHANA_REGIONS;
}

export function countryOptionsFor(country: string): { code: string; name: string }[] {
  if (country && !COUNTRIES.some((c) => c.code === country)) {
    return [{ code: country, name: country }, ...COUNTRIES];
  }
  return COUNTRIES;
}

export interface PropertyForm {
  name: string;
  property_type: PropertyType | '';
  street_address: string;
  street_address_2: string;
  city: string;
  state: string;
  zip_code: string;
  country: string;
  address_visibility: AddressVisibility;
  year_built: string;
  description: string;
  parking: string;
  pet_policy: string;
  smoking_policy: string;
}

export function emptyPropertyForm(): PropertyForm {
  return {
    name: '',
    property_type: '',
    street_address: '',
    street_address_2: '',
    city: 'Accra',
    state: 'GA',
    zip_code: '',
    country: 'GH',
    address_visibility: 'area_only',
    year_built: '',
    description: '',
    parking: '',
    pet_policy: '',
    smoking_policy: '',
  };
}

export function propertyFormFromModel(p: Property): PropertyForm {
  return {
    name: p.name,
    property_type: p.property_type,
    street_address: p.street_address,
    street_address_2: p.street_address_2 ?? '',
    city: p.city,
    state: p.state,
    zip_code: p.zip_code,
    country: p.country,
    address_visibility: p.address_visibility ?? 'area_only',
    year_built: p.year_built != null ? String(p.year_built) : '',
    description: p.description ?? '',
    parking: p.parking ?? '',
    pet_policy: p.pet_policy ?? '',
    smoking_policy: p.smoking_policy ?? '',
  };
}

/** Which "page" (0 = basics, 1 = location) each field lives on. */
export const PROPERTY_FIELD_STEP: Record<string, 0 | 1> = {
  name: 0,
  property_type: 0,
  year_built: 0,
  description: 0,
  parking: 0,
  pet_policy: 0,
  smoking_policy: 0,
  street_address: 1,
  street_address_2: 1,
  city: 1,
  state: 1,
  zip_code: 1,
  country: 1,
  address_visibility: 1,
};

/** Validation mirrors StorePropertyRequest — no invented rules. */
export function validatePropertyBasics(form: PropertyForm): Record<string, string> {
  const e: Record<string, string> = {};
  if (!form.name.trim()) e.name = 'Property name is required.';
  else if (form.name.length > 255) e.name = 'Keep the name under 255 characters.';
  if (!form.property_type) e.property_type = 'Choose a property type.';
  if (form.year_built.trim()) {
    const y = Number(form.year_built);
    const max = new Date().getFullYear() + 1;
    if (!Number.isInteger(y)) e.year_built = 'Enter a valid year.';
    else if (y < 1800) e.year_built = 'Year built must be 1800 or later.';
    else if (y > max) e.year_built = 'Year built cannot be in the future.';
  }
  if (form.description.length > 2000) e.description = 'Keep the description under 2000 characters.';
  return e;
}

export function validatePropertyLocation(form: PropertyForm): Record<string, string> {
  const e: Record<string, string> = {};
  if (!form.street_address.trim()) e.street_address = 'Street address is required.';
  if (!form.city.trim()) e.city = 'City is required.';
  if (!/^[A-Z]{2}$/.test(form.state)) e.state = 'Select a region (2-letter code).';
  if (!form.zip_code.trim()) e.zip_code = 'Digital address / postcode is required.';
  else if (form.zip_code.length > 10) e.zip_code = 'Keep this under 10 characters.';
  if (!/^[A-Z]{2}$/.test(form.country)) e.country = 'Select a country.';
  return e;
}

export function propertyPayloadFromForm(form: PropertyForm): Partial<Property> {
  return {
    name: form.name.trim(),
    property_type: form.property_type as PropertyType,
    street_address: form.street_address.trim(),
    street_address_2: form.street_address_2.trim() || null,
    city: form.city.trim(),
    state: form.state,
    zip_code: form.zip_code.trim(),
    country: form.country,
    address_visibility: form.address_visibility,
    year_built: form.year_built ? Number(form.year_built) : null,
    description: form.description.trim() || null,
    parking: form.parking.trim() || null,
    pet_policy: form.pet_policy.trim() || null,
    smoking_policy: form.smoking_policy.trim() || null,
  };
}
