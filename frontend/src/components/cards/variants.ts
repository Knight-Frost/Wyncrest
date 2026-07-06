/**
 * Homecrest semantic card system — variant vocabulary + data-driven mapping.
 *
 * Color in Homecrest is a ROLE, never decoration. Pages must not hand-pick card
 * colors; they call a mapping function with REAL backend data and get back a
 * `SemanticRole`. That keeps the UI truthful (a card is only "danger" when the
 * data is actually in a danger state) and consistent across every portal.
 *
 * Role → palette (see editorial.css):
 *   success → estate green   (healthy / cleared / collected / on-time)
 *   info    → ink teal       (support / confidence / primary financial)
 *   warning → clay/terracotta (review / attention / pending / due-soon)
 *   danger  → oxblood        (critical / overdue / destructive)
 *   neutral → cool slate ink (ordinary information)
 */
import type {
  ApplicationStatus,
  ContractStatus,
  LedgerStatus,
  ListingStatus,
  MaintenanceRequest,
} from '@/lib/types';

/** The semantic roles a card/badge/icon can take. */
export type SemanticRole =
  | 'neutral'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info';

/** Card altitude — Level 1 (quiet), Level 2 (tinted status), Level 3 (command). */
export type CardLevel = 'neutral' | 'soft' | 'command';

/* ── Tailwind class tables (single source of truth for role → utilities) ──────
   Soft + neutral cards read off the remapped semantic ramps, so they re-skin
   for free in light/dark. Command fills use the bespoke --nexus-cmd-* tokens. */

export const softSurface: Record<SemanticRole, string> = {
  neutral: 'bg-surface border-ink-200',
  success: 'bg-success-50 border-success-50',
  warning: 'bg-warning-50 border-warning-50',
  danger:  'bg-danger-50 border-danger-50',
  info:    'bg-info-50 border-info-50',
};

export const iconTileClass: Record<SemanticRole, string> = {
  // neutral carries the brand (ink-teal) accent — a calm identity, not a status
  // claim. Status is signalled by the card SURFACE (below), never the icon.
  neutral: 'bg-brand-50 text-brand-700',
  success: 'bg-success-50 text-success-600',
  warning: 'bg-warning-50 text-warning-600',
  danger:  'bg-danger-50 text-danger-600',
  info:    'bg-info-50 text-info-600',
};

/** Strong (role-tinted) value text for tinted status cards. */
export const valueToneClass: Record<SemanticRole, string> = {
  neutral: 'text-ink-950',
  success: 'text-success-600',
  warning: 'text-warning-600',
  danger:  'text-danger-600',
  info:    'text-info-600',
};

/** Deep command-card fill (gradient via --nexus-cmd-* tokens) per role. */
export const commandFill: Record<SemanticRole, { from: string; to: string }> = {
  neutral: { from: 'var(--nexus-cmd-neutral)', to: 'var(--nexus-cmd-neutral-2)' },
  success: { from: 'var(--nexus-cmd-success)', to: 'var(--nexus-cmd-success-2)' },
  warning: { from: 'var(--nexus-cmd-review)',  to: 'var(--nexus-cmd-review-2)' },
  danger:  { from: 'var(--nexus-cmd-danger)',  to: 'var(--nexus-cmd-danger-2)' },
  info:    { from: 'var(--nexus-cmd-info)',     to: 'var(--nexus-cmd-info-2)' },
};

/* ── Data-driven mapping functions ───────────────────────────────────────────
   Each takes REAL data and returns the role the UI must show. */

/** Tenant outstanding balance. Cleared → success; owing → warning; overdue → danger. */
export function getPaymentBalanceVariant(
  balanceCents: number,
  hasOverdue: boolean,
): SemanticRole {
  if (balanceCents <= 0) return 'success';
  return hasOverdue ? 'danger' : 'warning';
}

/** Payment health. On-time → success; overdue → danger; no ledger data → neutral. */
export function getPaymentHealthVariant(
  hasOverdue: boolean,
  hasData: boolean,
): SemanticRole {
  if (!hasData) return 'neutral';
  return hasOverdue ? 'danger' : 'success';
}

