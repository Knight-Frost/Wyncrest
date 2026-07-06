/**
 * Shared SVG icons + tiny render helpers for the landlord Maintenance list +
 * detail, ported from wyncrest-landlord-maintenance.html. Component-only
 * module (pure helpers live in maintenance-helpers.ts) so React Fast Refresh
 * stays happy.
 */
import type { ReactNode } from 'react';

type IconProps = { className?: string };

export const IconSearch = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" /></svg>
);
export const IconBack = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m15 18-6-6 6-6" /></svg>
);
export const IconChevRight = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m9 6 6 6-6 6" /></svg>
);
export const IconChevDown = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m6 9 6 6 6-6" /></svg>
);
export const IconPlus = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 5v14M5 12h14" /></svg>
);
export const IconCheck = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M20 6 9 17l-5-5" /></svg>
);
export const IconX = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M18 6 6 18M6 6l12 12" /></svg>
);
export const IconExport = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M17 8l-5-5-5 5M12 3v12" /></svg>
);
export const IconDownload = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><path d="M7 10l5 5 5-5M12 15V3" /></svg>
);
export const IconMsg = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>
);
export const IconUser = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
);
export const IconPhone = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.5 2.8.6a2 2 0 0 1 1.7 2Z" /></svg>
);
export const IconMail = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-8.9 5.5a2 2 0 0 1-2.1 0L2 7" /></svg>
);
export const IconHome = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m3 10 9-7 9 7" /><path d="M5 9v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9" /></svg>
);
export const IconBuilding = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01" /></svg>
);
export const IconCal = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
);
export const IconClock = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
);
export const IconInfo = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></svg>
);
export const IconWarn = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /><path d="M12 9v4M12 17h.01" /></svg>
);
export const IconShield = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" /><path d="m9 12 2 2 4-4" /></svg>
);
export const IconCamera = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z" /><circle cx="12" cy="13" r="3.5" /></svg>
);
export const IconImage = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" /></svg>
);
export const IconDoc = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" /><path d="M14 3v6h6" /></svg>
);
export const IconReceipt = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1z" /><path d="M8 7h8M8 11h8M8 15h5" /></svg>
);
export const IconCash = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /></svg>
);
export const IconWrench = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M14.7 6.3a4 4 0 0 0-5.4 5.3l-6 6a1.4 1.4 0 0 0 2 2l6-6a4 4 0 0 0 5.3-5.4l-2.4 2.4-2-2z" /></svg>
);
export const IconActivity = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>
);
export const IconFlag = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z" /><path d="M4 22v-7" /></svg>
);
export const IconDroplet = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 22a7 7 0 0 0 7-7c0-3-3-7-7-11C8 8 5 12 5 15a7 7 0 0 0 7 7Z" /></svg>
);
export const IconZap = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M13 2 3 14h9l-1 8 10-12h-9z" /></svg>
);
export const IconWind = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12.8 19.6A2 2 0 1 0 14 16H2M17.5 8a2.5 2.5 0 1 1 2 4H2M9.8 4.4A2 2 0 1 1 11 8H2" /></svg>
);
export const IconBug = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M8 2l1.5 1.5M16 2l-1.5 1.5" /><path d="M9 7.5h6a3 3 0 0 1 3 3V14a6 6 0 0 1-12 0v-3.5a3 3 0 0 1 3-3Z" /><path d="M3 13h3M18 13h3M3 8h2M19 8h2M3 18h3M18 18h3M12 7.5V21" /></svg>
);
export const IconBox = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M21 8 12 3 3 8v8l9 5 9-5z" /><path d="m3 8 9 5 9-5M12 13v8" /></svg>
);
export const IconLock = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></svg>
);
export const IconHammer = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m15 12-8.4 8.4a2 2 0 0 1-2.8-2.8L12 9" /><path d="M18 15 22 11 15 4l-4 4z" /><path d="m9 7 3 3" /></svg>
);
export const IconHandshake = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m11 17 2 2a1 1 0 0 0 1.4 0l3.6-3.6a2 2 0 0 0 0-2.8l-4-4" /><path d="m6 9 3.5-3.5a2 2 0 0 1 2.8 0L14 7" /><path d="m14 7-4 4M8 11l3 3" /></svg>
);
export const IconPlay = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m5 3 14 9-14 9z" /></svg>
);
export const IconPause = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="6" y="4" width="4" height="16" /><rect x="14" y="4" width="4" height="16" /></svg>
);
export const IconArchive = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="3" y="3" width="18" height="4" rx="1" /><path d="M5 7v13a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7M10 12h4" /></svg>
);
export const IconRenew = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M3 12a9 9 0 0 1 15-6.7L21 8" /><path d="M21 3v5h-5" /><path d="M21 12a9 9 0 0 1-15 6.7L3 16" /><path d="M3 21v-5h5" /></svg>
);
export const IconEye = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /></svg>
);

/** Small helper to render a key/value row from the mockup's `.kv-row`. */
export function KVRow({ k, v }: { k: string; v: ReactNode }): ReactNode {
  return (
    <div className="kv-row">
      <span className="k">{k}</span>
      <span className="v">{v === null || v === undefined || v === '' ? '—' : v}</span>
    </div>
  );
}
