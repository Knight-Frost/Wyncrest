import type { Role } from '@/lib/types';
import { adminHasCapability, isSuperAdmin } from '@/lib/permissions';
import {
  IconActivity,
  IconBarChart,
  IconBell,
  IconBuilding,
  IconCircleCheck,
  IconCompare,
  IconDashboard,
  IconDoc,
  IconFolder,
  IconGrid,
  IconHeart,
  IconHome,
  IconKey,
  IconLedger,
  IconMessage,
  IconScale,
  IconSearch,
  IconSettings,
  IconShield,
  IconStar,
  IconUser,
  IconUsers,
  IconWallet,
  IconWrench,
} from '@/components/ui/icons';

const ICON = { size: 18 as const };

export interface NavItem {
  to: string;
  label: string;
  icon: React.ReactNode;
  end?: boolean;
  badge?: number;
  /**
   * Admin capability required to see this item. Super admins (who implicitly
   * hold every capability) always see it; a regular admin sees it only if a
   * super admin has granted the capability. This is UI reflection only — the
   * API independently enforces the same rule.
   */
  requiresCapability?: string;
  /**
   * Hides this item from super admins even though they'd otherwise pass every
   * capability check. For pages designed for a SCOPED admin's own limited
   * workload (e.g. "My Analytics") where a super admin has a dedicated,
   * full-platform equivalent instead (e.g. "Platform Analytics").
   */
  hideForSuperAdmin?: boolean;
}

/** Minimal shape of the signed-in user needed to gate capability nav items. */
export interface NavGateUser {
  role: Role;
  is_super_admin?: boolean;
  capabilities?: string[];
}

/**
 * Can the given user see a capability-gated nav item? Ungated items are always
 * visible; gated items defer to the shared RBAC rule (super admin bypasses,
 * scoped admin needs the exact capability).
 */
function canSeeNavItem(item: NavItem, user?: NavGateUser): boolean {
  if (item.hideForSuperAdmin && isSuperAdmin(user)) return false;
  if (!item.requiresCapability) return true;
  return adminHasCapability(user, item.requiresCapability);
}

export interface NavGroup {
  title: string;
  items: NavItem[];
}

/* ---- Grouped nav definitions ------------------------------------------- */

const TENANT_GROUPS: NavGroup[] = [
  {
    title: 'Find a Home',
    items: [
      { to: '/app', label: 'Dashboard', icon: <IconDashboard {...ICON} />, end: true },
      { to: '/app/browse', label: 'Browse Homes', icon: <IconSearch {...ICON} /> },
      { to: '/app/saved', label: 'Saved Homes', icon: <IconHeart {...ICON} /> },
      { to: '/app/compare', label: 'Compare', icon: <IconCompare {...ICON} /> },
    ],
  },
  {
    title: 'My Rental',
    items: [
      { to: '/app/applications', label: 'Applications', icon: <IconDoc {...ICON} /> },
      { to: '/app/contracts', label: 'Lease & Rent', icon: <IconScale {...ICON} /> },
      { to: '/app/payments', label: 'Payments', icon: <IconWallet {...ICON} /> },
      { to: '/app/maintenance', label: 'Maintenance', icon: <IconWrench {...ICON} /> },
    ],
  },
  {
    title: 'Communicate',
    items: [
      { to: '/app/messages', label: 'Messages', icon: <IconMessage {...ICON} /> },
      { to: '/app/documents', label: 'Documents', icon: <IconFolder {...ICON} /> },
    ],
  },
  {
    title: 'Account',
    items: [
      { to: '/app/verification', label: 'Verification', icon: <IconCircleCheck {...ICON} /> },
      { to: '/app/reviews', label: 'My Reviews', icon: <IconStar {...ICON} /> },
      { to: '/app/notifications', label: 'Notifications', icon: <IconBell {...ICON} /> },
      { to: '/app/profile', label: 'Profile', icon: <IconUser {...ICON} /> },
      { to: '/app/settings', label: 'Settings', icon: <IconSettings {...ICON} /> },
    ],
  },
];

const LANDLORD_GROUPS: NavGroup[] = [
  {
    title: 'Overview',
    items: [
      { to: '/app', label: 'Dashboard', icon: <IconDashboard {...ICON} />, end: true },
      { to: '/app/properties', label: 'Properties', icon: <IconBuilding {...ICON} /> },
      { to: '/app/listings', label: 'Listings', icon: <IconHome {...ICON} /> },
    ],
  },
  {
    title: 'Operations',
    items: [
      { to: '/app/applicants', label: 'Applicants', icon: <IconUsers {...ICON} /> },
      { to: '/app/tenants', label: 'Tenants', icon: <IconGrid {...ICON} /> },
      { to: '/app/ledger', label: 'Rent', icon: <IconLedger {...ICON} /> },
      { to: '/app/maintenance', label: 'Maintenance', icon: <IconWrench {...ICON} /> },
    ],
  },
  {
    title: 'Analytics',
    items: [
      { to: '/app/analytics', label: 'Analytics', icon: <IconBarChart {...ICON} /> },
    ],
  },
  {
    title: 'Account',
    items: [
      { to: '/app/landlord-verification', label: 'Verification', icon: <IconCircleCheck {...ICON} /> },
      { to: '/app/landlord-reviews', label: 'Reviews', icon: <IconStar {...ICON} /> },
      { to: '/app/notifications', label: 'Notifications', icon: <IconBell {...ICON} /> },
      { to: '/app/profile', label: 'Profile', icon: <IconUser {...ICON} /> },
      { to: '/app/settings', label: 'Settings', icon: <IconSettings {...ICON} /> },
    ],
  },
];

