/**
 * Shared helpers for the landlord Applicants command-centre (list / detail /
 * compare). Everything here derives from REAL Application data — no fabricated
 * scores. Reuses the tenant guided-form's own notion of "complete" so the two
 * portals never disagree about what counts as a finished application.
 */
import type { Application, ApplicationStatus } from '@/lib/types';
import { SECTIONS, sectionDone, sectionsComplete } from '@/pages/tenant/applicationHelpers';

/* ── Affordability ─────────────────────────────────────────────────────────── */

export type AffordLevel = 'good' | 'mod' | 'tight';

export const AFFORD_LABEL: Record<AffordLevel, string> = {
  good: 'Comfortable',
  mod: 'Moderate',
  tight: 'Tight',
};

/** The guided form's income field is free text (e.g. "9,200" or "GH₵9200"). */
export function parseIncome(income?: string | null): number | null {
  if (!income) return null;
  const n = parseFloat(income.replace(/[^0-9.]/g, ''));
  return Number.isFinite(n) && n > 0 ? n : null;
}

function parseRent(rentAmount?: string | null): number | null {
  if (!rentAmount) return null;
  const n = parseFloat(rentAmount);
  return Number.isFinite(n) && n > 0 ? n : null;
}

/** Income ÷ rent — a common leasing guideline is 3× or more. Null when either
 * figure isn't known yet (e.g. the applicant hasn't reached that form step). */
export function affordability(app: Application): { ratio: number; level: AffordLevel } | null {
  const income = parseIncome(app.form_data?.employment?.income);
  const rent = parseRent(app.listing?.unit?.rent_amount);
  if (income === null || rent === null) return null;

  const ratio = Math.round((income / rent) * 10) / 10;
  const level: AffordLevel = ratio >= 3 ? 'good' : ratio >= 2 ? 'mod' : 'tight';
  return { ratio, level };
}

/* ── Completeness (reuses the tenant guided-form's own definition) ─────────── */

export function completenessPercent(app: Application): number {
  const done = sectionsComplete(app.form_data ?? null, app.documents);
  return Math.round((done / (SECTIONS.length - 1)) * 100);
}

const DOCUMENTS_STEP_INDEX = SECTIONS.length - 2; // "Documents" is the step before "Review & submit"

export function hasRequiredDocuments(app: Application): boolean {
  return sectionDone(app.form_data ?? null, app.documents, DOCUMENTS_STEP_INDEX);
}

export function isFullyVerified(app: Application): boolean {
  return Boolean(app.tenant?.identity_verified) && hasRequiredDocuments(app);
}

/* ── Household ─────────────────────────────────────────────────────────────── */

export function householdSummary(app: Application): string {
  const h = app.form_data?.household;
  if (!h?.adults) return '—';
  const children = h.children && h.children !== '0' ? `+${h.children}` : '';
  return `${h.adults}${children}`;
}

/* ── Tabs ──────────────────────────────────────────────────────────────────── */

export type ApplicantTab = 'all' | 'new' | 'review' | 'shortlisted' | 'approved' | 'declined';

export const APPLICANT_TABS: { key: ApplicantTab; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'new', label: 'New' },
  { key: 'review', label: 'Under review' },
  { key: 'shortlisted', label: 'Shortlisted' },
  { key: 'approved', label: 'Approved' },
  { key: 'declined', label: 'Not selected' },
];

const REVIEW_STATUSES: ApplicationStatus[] = ['in_review', 'needs_action'];
const DECLINED_STATUSES: ApplicationStatus[] = ['rejected', 'withdrawn'];

export function matchesApplicantTab(app: Application, tab: ApplicantTab): boolean {
  switch (tab) {
    case 'all':
      return app.status !== 'withdrawn';
    case 'new':
      return app.status === 'submitted';
    case 'review':
      return (REVIEW_STATUSES as string[]).includes(app.status);
    case 'shortlisted':
      return Boolean(app.is_shortlisted);
    case 'approved':
      return app.status === 'approved';
    case 'declined':
      return (DECLINED_STATUSES as string[]).includes(app.status);
    default:
      return true;
  }
}

/** Whether a landlord decision (approve/decline) can still be made. */
export function isDecidable(status: ApplicationStatus): boolean {
  return status === 'submitted' || status === 'in_review' || status === 'needs_action';
}

/** Matches ApplicationPolicy::requestInfo — active statuses only. */
export function canRequestInfo(status: ApplicationStatus): boolean {
  return status === 'submitted' || status === 'in_review' || status === 'needs_action';
}

/** Matches ApplicationPolicy::shortlist — any non-draft, non-final status. */
export function canShortlist(status: ApplicationStatus): boolean {
  return status !== 'draft' && status !== 'approved' && status !== 'rejected' && status !== 'withdrawn';
}
