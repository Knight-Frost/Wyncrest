/**
 * Shared SVG icons for the landlord Tenant Management roster + tenant-file
 * pages, ported from wyncrest-landlord-tenants.html's `I = {...}` icon set.
 * Component-only module so React Fast Refresh stays happy (pure helpers
 * live in tenantHelpers.ts).
 */
type IconProps = { className?: string };

export const IconSearch = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="11" cy="11" r="7" /><path d="m21 21-4.3-4.3" /></svg>
);
export const IconBack = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m15 18-6-6 6-6" /></svg>
);
export const IconPhone = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.5 2.8.6a2 2 0 0 1 1.7 2Z" />
  </svg>
);
export const IconMail = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-8.9 5.5a2 2 0 0 1-2.1 0L2 7" /></svg>
);
export const IconMsg = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>
);
export const IconCash = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /><path d="M6 12h.01M18 12h.01" /></svg>
);
export const IconBell = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" /><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0" /></svg>
);
export const IconRenew = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M3 12a9 9 0 0 1 15-6.7L21 8" /><path d="M21 3v5h-5" /><path d="M21 12a9 9 0 0 1-15 6.7L3 16" /><path d="M3 21v-5h5" />
  </svg>
);
export const IconOut = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><path d="m16 17 5-5-5-5" /><path d="M21 12H9" /></svg>
);
export const IconNote = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M15.5 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.5z" /><path d="M15 3v6h6" /></svg>
);
export const IconCheck = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M20 6 9 17l-5-5" /></svg>
);
export const IconHome = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="m3 10 9-7 9 7" /><path d="M5 9v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9" /></svg>
);
export const IconWrench = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M14.7 6.3a4 4 0 0 0-5.4 5.3l-6 6a1.4 1.4 0 0 0 2 2l6-6a4 4 0 0 0 5.3-5.4l-2.4 2.4-2-2z" /></svg>
);
export const IconDoc = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" /><path d="M14 3v6h6" /></svg>
);
export const IconCal = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
);
export const IconShield = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" /><path d="m9 12 2 2 4-4" /></svg>
);
export const IconInfo = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></svg>
);
export const IconWarn = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /><path d="M12 9v4M12 17h.01" />
  </svg>
);
export const IconPlus = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 5v14M5 12h14" /></svg>
);
export const IconUsers = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" />
  </svg>
);
