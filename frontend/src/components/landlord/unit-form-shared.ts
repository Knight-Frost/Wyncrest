import type { Unit, UnitAvailabilityStatus } from '@/lib/types';

export const AVAILABILITY_STATUSES: UnitAvailabilityStatus[] = [
  'available',
  'occupied',
  'pending',
  'maintenance',
  'unlisted',
];

export interface UnitForm {
  unit_number: string;
  internal_name: string;
  bedrooms: string;
  bathrooms: string;
  square_feet: string;
  rent_amount: string;
  security_deposit: string;
  availability_status: UnitAvailabilityStatus;
  available_from: string;
  amenities: string;
}

export function emptyUnitForm(): UnitForm {
  return {
    unit_number: '',
    internal_name: '',
    bedrooms: '1',
    bathrooms: '1',
    square_feet: '',
    rent_amount: '',
    security_deposit: '',
    availability_status: 'available',
    available_from: '',
    amenities: '',
  };
}

export function unitFormFrom(u: Unit): UnitForm {
  return {
    unit_number: u.unit_number,
    internal_name: u.internal_name ?? '',
    bedrooms: u.bedrooms,
    bathrooms: u.bathrooms,
    square_feet: u.square_feet != null ? String(u.square_feet) : '',
    rent_amount: u.rent_amount,
    security_deposit: u.security_deposit ?? '',
    availability_status: u.availability_status,
    available_from: u.available_from ?? '',
    amenities: (u.amenities ?? []).join(', '),
  };
}

/** Validation mirrors StoreUnitRequest — no invented rules. */
export function validateUnitForm(form: UnitForm): Record<string, string> {
  const e: Record<string, string> = {};
  if (!form.unit_number.trim()) e.unit_number = 'Unit number is required.';
  if (form.bedrooms.trim() === '' || Number(form.bedrooms) < 0) e.bedrooms = 'Enter a valid bedroom count.';
  if (form.bathrooms.trim() === '' || Number(form.bathrooms) < 0) e.bathrooms = 'Enter a valid bathroom count.';
  if (form.rent_amount.trim() === '' || Number(form.rent_amount) < 0) e.rent_amount = 'Enter a valid monthly rent.';
  if (
    (form.availability_status === 'available' || form.availability_status === 'pending') &&
    !form.available_from
  ) {
    e.available_from = 'Set an availability date for a vacant/coming-soon unit.';
  }
  return e;
}

export function unitPayloadFromForm(form: UnitForm): Partial<Unit> {
  const amenities = form.amenities
    .split(',')
    .map((a) => a.trim())
    .filter(Boolean);
  return {
    unit_number: form.unit_number,
    internal_name: form.internal_name || null,
    bedrooms: form.bedrooms,
    bathrooms: form.bathrooms,
    square_feet: form.square_feet ? Number(form.square_feet) : null,
    rent_amount: form.rent_amount,
    security_deposit: form.security_deposit || null,
    availability_status: form.availability_status,
    available_from: form.available_from || null,
    amenities: amenities.length ? amenities : null,
  };
}
