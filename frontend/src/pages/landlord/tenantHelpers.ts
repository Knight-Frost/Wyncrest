/**
 * Pure derivation helpers for the landlord Tenant Management roster + tenant
 * file. Everything here derives from REAL Contract/LedgerEntry/MaintenanceRequest
 * data — no fabricated scores, and no partial-payment concept (project memory:
 * partial payments are not backend-supported, never faked). Kept in its own
 * module (not a component file) so React Fast Refresh stays happy.
 */
import type { CSSProperties } from 'react';
import type { Contract, LedgerEntry, MaintenanceRequest, PaymentMethod } from '@/lib/types';
import { daysUntil, formatCents, formatDate } from '@/lib/format';

/* ── Ledger grouping ────────────────────────────────────────────────────── */

export function groupLedgerByContract(entries: LedgerEntry[]): Map<string, LedgerEntry[]> {
  const map = new Map<string, LedgerEntry[]>();
  for (const entry of entries) {
    const list = map.get(entry.contract_id);
    if (list) list.push(entry);
    else map.set(entry.contract_id, [entry]);
  }
  return map;
}

/* ── Location (property/unit/area) ─────────────────────────────────────── */

export interface ContractLocation {
  property: string;
  city: string | null;
  unit: string | null;
  listingTitle: string | null;
}

export function contractLocation(contract: Contract): ContractLocation {
  const unit = contract.listing?.unit;
  const property = unit?.property;
  return {
    property: property?.name ?? 'Property unavailable',
    city: property?.city ?? null,
    unit: unit?.unit_number ?? null,
    listingTitle: contract.listing?.title ?? null,
  };
}

/* ── Rent standing (no "partial" — that isn't a real backend concept) ──── */

export type RentStatus = 'paid' | 'due_soon' | 'overdue';

export interface NextPayment {
  entry: LedgerEntry;
  amountCents: number;
  dueDate: string | null;
  overdue: boolean;
}

export interface RentPosture {
  status: RentStatus;
  nextPayment: NextPayment | null;
  outstandingCents: number;
  /** All open (pending/overdue) rent/late-fee entries, earliest due first. */
  openEntries: LedgerEntry[];
}

/** Every open (pending/overdue) rent or late-fee entry for a contract. */
export function derivePaymentPosture(entries: LedgerEntry[]): RentPosture {
  const open = entries.filter(
    (e) => (e.type === 'rent' || e.type === 'late_fee') && (e.status === 'pending' || e.status === 'overdue'),
  );
  const outstandingCents = open.reduce((sum, e) => sum + e.display_amount_cents, 0);
  const isOverdue = open.some((e) => e.status === 'overdue');

  const sorted = [...open].sort((a, b) => {
    const at = a.due_date ? new Date(a.due_date).getTime() : Number.MAX_SAFE_INTEGER;
    const bt = b.due_date ? new Date(b.due_date).getTime() : Number.MAX_SAFE_INTEGER;
    return at - bt;
  });

  if (sorted.length === 0) {
    return { status: 'paid', nextPayment: null, outstandingCents: 0, openEntries: [] };
  }

  const target = sorted.find((e) => e.status === 'overdue') ?? sorted[0];
  const nextPayment: NextPayment = {
    entry: target,
    amountCents: target.display_amount_cents,
    dueDate: target.due_date,
    overdue: target.status === 'overdue',
  };

  return {
    status: isOverdue ? 'overdue' : 'due_soon',
    nextPayment,
    outstandingCents,
    openEntries: sorted,
  };
}

export const RENT_LABEL: Record<RentStatus, string> = {
  paid: 'Paid up',
  due_soon: 'Due soon',
  overdue: 'Overdue',
};

export const RENT_BADGE: Record<RentStatus, string> = {
  paid: 'b-green',
  due_soon: 'b-blue',
  overdue: 'b-red',
};

/* ── Lease / renewal standing ───────────────────────────────────────────── */

export type RenewalStatus = 'active' | 'up_for_renewal' | 'holdover' | 'renewed';

/** Same 60-day window the rest of the app uses for "ending soon". */
const RENEWAL_WINDOW_DAYS = 60;

/**
 * Only meaningful for an ACTIVE contract. A recent `contract_renewals` row
 * wins outright (simplest correct read — see project plan); otherwise it's
 * derived from how many days remain until `end_date`.
 */
export function deriveRenewalStatus(contract: Contract): RenewalStatus {
  if ((contract.renewals?.length ?? 0) > 0) return 'renewed';
  const days = daysUntil(contract.end_date);
  if (days === null) return 'active';
  if (days < 0) return 'holdover';
  if (days <= RENEWAL_WINDOW_DAYS) return 'up_for_renewal';
  return 'active';
}

export const RENEWAL_LABEL: Record<RenewalStatus, string> = {
  active: 'In term',
  up_for_renewal: 'Renewal due',
  holdover: 'Holdover',
  renewed: 'Renewed',
};

export const RENEWAL_BADGE: Record<RenewalStatus, string> = {
  active: 'b-gray',
  up_for_renewal: 'b-amber',
  holdover: 'b-red',
  renewed: 'b-green',
};