const ADMIN_GROUPS: NavGroup[] = [
  {
    title: 'Platform',
    items: [
      { to: '/app', label: 'Overview', icon: <IconDashboard {...ICON} />, end: true },
      { to: '/app/verifications', label: 'Verifications', icon: <IconCircleCheck {...ICON} />, requiresCapability: 'review_verifications' },
      { to: '/app/listing-review', label: 'Listing Review', icon: <IconShield {...ICON} />, requiresCapability: 'moderate_listings' },
      // Viewing the user roster is a baseline admin privilege; only the
      // moderation actions inside the page require manage_users.
      { to: '/app/users', label: 'Users', icon: <IconUsers {...ICON} /> },
      { to: '/app/manage-access', label: 'Manage Users & Permissions', icon: <IconKey {...ICON} />, requiresCapability: 'manage_access' },
      { to: '/app/review-moderation', label: 'Reviews', icon: <IconStar {...ICON} />, requiresCapability: 'moderate_reviews' },
    ],
  },
  {
    title: 'Oversight',
    items: [
      // Same rule as Users: viewing is universal, only mutating actions
      // (terminate/notes, late fees) require manage_contracts/manage_ledger.
      { to: '/app/contracts', label: 'Contracts', icon: <IconScale {...ICON} /> },
      { to: '/app/ledger', label: 'Ledger', icon: <IconLedger {...ICON} /> },
      // Same rule as Contracts/Ledger: viewing is universal, only mutating
      // actions (assign/escalate/notes/override/export) require manage_maintenance.
      { to: '/app/maintenance', label: 'Maintenance', icon: <IconWrench {...ICON} /> },
      { to: '/app/audit', label: 'Audit Logs', icon: <IconActivity {...ICON} />, requiresCapability: 'view_audit' },
      // Reachable by any SCOPED admin — the "your own workload" analytics
      // page. Distinct from Platform Analytics below (full platform, requires
      // view_analytics); this page shows only the modules this admin holds
      // capabilities for. Hidden from super admins: Platform Analytics is
      // their equivalent, so showing both would be redundant/confusing.
      { to: '/app/admin-analytics', label: 'My Analytics', icon: <IconBarChart {...ICON} />, hideForSuperAdmin: true },
      { to: '/app/platform-analytics', label: 'Platform Analytics', icon: <IconBarChart {...ICON} />, requiresCapability: 'view_analytics' },
    ],
  },
  {
    title: 'Account',
    items: [
      { to: '/app/notifications', label: 'Notifications', icon: <IconBell {...ICON} /> },
      { to: '/app/settings', label: 'Settings', icon: <IconSettings {...ICON} /> },
    ],
  },
];

const NAV_GROUPS: Record<Role, NavGroup[]> = {
  tenant:   TENANT_GROUPS,
  landlord: LANDLORD_GROUPS,
  admin:    ADMIN_GROUPS,
};

/* ---- Exports ------------------------------------------------------------- */

/**
 * Grouped navigation for the sidebar. Pass the signed-in user to hide
 * capability-gated items (e.g. Manage Users & Permissions) the user can't reach.
 * Empty groups are dropped so no orphan section headers render.
 */
export function navForRole(role: Role, user?: NavGateUser): NavGroup[] {
  const groups = NAV_GROUPS[role] ?? [];
  if (!user) return groups;
  return groups
    .map((g) => ({ ...g, items: g.items.filter((it) => canSeeNavItem(it, user)) }))
    .filter((g) => g.items.length > 0);
}

/** Flat list of all nav items for a role (capability-filtered when user given). */
export function navItemsForRole(role: Role, user?: NavGateUser): NavItem[] {
  return navForRole(role, user).flatMap((g) => g.items);
}

/** Up to 5 items for the mobile bottom nav bar. */
export function mobileNavItems(role: Role, user?: NavGateUser): NavItem[] {
  const all = navItemsForRole(role, user);
  // Always include the dashboard first, then key items, finish with notifications
  const notif = all.find((i) => i.to === '/app/notifications');
  const rest = all.filter((i) => i.to !== '/app/notifications').slice(0, 4);
  const items = notif ? [...rest, notif] : rest;
  return items.slice(0, 5);
}

export const roleLabel: Record<Role, string> = {
  tenant:   'Tenant',
  landlord: 'Landlord',
  admin:    'Administrator',
};
