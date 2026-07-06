/*
 * Inline stroke icons for the Listing Review editorial pages. Kept local (and
 * deliberately thin) so the mockup's precise stroke weights render consistently
 * inside the `.wlr` glass surfaces without pulling in the general icon set.
 */
type P = { className?: string };

const S = ({ children, ...p }: React.SVGProps<SVGSVGElement>) => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.9} {...p}>
    {children}
  </svg>
);

export const WIconSearch = (p: P) => (
  <S {...p}>
    <circle cx="11" cy="11" r="7" />
    <path d="M21 21l-4-4" />
  </S>
);
export const WIconExport = (p: P) => (
  <S {...p}>
    <path d="M12 3v12M8 11l4 4 4-4M4 21h16" strokeLinecap="round" strokeLinejoin="round" />
  </S>
);
export const WIconGuide = (p: P) => (
  <S {...p}>
    <path d="M12 3v18M6 8h12M6 16h12" strokeLinecap="round" />
  </S>
);
export const WIconRefresh = (p: P) => (
  <S {...p}>
    <path d="M21 12a9 9 0 11-2.64-6.36M21 3v6h-6" strokeLinecap="round" strokeLinejoin="round" />
  </S>
);
export const WIconChevron = (p: P) => (
  <S {...p}>
    <path d="M9 6l6 6-6 6" />
  </S>
);
export const WIconPhotos = (p: P) => (
  <S {...p} strokeWidth={2}>
    <rect x="3" y="5" width="18" height="14" rx="2" />
    <circle cx="8.5" cy="10" r="1.5" />
    <path d="M21 16l-5-5L5 19" />
  </S>
);
export const WIconBed = (p: P) => (
  <S {...p}>
    <path d="M3 10h18M6 10V6h12v4M4 18v-8M20 18v-8" />
  </S>
);
export const WIconBath = (p: P) => (
  <S {...p}>
    <path d="M4 12h16v4a3 3 0 01-3 3H7a3 3 0 01-3-3z" />
  </S>
);
export const WIconArea = (p: P) => (
  <S {...p}>
    <path d="M3 3h18v18H3z" />
  </S>
);
export const WIconPin = (p: P) => (
  <S {...p}>
    <path d="M12 21s7-6.4 7-11a7 7 0 10-14 0c0 4.6 7 11 7 11z" />
    <circle cx="12" cy="10" r="2.5" />
  </S>
);
export const WIconCheck = (p: P) => (
  <S {...p}>
    <path d="M20 6L9 17l-5-5" strokeLinecap="round" strokeLinejoin="round" />
  </S>
);
export const WIconX = (p: P) => (
  <S {...p}>
    <path d="M6 6l12 12M18 6L6 18" strokeLinecap="round" />
  </S>
);
export const WIconWarn = (p: P) => (
  <S {...p}>
    <path d="M12 8v5M12 16h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.4 3.9a2 2 0 00-3.4 0z" />
  </S>
);
export const WIconEye = (p: P) => (
  <S {...p}>
    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" />
    <circle cx="12" cy="12" r="3" />
  </S>
);
export const WIconBack = (p: P) => (
  <S {...p} strokeWidth={2}>
    <path d="M15 18l-6-6 6-6" />
  </S>
);
export const WIconInfo = (p: P) => (
  <S {...p}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 11v5M12 8h.01" />
  </S>
);
export const WIconDuplicate = (p: P) => (
  <S {...p}>
    <rect x="8" y="8" width="12" height="12" rx="2" />
    <path d="M4 16V6a2 2 0 012-2h10" />
  </S>
);
