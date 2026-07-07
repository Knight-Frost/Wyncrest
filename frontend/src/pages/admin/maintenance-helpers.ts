/**
 * Pure derivations for the admin Maintenance oversight list + detail —
 * category/priority/status metadata (icon + CSS class). Same shape as the
 * landlord equivalent (pages/landlord/maintenance-helpers.ts) but pointed at
 * this page's own --wam-* tokens (admin-maintenance.css, scoped `.wamnt`),
 * since custom properties don't cross a CSS scope boundary.
 */
import type { ReactNode } from 'react';
import type {
  AdminMaintenanceCase,
  MaintenanceCategory,
  MaintenancePriority,
  MaintenanceStatus,
} from '@/lib/types';
import { maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel } from '@/lib/statusMaps';
import {
  IconDroplet, IconZap, IconBox, IconWind, IconHammer, IconBug, IconLock, IconWrench, IconBuilding, IconImage,
} from '@/pages/landlord/maintenance-ui';

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

export const CATEGORY_TINT: Record<MaintenanceCategory, string> = {
  plumbing: 'color-mix(in srgb, var(--wam-petrol-2) 12%, transparent)',
  electrical: 'color-mix(in srgb, var(--wam-amber) 14%, transparent)',
  appliance: 'color-mix(in srgb, var(--wam-purple) 12%, transparent)',
  hvac: 'color-mix(in srgb, var(--wam-petrol-2) 12%, transparent)',
  structural: 'color-mix(in srgb, var(--wam-slate) 14%, transparent)',
  pest: 'color-mix(in srgb, var(--wam-green) 12%, transparent)',
  security: 'color-mix(in srgb, var(--wam-oxblood) 11%, transparent)',
  locks: 'color-mix(in srgb, var(--wam-oxblood) 11%, transparent)',
  windows: 'color-mix(in srgb, var(--wam-petrol-2) 12%, transparent)',
  flooring: 'color-mix(in srgb, var(--wam-slate) 14%, transparent)',
  water_damage: 'color-mix(in srgb, var(--wam-petrol-2) 12%, transparent)',
  shared_area: 'color-mix(in srgb, var(--wam-slate) 12%, transparent)',
  general: 'color-mix(in srgb, var(--wam-slate) 12%, transparent)',
};

export const CATEGORY_COLOR: Record<MaintenanceCategory, string> = {
  plumbing: 'var(--wam-petrol-2)',
  electrical: 'var(--wam-amber)',
  appliance: 'var(--wam-purple)',
  hvac: 'var(--wam-petrol-2)',
  structural: 'var(--wam-slate)',
  pest: 'var(--wam-green)',
  security: 'var(--wam-oxblood)',
  locks: 'var(--wam-oxblood)',
  windows: 'var(--wam-petrol-2)',
  flooring: 'var(--wam-slate)',
  water_damage: 'var(--wam-petrol-2)',
  shared_area: 'var(--wam-slate)',
  general: 'var(--wam-slate)',
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

export function isOpen(r: Pick<AdminMaintenanceCase, 'status'>): boolean {
  return OPEN_STATUSES.includes(r.status as MaintenanceStatus);
}

/* Re-exported so pages import metadata from one place. */
export { maintenanceCategoryLabel, maintenancePriorityLabel, maintenanceStatusLabel };
