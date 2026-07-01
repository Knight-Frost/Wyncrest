/**
 * auditVisuals — shared "colour = meaning" helpers for the Audit pages.
 *
 * Extracted so the timeline list (AuditTimeline) and the detail page
 * (AuditLogDetail) tint category nodes and actor avatars identically. Every
 * colour maps to the approved semantic ramp only (info/success/warning/danger/
 * neutral) — never an invented colour — and re-skins for free in dark mode.
 */
import {
  IconShield,
  IconImage,
  IconWallet,
  IconAlertTriangle,
  IconUser,
  IconHome,
  IconFileText,
  IconBuilding,
  IconInbox,
  IconWrench,
  IconSettings,
  IconMessage,
  IconActivity,
} from '@/components/ui/icons';
import type { SemanticRole } from '@/components/cards/variants';

/** Maps actor role strings to SemanticRoles (colour = authority/meaning). */
export const ROLE_SEMANTIC: Record<string, SemanticRole> = {
  admin: 'danger', // elevated power — oxblood conveys authority
  landlord: 'info', // primary business role — blue
  tenant: 'success', // most common actor — green
  user: 'neutral',
  system: 'warning', // system events warrant attention
};

/** Tailwind tint pairs for the round avatar / square icon chip. */
export const TINT: Record<SemanticRole, string> = {
  info: 'bg-brand-50 text-brand-600',
  success: 'bg-success-50 text-success-600',
  warning: 'bg-warning-50 text-warning-600',
  danger: 'bg-danger-50 text-danger-600',
  neutral: 'bg-ink-100 text-ink-500',
};

/** Icon component shape (icons.tsx doesn't export its prop type). */
type IconCmp = React.ComponentType<{ size?: number; className?: string }>;
export type ActionVisual = { Icon: IconCmp; tint: SemanticRole };

/**
 * Picks an icon + tint for an action by category. Keyword-matched against the
 * raw action key, with the derived area as a fallback so unknown actions still
 * render a sensible chip.
 */
export function actionVisual(action: string, area: string): ActionVisual {
  const a = action.toLowerCase();
  if (/login|logout|sign_?in|auth|rate_limit|password|access|token/.test(a)) return { Icon: IconShield, tint: 'warning' };
  if (/media|upload|photo|image|avatar|gallery/.test(a)) return { Icon: IconImage, tint: 'neutral' };
  if (/ledger|payment|paid|rent|late_fee|refund|invoice|charge/.test(a)) return { Icon: IconWallet, tint: 'success' };
  if (/delete|destroy|remove|suspend|block|archive|reject|terminate|revoke/.test(a)) return { Icon: IconAlertTriangle, tint: 'danger' };
  if (/user|account|register|verif|identity|profile/.test(a)) return { Icon: IconUser, tint: 'info' };
  if (/listing/.test(a)) return { Icon: IconHome, tint: 'info' };
  if (/contract|lease/.test(a)) return { Icon: IconFileText, tint: 'info' };
  if (/property|unit/.test(a)) return { Icon: IconBuilding, tint: 'info' };
  if (/application|applicant/.test(a)) return { Icon: IconInbox, tint: 'info' };
  if (/maintenance|repair/.test(a)) return { Icon: IconWrench, tint: 'warning' };
  if (/message|conversation/.test(a)) return { Icon: IconMessage, tint: 'info' };
  if (/feature|setting|policy|config/.test(a)) return { Icon: IconSettings, tint: 'neutral' };

  switch (area) {
    case 'Access':  return { Icon: IconShield, tint: 'warning' };
    case 'Ledger':  return { Icon: IconWallet, tint: 'success' };
    case 'Users':   return { Icon: IconUser, tint: 'info' };
    case 'System':  return { Icon: IconSettings, tint: 'neutral' };
    default:        return { Icon: IconActivity, tint: 'neutral' };
  }
}

/** Two-letter initials from a name, e.g. "Jack Frost" → "JF". */
export function initialsOf(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '—';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