/* ── On-time payment rate ────────────────────────────────────────────────── */

export interface OnTimeRecord {
  onTime: number;
  total: number;
  /** 0-100; defaults to 100 when there's no settled-payment history yet. */
  rate: number;
}

/**
 * Walks PAYMENT entries' related_rent_entry_id back to the RENT/LATE_FEE
 * entry they settled, and compares the payment's occurred_at to that entry's
 * due_date. On-time when paid on or before the due date.
 */
export function deriveOnTimeRecord(entries: LedgerEntry[]): OnTimeRecord {
  const byId = new Map(entries.map((e) => [e.id, e]));
  const payments = entries.filter((e) => e.type === 'payment');

  let onTime = 0;
  let total = 0;
  for (const payment of payments) {
    if (!payment.related_rent_entry_id) continue;
    const original = byId.get(payment.related_rent_entry_id);
    if (!original || !original.due_date) continue;
    total += 1;
    const paidAt = new Date(payment.occurred_at ?? payment.created_at).getTime();
    const dueAt = new Date(original.due_date).getTime();
    if (paidAt <= dueAt) onTime += 1;
  }

  return { onTime, total, rate: total > 0 ? Math.round((onTime / total) * 100) : 100 };
}

export function healthClass(rate: number): 'ok' | 'mid' | 'low' {
  if (rate >= 90) return 'ok';
  if (rate >= 75) return 'mid';
  return 'low';
}

/* ── Maintenance ─────────────────────────────────────────────────────────── */

const OPEN_MAINTENANCE_STATUSES = new Set(['open', 'acknowledged', 'in_progress']);

export function openMaintenanceCount(requests: MaintenanceRequest[], contractId: string): number {
  return requests.filter((m) => m.contract_id === contractId && OPEN_MAINTENANCE_STATUSES.has(m.status)).length;
}

/* ── Per-contract roster row + KPI aggregation ──────────────────────────── */

export interface TenantRosterRow {
  contract: Contract;
  location: ContractLocation;
  rent: RentPosture;
  renewalStatus: RenewalStatus;
  onTime: OnTimeRecord;
  openMaintenance: number;
}

export function buildRosterRow(
  contract: Contract,
  ledgerByContract: Map<string, LedgerEntry[]>,
  maintenance: MaintenanceRequest[],
): TenantRosterRow {
  const entries = ledgerByContract.get(contract.id) ?? [];
  return {
    contract,
    location: contractLocation(contract),
    rent: derivePaymentPosture(entries),
    renewalStatus: deriveRenewalStatus(contract),
    onTime: deriveOnTimeRecord(entries),
    openMaintenance: openMaintenanceCount(maintenance, contract.id),
  };
}

export interface RosterKpis {
  activeCount: number;
  avgOnTimeRate: number;
  outstandingCents: number;
  outstandingTenantCount: number;
  /** up_for_renewal ∪ holdover — leases that need a decision. */
  endingSoonCount: number;
  openMaintenanceTotal: number;
}

export function computeRosterKpis(rows: TenantRosterRow[]): RosterKpis {
  const activeCount = rows.length;
  const avgOnTimeRate =
    activeCount > 0 ? Math.round(rows.reduce((sum, r) => sum + r.onTime.rate, 0) / activeCount) : 100;
  const outstandingCents = rows.reduce((sum, r) => sum + r.rent.outstandingCents, 0);
  const outstandingTenantCount = rows.filter((r) => r.rent.outstandingCents > 0).length;
  const endingSoonCount = rows.filter(
    (r) => r.renewalStatus === 'up_for_renewal' || r.renewalStatus === 'holdover',
  ).length;
  const openMaintenanceTotal = rows.reduce((sum, r) => sum + r.openMaintenance, 0);
  return {
    activeCount,
    avgOnTimeRate,
    outstandingCents,
    outstandingTenantCount,
    endingSoonCount,
    openMaintenanceTotal,
  };
}

/* ── Payment method presentation ────────────────────────────────────────── */

export const PAYMENT_METHOD_LABEL: Record<PaymentMethod, string> = {
  mobile_money_mtn: 'Mobile money · MTN',
  mobile_money_vodafone: 'Mobile money · Vodafone',
  bank_transfer: 'Bank transfer',
  cash: 'Cash',
};

export const PAYMENT_METHOD_OPTIONS: { value: PaymentMethod; label: string }[] = [
  { value: 'mobile_money_mtn', label: 'Mobile money · MTN' },
  { value: 'mobile_money_vodafone', label: 'Mobile money · Vodafone' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'cash', label: 'Cash' },
];

/** How a ledger entry's payment was made — real, never invented. */
export function ledgerMethodLabel(entry: LedgerEntry): string {
  if (entry.payment_method) return PAYMENT_METHOD_LABEL[entry.payment_method];
  if (entry.stripe_payment_intent_id) return 'Online payment (Stripe)';
  if (entry.type === 'payment') return 'Payment';
  return 'Auto-generated';
}

