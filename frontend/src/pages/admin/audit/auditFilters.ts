/**
 * Audit filter types + date-range helpers.
 *
 * Kept in a non-component module so it can be imported by both AuditFilterBar
 * (the UI) and AuditLogs (the page's initial state) without tripping
 * react-refresh's "only export components" rule.
 */
export type DatePreset = 'today' | '7d' | '30d' | '90d' | 'all' | 'custom';

export interface AuditFilters {
  severity: '' | 'info' | 'warning' | 'critical';
  area: string;
  actor_role: '' | 'admin' | 'landlord' | 'tenant' | 'user' | 'system';
  date_preset: DatePreset;
  from_date: string;
  to_date: string;
  search: string;
  sort: 'newest' | 'oldest';
}

const pad = (n: number) => String(n).padStart(2, '0');
const ymd = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/** Concrete {from_date,to_date} for a preset. 'all'/'custom' clear the range. */
export function rangeForPreset(p: DatePreset): { from_date: string; to_date: string } {
  if (p === 'all' || p === 'custom') return { from_date: '', to_date: '' };
  const now = new Date();
  const from = new Date(now);
  if (p === '7d') from.setDate(now.getDate() - 6);
  else if (p === '30d') from.setDate(now.getDate() - 29);
  else if (p === '90d') from.setDate(now.getDate() - 89);
  return { from_date: ymd(from), to_date: ymd(now) };
}
