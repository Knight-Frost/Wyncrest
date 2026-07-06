/**
 * Formatting helpers. Centralized so money/date rendering is consistent and the
 * two backend money schemes are never confused at the call site.
 *
 * Currency: Homecrest is a Ghana platform — all money is displayed in Ghana Cedis (GH&#8373;).
 * The backend stores integer "cents" (pesewas) for Contract/LedgerEntry amounts
 * and decimal strings for Unit.rent_amount.
 */
import type { ContractStatus, LedgerStatus, ListingStatus } from './types';

const GHS_FMT = new Intl.NumberFormat('en-GH', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

const GHS_NO_DEC = new Intl.NumberFormat('en-GH', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
});

/** Render integer pesewas/cents as GH&#8373; (Contract.rent_amount, LedgerEntry.amount_cents). */
export function formatCents(cents: number): string {
  return 'GH₵ ' + GHS_FMT.format(cents / 100);
}

/**
 * Compact GH&#8373; for tight spaces (chart axis ticks): "GH₵ 17k" instead of
 * "GH₵ 17,000.00". Never used for a headline figure, only axis/tick labels.
 */
export function formatCentsCompact(cents: number): string {
  const cedis = cents / 100;
  if (Math.abs(cedis) < 1000) return 'GH₵ ' + GHS_NO_DEC.format(cedis);
  const thousands = cedis / 1000;
  const rounded = Number.isInteger(thousands) ? thousands.toFixed(0) : thousands.toFixed(1);
  return `GH₵ ${rounded}k`;
}

/** Render a decimal cedi string from the API (Unit.rent_amount = "3500.00"). */
export function formatDollars(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'string' ? parseFloat(value) : value;
  return Number.isFinite(n) ? 'GH₵ ' + GHS_FMT.format(n) : '—';
}

/**
 * Render a decimal cedi string without fractional digits, useful for listing
 * cards where "GH&#8373; 3,500" is cleaner than "GH&#8373; 3,500.00".
 */
export function formatCedisDecimal(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'string' ? parseFloat(value) : value;
  return Number.isFinite(n) ? 'GH₵ ' + GHS_NO_DEC.format(n) : '—';
}

/**
 * Resolve a stored file path to a public URL (listing photos live under
 * /storage). Returns null when there is no real file, so callers fall back to a
 * neutral placeholder rather than a broken image.
 */
export function storageUrl(path: string | null | undefined): string | null {
  if (!path) return null;
  return `${import.meta.env.VITE_API_URL ?? ''}/storage/${path}`;
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString('en-GH', { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * Compact relative time, e.g. "just now", "3 hours ago", "2 days ago".
 * Always derived from a real timestamp — used for "Updated X ago" labels.
 */
export function timeAgo(iso: string | null | undefined): string {
  if (!iso) return '—';
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '—';
  const secs = Math.round((Date.now() - then) / 1000);
  if (secs < 45) return 'just now';
  const mins = Math.round(secs / 60);
  if (mins < 60) return `${mins} ${mins === 1 ? 'minute' : 'minutes'} ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs} ${hrs === 1 ? 'hour' : 'hours'} ago`;
  const days = Math.round(hrs / 24);
  if (days < 30) return `${days} ${days === 1 ? 'day' : 'days'} ago`;
  const months = Math.round(days / 30);
  if (months < 12) return `${months} ${months === 1 ? 'month' : 'months'} ago`;
  const years = Math.round(months / 12);
  return `${years} ${years === 1 ? 'year' : 'years'} ago`;
}

/**
 * Days until a future date (negative if past). Used for "in 4 days" / "Overdue
 * 12 days" lease + payment posture, computed from real due dates.
 */
export function daysUntil(iso: string | null | undefined): number | null {
  if (!iso) return null;
  const target = new Date(iso).getTime();
  if (Number.isNaN(target)) return null;
  const startOfDay = (t: number) => {
    const d = new Date(t);
    d.setHours(0, 0, 0, 0);
    return d.getTime();
  };
  return Math.round((startOfDay(target) - startOfDay(Date.now())) / 86_400_000);
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString('en-GH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
}

/** "pending_review" -> "Pending review" */
export function humanize(value: string | null | undefined): string {
  if (!value) return '—';
  const s = value.replace(/_/g, ' ');
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/* ---- Status -> semantic tone (drives Badge color) ------------------------- */
export type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';

export function listingStatusTone(status: ListingStatus): Tone {
  switch (status) {
    case 'active':         return 'success';
    case 'pending_review': return 'warning';
    case 'rejected':       return 'danger';
    case 'draft':          return 'neutral';
    default:               return 'neutral';
  }
}

export function contractStatusTone(status: ContractStatus): Tone {
  switch (status) {
    case 'active':         return 'success';
    case 'pending_tenant': return 'warning';
    case 'terminated':     return 'danger';
    case 'expired':        return 'neutral';
    case 'draft':          return 'neutral';
    default:               return 'neutral';
  }
}

export function ledgerStatusTone(status: LedgerStatus): Tone {
  switch (status) {
    case 'paid':    return 'success';
    case 'pending': return 'info';
    case 'overdue': return 'danger';
    case 'waived':  return 'neutral';
    default:        return 'neutral';
  }
}