/** Next-payment-due. Overdue → danger; due within `soonDays` → warning; else neutral. */
export function getNextDueVariant(
  daysUntilDue: number | null,
  soonDays = 7,
): SemanticRole {
  if (daysUntilDue === null) return 'neutral';
  if (daysUntilDue < 0) return 'danger';
  if (daysUntilDue <= soonDays) return 'warning';
  return 'neutral';
}

/** Admin "critical today". Any critical event → danger; otherwise healthy/neutral. */
export function getAdminCriticalVariant(count: number): SemanticRole {
  return count > 0 ? 'danger' : 'success';
}

/**
 * Failed sign-ins. Soft rose by default; escalates to full danger past a
 * critical threshold. Threshold is explicit + documented (no magic numbers
 * scattered in JSX). // why: 10 failed sign-ins/day is the platform alert line.
 */
export const FAILED_SIGNIN_CRITICAL_THRESHOLD = 10;
export function getFailedSigninsVariant(count: number): SemanticRole {
  if (count <= 0) return 'success';
  return count >= FAILED_SIGNIN_CRITICAL_THRESHOLD ? 'danger' : 'warning';
}

/** Policy changes — audit/policy activity reads as review (clay), never danger. */
export function getPolicyChangesVariant(count: number): SemanticRole {
  return count > 0 ? 'warning' : 'neutral';
}

/** Healthy user activity is success/teal — never a danger color. */
export function getUserActivityVariant(count: number): SemanticRole {
  return count > 0 ? 'success' : 'neutral';
}

/** Review queue. Items present → review (clay); empty → neutral. */
export function getReviewQueueVariant(count: number): SemanticRole {
  return count > 0 ? 'warning' : 'neutral';
}

/**
 * Ledger health from the overdue share of outstanding balance.
 * <5% healthy, <20% watch, else critical. Mirrors backend posture.
 */
export function getLedgerHealthVariant(
  outstandingCents: number,
  overdueCents: number,
): SemanticRole {
  if (outstandingCents <= 0) return 'success';
  const ratio = overdueCents / outstandingCents;
  if (ratio < 0.05) return 'success';
  if (ratio < 0.2) return 'warning';
  return 'danger';
}

/** Occupancy rate (0–100). ≥90 good, ≥70 watch, else vacancy concern. */
export function getOccupancyVariant(ratePct: number): SemanticRole {
  if (ratePct >= 90) return 'success';
  if (ratePct >= 70) return 'warning';
  return 'danger';
}

/** Collected-rent posture: outstanding fully covered → success, else watch. */
export function getCollectedVariant(
  collectedCents: number,
  overdueCents: number,
): SemanticRole {
  if (overdueCents > 0) return 'danger';
  return collectedCents > 0 ? 'success' : 'neutral';
}

export function getMaintenanceVariant(
  priority: MaintenanceRequest['priority'],
  status: MaintenanceRequest['status'],
): SemanticRole {
  if (status === 'resolved' || status === 'closed') return 'success';
  if (priority === 'urgent') return 'danger';
  if (priority === 'high') return 'warning';
  return 'info';
}

export function getListingModerationVariant(status: ListingStatus): SemanticRole {
  switch (status) {
    case 'active':         return 'success';
    case 'pending_review': return 'warning';
    case 'rejected':       return 'danger';
    default:               return 'neutral';
  }
}

export function getApplicationVariant(status: ApplicationStatus): SemanticRole {
  switch (status) {
    case 'approved':         return 'success';
    case 'rejected':         return 'danger';
    case 'needs_action':     return 'warning';
    case 'withdrawn':        return 'neutral';
    case 'draft':            return 'neutral';
    default:                 return 'info'; // submitted / in_review / landlord_review
  }
}

export function getContractVariant(status: ContractStatus): SemanticRole {
  switch (status) {
    case 'active':         return 'success';
    case 'pending_tenant': return 'warning';
    case 'terminated':     return 'danger';
    default:               return 'neutral';
  }
}

export function getLedgerVariant(status: LedgerStatus): SemanticRole {
  switch (status) {
    case 'paid':    return 'success';
    case 'pending': return 'info';
    case 'overdue': return 'danger';
    default:        return 'neutral';
  }
}
