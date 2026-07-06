/**
 * Additional inline SVG icons for the landlord Listings list + detail, ported
 * from wyncrest-listings-landlord.html. Icons already covered by
 * properties-ui.tsx (IconPlus/IconEdit/IconImage/IconWarn/IconCheck/IconBack/
 * IconList/IconSearch) are reused from there, not duplicated here.
 */
type IconProps = { className?: string };

export const IconEye = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" /><circle cx="12" cy="12" r="3" />
  </svg>
);
export const IconUsers = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <circle cx="9" cy="8" r="3" /><path d="M3 20c0-3 3-5 6-5s6 2 6 5" />
  </svg>
);
export const IconDots = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <circle cx="12" cy="5" r="1.5" /><circle cx="12" cy="12" r="1.5" /><circle cx="12" cy="19" r="1.5" />
  </svg>
);
export const IconTrash = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
);
export const IconLink = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M10 13a5 5 0 007 0l3-3a5 5 0 00-7-7l-1 1" /><path d="M14 11a5 5 0 00-7 0l-3 3a5 5 0 007 7l1-1" />
  </svg>
);
export const IconBox = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M3 7l9-4 9 4v10l-9 4-9-4z" /></svg>
);
export const IconUp = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 19V5M5 12l7-7 7 7" /></svg>
);
export const IconX = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M6 6l12 12M18 6L6 18" /></svg>
);
export const IconClock = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="12" cy="12" r="9" /><path d="M12 8v4l3 2" /></svg>
);
export const IconDownload = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 3v12M8 11l4 4 4-4M4 21h16" /></svg>
);
export const IconAlert = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.4 3.9a2 2 0 00-3.4 0z" />
  </svg>
);
