/*
 * Shared bits for the landlord Rent Ledger console (main page + case file +
 * statements). Icons, money/date formatters, per-type/-status display meta,
 * and the reusable Record-Payment modal all live here so the four ledger
 * pages stay consistent and DRY. Everything renders inside `.wled`
 * (ledger.css) — the White-Liquid-Glass port of the mockup.
 */
import { type CSSProperties, type ReactNode } from 'react';
import type { LedgerEntry, LedgerType } from '@/lib/types';
import { formatCents } from '@/lib/format';

/* ---------- money / dates ------------------------------------------------- */

/** Compact GH₵, no decimals — for big card numbers (matches the mockup). */
export function cedis0(cents: number): string {
  return 'GH₵ ' + Math.abs(Math.round(cents / 100)).toLocaleString('en-GH');
}

/** Signed, always-2dp — payments show a leading minus. */
export function signedCents(cents: number): string {
  return (cents < 0 ? '− ' : '') + formatCents(Math.abs(cents));
}

export function fmtD(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}
export function fmtDShort(iso: string | null | undefined): string {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}
export function fmtDT(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return (
    d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) +
    ' at ' +
    d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
  );
}

/* ---------- avatars ------------------------------------------------------- */

const AV = ['#23596B', '#3E6E5C', '#7C4A54', '#4B5E86', '#6E5A3C', '#2C7A57', '#5B4B76'];
export function initials(name: string): string {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join('')
    .toUpperCase();
}
function tint(name: string): string {
  let s = 0;
  for (const c of name) s = (s + c.charCodeAt(0)) % AV.length;
  return AV[s];
}
export function avStyle(name: string): CSSProperties {
  const c = tint(name);
  return { background: `linear-gradient(150deg,${c},${c}cc)` };
}

/* ---------- icons (inline, local — no external deps) ---------------------- */

const svg = (inner: ReactNode, extra?: Record<string, string>) => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...extra}>
    {inner}
  </svg>
);

export const I: Record<string, ReactNode> = {
  search: svg(<><circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" /></>),
  back: svg(<path d="m15 18-6-6 6-6" />),
  chev: svg(<path d="m9 6 6 6-6 6" />),
  down: svg(<path d="m6 9 6 6 6-6" />),
  arw: svg(<path d="M5 12h14M13 6l6 6-6 6" />),
  cash: svg(<><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /><path d="M6 12h.01M18 12h.01" /></>),
  doc: svg(<><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" /><path d="M14 3v6h6" /></>),
  warn: svg(<><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /><path d="M12 9v4M12 17h.01" /></>),
  shield: svg(<><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" /><path d="m9 12 2 2 4-4" /></>),
  info: svg(<><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></>),
  renew: svg(<><path d="M3 12a9 9 0 0 1 15-6.7L21 8" /><path d="M21 3v5h-5" /><path d="M21 12a9 9 0 0 1-15 6.7L3 16" /><path d="M3 21v-5h5" /></>),
  out: svg(<><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" /></>),
  download: svg(<><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M7 10l5 5 5-5M12 15V3" /></>),
  export: svg(<><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M17 8l-5-5-5 5M12 3v12" /></>),
  check: svg(<path d="M20 6 9 17l-5-5" />, { strokeWidth: '2.4' }),
  x: svg(<path d="M18 6 6 18M6 6l12 12" />, { strokeWidth: '2.2' }),
  doc2: svg(<><path d="M15.5 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.5z" /><path d="M15 3v6h6" /><path d="M9 13h6M9 17h4" /></>),
  clock: svg(<><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>),
  msg: svg(<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />),
  scale: svg(<><path d="M12 3v18M7 7h10M5 7l-3 7h6zM19 7l-3 7h6z" /><path d="M2 14a4 4 0 0 0 6 0M16 14a4 4 0 0 0 6 0" /></>),
  building: svg(<><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01" /></>),
};

/* ---------- entry / status meta ------------------------------------------ */

interface EntryMeta {
  label: string;
  badge: string;
  icon: ReactNode;
  tint: CSSProperties;
}
export const ENTRY: Record<LedgerType, EntryMeta> = {
  rent: { label: 'Rent charge', badge: 'b-gray', icon: I.doc, tint: { background: 'color-mix(in srgb, var(--slate) 12%, transparent)', color: 'var(--slate)' } },
  late_fee: { label: 'Late fee', badge: 'b-amber', icon: I.warn, tint: { background: 'color-mix(in srgb, var(--amber) 13%, transparent)', color: 'var(--amber)' } },
  payment: { label: 'Payment', badge: 'b-green', icon: I.cash, tint: { background: 'color-mix(in srgb, var(--green) 12%, transparent)', color: 'var(--green)' } },
  refund: { label: 'Refund', badge: 'b-purple', icon: I.out, tint: { background: 'color-mix(in srgb, var(--purple) 12%, transparent)', color: 'var(--purple)' } },
};

export const STATUS: Record<string, { badge: string; label: string }> = {
  // ledger-entry statuses
  pending: { badge: 'b-blue', label: 'Pending' },
  paid: { badge: 'b-green', label: 'Paid' },
  overdue: { badge: 'b-red', label: 'Overdue' },
  waived: { badge: 'b-gray', label: 'Waived' },
  // contract payment statuses (Balances tab)
  open: { badge: 'b-blue', label: 'Due soon' },
  no_history: { badge: 'b-gray', label: 'No activity' },
  current: { badge: 'b-green', label: 'Current' },
};

/** Contract payment status → the label/badge shown on a Balances row. */
export function contractStatusMeta(status: string): { badge: string; label: string } {
  if (status === 'paid') return { badge: 'b-green', label: 'Current' };
  if (status === 'overdue') return STATUS.overdue;
  if (status === 'open') return STATUS.open;
  return STATUS.no_history;
}

/** Amount tone class by movement direction. */
export function amtToneClass(entry: LedgerEntry): string {
  if (entry.direction === 'payment') return 'cr';
  if (entry.type === 'late_fee') return 'fee';
  return 'db';
}

/** Whether an entry can still have an offline payment recorded against it. */
export function isOpenObligation(entry: LedgerEntry): boolean {
  return (entry.type === 'rent' || entry.type === 'late_fee') && (entry.status === 'pending' || entry.status === 'overdue');
}

