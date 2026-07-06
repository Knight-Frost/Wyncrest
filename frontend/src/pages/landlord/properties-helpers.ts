/**
 * Pure, component-free helpers for the landlord Properties list + detail.
 * Kept separate from properties-ui.tsx so React Fast Refresh stays happy
 * (a module should export either components or plain helpers, not both).
 */

/* ---- Money (whole-cedi, matching the mockup's "GH₵ 2,800" style) --------- */
const GHS_WHOLE = new Intl.NumberFormat('en-GH', { maximumFractionDigits: 0 });

/** Integer pesewas/cents → "GH₵ 2,800" (no decimals). */
export function moneyCents(cents: number | null | undefined): string {
  if (cents === null || cents === undefined || !Number.isFinite(cents)) return 'GH₵ 0';
  return 'GH₵ ' + GHS_WHOLE.format(Math.round(cents / 100));
}

/** Decimal cedi string ("2800.00") → "GH₵ 2,800". */
export function moneyDecimal(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'string' ? parseFloat(value) : value;
  return Number.isFinite(n) ? 'GH₵ ' + GHS_WHOLE.format(Math.round(n)) : '—';
}

/* ---- Cover gradients (deterministic per property, like the mockup) ------- */
export const COVER_GRADIENTS = [
  'linear-gradient(135deg,#23596B,#163C47)',
  'linear-gradient(135deg,#2C7A57,#163C47)',
  'linear-gradient(135deg,#5B6B72,#3C4450)',
  'linear-gradient(135deg,#4B3E86,#23596B)',
  'linear-gradient(135deg,#9A6A1E,#5B4a20)',
];

/** Stable gradient index from a numeric id, so a property keeps its colour. */
export function gradientFor(id: number): string {
  return COVER_GRADIENTS[Math.abs(id) % COVER_GRADIENTS.length];
}

/* ---- Status → badge colour maps ----------------------------------------- */
export type BadgeTone = 'green' | 'amber' | 'red' | 'gray' | 'blue';

export function unitAvailabilityTone(status: string): BadgeTone {
  switch (status) {
    case 'occupied': return 'green';
    case 'available': return 'amber';
    case 'pending': return 'blue';
    case 'maintenance': return 'red';
    default: return 'gray';
  }
}

export function listingStatusTone(status: string): BadgeTone {
  switch (status) {
    case 'active': return 'green';
    case 'pending_review': return 'amber';
    case 'rejected': return 'red';
    case 'draft': return 'blue';
    case 'inactive':
    case 'archived': return 'gray';
    default: return 'gray';
  }
}

export function contractStatusTone(status: string): BadgeTone {
  switch (status) {
    case 'active': return 'green';
    case 'pending_tenant': return 'amber';
    case 'draft': return 'gray';
    case 'terminated': return 'red';
    case 'expired': return 'gray';
    default: return 'gray';
  }
}

export function maintenancePriorityTone(priority: string): BadgeTone {
  switch (priority) {
    case 'urgent':
    case 'high': return 'red';
    case 'medium': return 'amber';
    default: return 'gray';
  }
}

export function maintenanceStatusTone(status: string): BadgeTone {
  switch (status) {
    case 'resolved':
    case 'closed': return 'green';
    case 'in_progress':
    case 'acknowledged': return 'blue';
    case 'cancelled': return 'gray';
    default: return 'amber';
  }
}

export function ledgerStatusTone(status: string): BadgeTone {
  switch (status) {
    case 'paid': return 'green';
    case 'overdue': return 'red';
    case 'waived': return 'gray';
    default: return 'amber';
  }
}

/** Property status → the mockup's statuspill class + label. */
export function propertyStatus(isActive: boolean): { cls: string; label: string } {
  return isActive ? { cls: 'active', label: 'Active' } : { cls: 'archived', label: 'Inactive' };
}
