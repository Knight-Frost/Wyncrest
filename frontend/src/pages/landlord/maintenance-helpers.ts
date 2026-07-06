/**
 * Pure derivations for the landlord Maintenance list + detail — category/
 * priority/status metadata (icon + CSS class), sorting/filtering, and cost
 * totals. Kept separate from maintenance-ui.tsx so Fast Refresh stays happy.
 */
import type { ReactNode } from 'react';
import type {
  MaintenanceAssigneeType,
  MaintenanceCategory,
  MaintenancePriority,
  MaintenanceRequest,
  MaintenanceStatus,
} from '@/lib/types';
import { maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel } from '@/lib/statusMaps';
import {
  IconDroplet, IconZap, IconBox, IconWind, IconHammer, IconBug, IconLock, IconWrench, IconBuilding, IconImage,
} from './maintenance-ui';

export const CATEGORY_ICON: Record<MaintenanceCategory, (p: { className?: string }) => ReactNode> = {
  plumbing: IconDroplet,
  electrical: IconZap,
  appliance: IconBox,
  hvac: IconWind,
  structural: IconHammer,
  pest: IconBug,
  security: IconLock,
  locks: IconLock,
  windows: IconImage,
  flooring: IconBox,
  water_damage: IconDroplet,
  shared_area: IconBuilding,
  general: IconWrench,
};

/* Tints are derived from CATEGORY_COLOR via color-mix() rather than a
   hand-picked rgba, so they stay correct in both themes automatically —
   no separate light/dark tint to keep in sync. */
export const CATEGORY_TINT: Record<MaintenanceCategory, string> = {
  plumbing: 'color-mix(in srgb, var(--wm-petrol-2) 12%, transparent)',
  electrical: 'color-mix(in srgb, var(--wm-amber) 14%, transparent)',
  appliance: 'color-mix(in srgb, var(--wm-purple) 12%, transparent)',
  hvac: 'color-mix(in srgb, var(--wm-petrol-2) 12%, transparent)',
  structural: 'color-mix(in srgb, var(--wm-slate) 14%, transparent)',
  pest: 'color-mix(in srgb, var(--wm-green) 12%, transparent)',
  security: 'color-mix(in srgb, var(--wm-oxblood) 11%, transparent)',
  locks: 'color-mix(in srgb, var(--wm-oxblood) 11%, transparent)',
  windows: 'color-mix(in srgb, var(--wm-petrol-2) 12%, transparent)',
  flooring: 'color-mix(in srgb, var(--wm-slate) 14%, transparent)',
  water_damage: 'color-mix(in srgb, var(--wm-petrol-2) 12%, transparent)',
  shared_area: 'color-mix(in srgb, var(--wm-slate) 12%, transparent)',
  general: 'color-mix(in srgb, var(--wm-slate) 12%, transparent)',
};

export const CATEGORY_COLOR: Record<MaintenanceCategory, string> = {
  plumbing: 'var(--wm-petrol-2)',
  electrical: 'var(--wm-amber)',
  appliance: 'var(--wm-purple)',
  hvac: 'var(--wm-petrol-2)',
  structural: 'var(--wm-slate)',
  pest: 'var(--wm-green)',
  security: 'var(--wm-oxblood)',
  locks: 'var(--wm-oxblood)',
  windows: 'var(--wm-petrol-2)',
  flooring: 'var(--wm-slate)',
  water_damage: 'var(--wm-petrol-2)',
  shared_area: 'var(--wm-slate)',
  general: 'var(--wm-slate)',
};

export const PRIORITY_RANK: Record<MaintenancePriority, number> = {
  urgent: 0,
  high: 1,
  medium: 2,
  low: 3,
};

export const PRIORITY_CLASS: Record<MaintenancePriority, string> = {
  urgent: 'pri-emergency',
  high: 'pri-high',
  medium: 'pri-medium',
  low: 'pri-low',
};

export const STATUS_BADGE: Record<MaintenanceStatus, string> = {
  open: 'b-blue',
  acknowledged: 'b-blue',
  assigned: 'b-purple',
  in_progress: 'b-amber',
  waiting: 'b-amber',
  resolved: 'b-green',
  closed: 'b-gray',
  cancelled: 'b-gray',
};

const OPEN_STATUSES: MaintenanceStatus[] = ['open', 'acknowledged', 'assigned', 'in_progress', 'waiting'];
const FINAL_STATUSES: MaintenanceStatus[] = ['resolved', 'closed', 'cancelled'];

export function isOpen(r: Pick<MaintenanceRequest, 'status'>): boolean {
  return OPEN_STATUSES.includes(r.status);
}
export function isFinal(r: Pick<MaintenanceRequest, 'status'>): boolean {
  return FINAL_STATUSES.includes(r.status);
}
export function isUrgent(r: Pick<MaintenanceRequest, 'status' | 'priority'>): boolean {
  return (r.priority === 'urgent' || r.priority === 'high') && isOpen(r);
}

export function assigneeTypeLabel(t: MaintenanceAssigneeType | null): string {
  if (t === 'vendor') return 'External vendor';
  if (t === 'staff') return 'Staff';
  return '—';
}

export function totalCost(r: Pick<MaintenanceRequest, 'labor_cost_cents' | 'parts_cost_cents'>): number {
  return (r.labor_cost_cents ?? 0) + (r.parts_cost_cents ?? 0);
}

export function locationLabel(r: MaintenanceRequest): string {
  return [
    r.unit?.unit_number ? `Unit ${r.unit.unit_number}` : null,
    r.property?.name ?? null,
  ].filter(Boolean).join(' · ') || '—';
}

/* Re-exported so pages import metadata from one place. */
export { maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel };
