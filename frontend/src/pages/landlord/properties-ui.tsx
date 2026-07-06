/**
 * Shared SVG icons + tiny render helpers for the landlord Properties list +
 * detail, ported from wyncrest-properties.html. Component-only module (pure
 * helpers live in properties-helpers.ts) so React Fast Refresh stays happy.
 */
import type { ReactNode } from 'react';

/* ---- Inline SVG icons (match the mockup's stroke set) -------------------- */
type IconProps = { className?: string };

export const IconBuilding = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M9 9h.01M15 9h.01M9 13h.01M15 13h.01" />
  </svg>
);
export const IconPlus = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 5v14M5 12h14" /></svg>
);
export const IconSearch = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
);
export const IconWarn = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.4 3.9a2 2 0 00-3.4 0z" />
  </svg>
);
export const IconCheck = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M20 6L9 17l-5-5" /></svg>
);
export const IconBack = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M15 18l-6-6 6-6" /></svg>
);
export const IconEdit = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M12 20h9M3 21l1-4 11-11 3 3L7 20z" /></svg>
);
export const IconImage = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}>
    <rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="M21 17l-5-5-8 8" />
  </svg>
);
export const IconList = (p: IconProps) => (
  <svg viewBox="0 0 24 24" className={p.className}><path d="M3 7h18M3 12h18M3 17h10" /></svg>
);

/** The stylised building glyph used inside missing/gradient covers. */
export const CoverGlyph = () => (
  <div className="cvicon">
    <svg viewBox="0 0 24 24">
      <path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M9 9h.01M15 9h.01M9 13h.01M15 13h.01" />
    </svg>
  </div>
);

/** Small helper to render a key/value row from the mockup's `.kv`. */
export function KV({ k, v }: { k: string; v: ReactNode }): ReactNode {
  return (
    <div className="kv">
      <span className="kk">{k}</span>
      <span className="vv">{v === null || v === undefined || v === '' ? '—' : v}</span>
    </div>
  );
}
