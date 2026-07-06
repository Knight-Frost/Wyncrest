/**
 * Shared intake ("repair report") option lists + human labels for the tenant
 * New Maintenance Request form and the request detail views. Every value here
 * mirrors an App\Enums\Maintenance* case 1:1 — no invented options.
 */
import type {
  MaintenanceAccess,
  MaintenanceArea,
  MaintenanceCategory,
  MaintenanceContactMethod,
  MaintenanceOnset,
  MaintenancePriority,
  MaintenanceSafetyFlag,
  MaintenanceVisitWindow,
} from '@/lib/types';

export const CATEGORY_OPTIONS: { value: MaintenanceCategory; label: string }[] = [
  { value: 'plumbing', label: 'Plumbing' },
  { value: 'electrical', label: 'Electrical' },
  { value: 'appliance', label: 'Appliance' },
  { value: 'hvac', label: 'Heating / Cooling' },
  { value: 'pest', label: 'Pest' },
  { value: 'locks', label: 'Doors / Locks' },
  { value: 'windows', label: 'Windows' },
  { value: 'flooring', label: 'Flooring' },
  { value: 'water_damage', label: 'Water damage' },
  { value: 'security', label: 'Security' },
  { value: 'structural', label: 'Structural' },
  { value: 'shared_area', label: 'Shared area' },
  { value: 'general', label: 'Other' },
];

/** Priority is shown newest→calmest with a plain-language explanation each. */
export const PRIORITY_OPTIONS: { value: MaintenancePriority; label: string; hint: string }[] = [
  { value: 'urgent', label: 'Emergency', hint: 'Immediate safety risk or serious property damage.' },
  { value: 'high', label: 'High', hint: 'A serious issue that needs quick attention.' },
  { value: 'medium', label: 'Medium', hint: 'Needs repair but is not dangerous.' },
  { value: 'low', label: 'Low', hint: 'Minor or cosmetic issue.' },
];

export const AREA_OPTIONS: { value: MaintenanceArea; label: string }[] = [
  { value: 'kitchen', label: 'Kitchen' },
  { value: 'bathroom', label: 'Bathroom' },
  { value: 'bedroom', label: 'Bedroom' },
  { value: 'living_room', label: 'Living room' },
  { value: 'balcony', label: 'Balcony' },
  { value: 'exterior', label: 'Exterior' },
  { value: 'shared_area', label: 'Shared area' },
  { value: 'garage', label: 'Garage / Parking' },
  { value: 'hallway', label: 'Hallway' },
  { value: 'other', label: 'Other' },
];

export const ONSET_OPTIONS: { value: MaintenanceOnset; label: string }[] = [
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'this_week', label: 'This week' },
  { value: 'over_a_week', label: 'More than a week ago' },
  { value: 'not_sure', label: 'Not sure' },
];

export const SAFETY_OPTIONS: { value: MaintenanceSafetyFlag; label: string; severe: boolean }[] = [
  { value: 'water_leak', label: 'Active water leak', severe: true },
  { value: 'no_power', label: 'No electricity', severe: true },
  { value: 'security', label: 'Broken lock or security issue', severe: true },
  { value: 'injury_risk', label: 'Injury or safety risk', severe: true },
  { value: 'mold', label: 'Mold or strong smell', severe: false },
  { value: 'pest', label: 'Pest problem', severe: false },
  { value: 'property_damage', label: 'Property damage', severe: false },
];

export const ACCESS_OPTIONS: { value: MaintenanceAccess; label: string }[] = [
  { value: 'yes', label: 'Yes, they can enter' },
  { value: 'no', label: 'No, I must be present' },
  { value: 'contact_first', label: 'Contact me first' },
];

export const VISIT_OPTIONS: { value: MaintenanceVisitWindow; label: string }[] = [
  { value: 'morning', label: 'Morning' },
  { value: 'afternoon', label: 'Afternoon' },
  { value: 'evening', label: 'Evening' },
  { value: 'weekend', label: 'Weekend' },
  { value: 'any', label: 'Any time' },
];

export const CONTACT_OPTIONS: { value: MaintenanceContactMethod; label: string }[] = [
  { value: 'message', label: 'Wyncrest message' },
  { value: 'phone', label: 'Phone' },
  { value: 'email', label: 'Email' },
];

/* Label lookups (value → human string) built from the option arrays above. */
const toMap = <T extends string>(opts: { value: T; label: string }[]): Record<T, string> =>
  opts.reduce((m, o) => ({ ...m, [o.value]: o.label }), {} as Record<T, string>);

export const areaLabel = toMap(AREA_OPTIONS);
export const onsetLabel = toMap(ONSET_OPTIONS);
export const accessLabel = toMap(ACCESS_OPTIONS);
export const visitLabel = toMap(VISIT_OPTIONS);
export const contactLabel = toMap(CONTACT_OPTIONS);
export const safetyLabel = toMap(SAFETY_OPTIONS);

/* Photo upload rules — mirror config/media.php (allowed_mimes + max_size_kb). */
export const PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp';
export const PHOTO_MAX_FILES = 10;
export const PHOTO_MAX_BYTES = 8 * 1024 * 1024; // 8 MB, matches MEDIA_MAX_SIZE_KB default
export const PHOTO_ACCEPT_LABEL = 'JPG, PNG or WEBP · up to 8 MB each · up to 10 photos';
