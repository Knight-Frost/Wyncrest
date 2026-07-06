/**
 * Pure, component-free helpers for the landlord Listings list + detail.
 * Kept separate from listing-ui.tsx so React Fast Refresh stays happy.
 */
import type { BadgeTone } from './properties-helpers';

export function applicationBadgeTone(status: string): BadgeTone {
  switch (status) {
    case 'approved': return 'green';
    case 'rejected': return 'red';
    case 'withdrawn': return 'gray';
    case 'needs_action': return 'amber';
    case 'in_review':
    case 'landlord_review': return 'blue';
    default: return 'amber'; // submitted / draft
  }
}

/** Human status → { label, statuspanel class } used for the mockup's coloured hero banner. */
export function statusPanelClass(status: string): string {
  switch (status) {
    case 'active': return 'active';
    case 'pending_review': return 'pending';
    case 'rejected': return 'rejected';
    default: return 'draft'; // draft / inactive / archived
  }
}

/** Whole days a listing has been live, or null if never published. */
export function daysOnMarket(publishedAt: string | null): number | null {
  if (!publishedAt) return null;
  const ms = Date.now() - new Date(publishedAt).getTime();
  return Math.max(0, Math.floor(ms / 86_400_000));
}
