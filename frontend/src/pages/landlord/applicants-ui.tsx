/**
 * Shared SVG icons for the landlord Applicants list + detail + compare pages,
 * ported from wyncrest-applications-landlord.html. Component-only module so
 * React Fast Refresh stays happy (pure helpers live in applicantHelpers.ts).
 */
type IconProps = { className?: string };

export const IconCheck = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M20 6L9 17l-5-5" /></svg>
);
export const IconStar = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M12 3l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 18.8 6.1 21l1.2-6.5L2.5 9.9 9.1 9z" />
  </svg>
);
export const IconMessage = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
  </svg>
);
export const IconSearch = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
);
export const IconShield = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M12 3l7 4v5c0 5-3.5 8-7 9-3.5-1-7-4-7-9V7z" /><path d="M9 12l2 2 4-4" />
  </svg>
);
export const IconClock = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="12" cy="12" r="9" /><path d="M12 8v4l3 2" /></svg>
);
export const IconWarn = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.4 3.9a2 2 0 00-3.4 0z" />
  </svg>
);
export const IconLock = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 018 0v3" /></svg>
);
export const IconBack = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M15 18l-6-6 6-6" /></svg>
);
export const IconCompare = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M9 3v18M3 9h6M15 3v18M15 9h6" /></svg>
);
export const IconDollar = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6" /></svg>
);
export const IconChecklist = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M9 11l3 3 8-8" /><path d="M20 12v6a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h9" /></svg>
);
export const IconFile = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z" /><path d="M13 3v6h6" />
  </svg>
);
export const IconPerson = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="9" cy="8" r="3" /><path d="M3 20c0-3 3-5 6-5s6 2 6 5" /></svg>
);
export const IconAlertCircle = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16h.01" /></svg>
);