/* ── Avatar (deterministic tint by name, mirrors the mockup's tint()/avStyle())
   Intentionally literal, not design tokens: these are a fixed per-identity
   decorative palette (like a Gravatar color set) that provides its OWN
   background for the avatar chip, with the CSS's fixed white initials/gloss
   (tenant-management.css `.tav`) always legible on top — the swatch doesn't
   sit against the app canvas, so it doesn't need to re-theme with light/dark
   mode the way page surfaces and text do. */

const AVATAR_TINTS = ['#23596B', '#3E6E5C', '#7C4A54', '#4B5E86', '#6E5A3C', '#2C7A57', '#5B4B76'];

export function avatarTint(name: string): string {
  let sum = 0;
  for (const ch of name) sum = (sum + ch.charCodeAt(0)) % AVATAR_TINTS.length;
  return AVATAR_TINTS[sum];
}

export function avatarStyle(name: string): CSSProperties {
  const tint = avatarTint(name);
  return { background: `linear-gradient(150deg, ${tint}, ${tint}cc)` };
}

export function initials(name: string): string {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join('')
    .toUpperCase();
}

/* ── Relative day labels ─────────────────────────────────────────────────── */

export function relativeDays(days: number | null): string {
  if (days === null) return '—';
  if (days === 0) return 'today';
  if (days === 1) return 'tomorrow';
  if (days === -1) return 'yesterday';
  return days > 0 ? `in ${days} days` : `${Math.abs(days)} days ago`;
}

/* ── Tenancy timeline (composed client-side, no new audit endpoint) ────── */

export interface TenancyTimelineEvent {
  title: string;
  detail: string;
  /** ISO timestamp, used only for sort order. */
  at: string;
  dateLabel: string;
  tone: 'done' | 'warn' | '';
}

/**
 * Builds the tenant-file Overview timeline purely from data already on hand:
 * contract fields, ContractRenewal history rows, and ledger PAYMENT entries.
 * No fabricated events, no new backend endpoint.
 */
export function buildTenancyTimeline(contract: Contract, entries: LedgerEntry[]): TenancyTimelineEvent[] {
  const events: TenancyTimelineEvent[] = [];

  events.push({
    title: 'Lease signed',
    detail: `${formatDate(contract.start_date)} start · ${formatCents(contract.rent_amount)} / month`,
    at: contract.created_at,
    dateLabel: formatDate(contract.created_at),
    tone: 'done',
  });

  for (const renewal of contract.renewals ?? []) {
    const rentChanged = renewal.new_rent_amount !== renewal.previous_rent_amount;
    events.push({
      title: 'Lease renewed',
      detail: `New end date ${formatDate(renewal.new_end_date)}${
        rentChanged ? `, rent now ${formatCents(renewal.new_rent_amount)} / month` : ''
      }`,
      at: renewal.created_at,
      dateLabel: formatDate(renewal.created_at),
      tone: 'done',
    });
  }

  for (const entry of entries) {
    if (entry.type !== 'payment') continue;
    events.push({
      title: 'Payment recorded',
      detail: `${formatCents(entry.display_amount_cents)} via ${ledgerMethodLabel(entry)}`,
      at: entry.occurred_at,
      dateLabel: formatDate(entry.occurred_at),
      tone: 'done',
    });
  }

  const renewalStatus = deriveRenewalStatus(contract);
  if (
    contract.status === 'active' &&
    contract.end_date !== null &&
    (renewalStatus === 'up_for_renewal' || renewalStatus === 'holdover')
  ) {
    events.push({
      title: renewalStatus === 'holdover' ? 'Lease reached holdover' : 'Renewal window open',
      detail:
        renewalStatus === 'holdover'
          ? `Term ended ${formatDate(contract.end_date)}. Renew the term or move the tenant out.`
          : `Current term ends ${formatDate(contract.end_date)}. Decision due soon.`,
      at: contract.end_date,
      dateLabel: relativeDays(daysUntil(contract.end_date)),
      tone: 'warn',
    });
  }

  const posture = derivePaymentPosture(entries);
  if (posture.status === 'overdue' && posture.nextPayment) {
    events.push({
      title: 'Rent overdue',
      detail: `${formatCents(posture.outstandingCents)} outstanding`,
      at: posture.nextPayment.dueDate ?? contract.created_at,
      dateLabel: posture.nextPayment.dueDate ? relativeDays(daysUntil(posture.nextPayment.dueDate)) : '—',
      tone: 'warn',
    });
  }

  if (contract.status === 'terminated') {
    events.push({
      title: 'Contract terminated',
      detail: contract.termination_reason ? `Reason: ${contract.termination_reason}` : 'Tenancy ended',
      at: contract.end_date ?? contract.created_at,
      dateLabel: formatDate(contract.end_date),
      tone: '',
    });
  } else if (contract.status === 'expired') {
    events.push({
      title: 'Lease expired',
      detail: 'Term ended without a renewal on record',
      at: contract.end_date ?? contract.created_at,
      dateLabel: formatDate(contract.end_date),
      tone: '',
    });
  }

  return events.sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime());
}
