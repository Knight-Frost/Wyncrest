/*
 * Inline stroke icons for the Identity Verification editorial pages. Kept
 * local (mirrors the approach in wlrIcons.tsx for Listing Review) so stroke
 * weights render consistently inside the `.wver` glass surfaces without
 * pulling in the general icon set.
 */
type P = { className?: string };

const S = ({ children, ...p }: React.SVGProps<SVGSVGElement>) => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.9} {...p}>
    {children}
  </svg>
);

export const WVIconSearch = (p: P) => (
  <S {...p}>
    <circle cx="11" cy="11" r="7" />
    <path d="M21 21l-4-4" />
  </S>
);
export const WVIconExport = (p: P) => (
  <S {...p}>
    <path d="M12 3v12M8 11l4 4 4-4M4 21h16" strokeLinecap="round" strokeLinejoin="round" />
  </S>
);
export const WVIconChevron = (p: P) => (
  <S {...p}>
    <path d="M9 6l6 6-6 6" />
  </S>
);
export const WVIconCheck = (p: P) => (
  <S {...p}>
    <path d="M20 6L9 17l-5-5" strokeLinecap="round" strokeLinejoin="round" />
  </S>
);
export const WVIconX = (p: P) => (
  <S {...p}>
    <path d="M6 6l12 12M18 6L6 18" strokeLinecap="round" />
  </S>
);
export const WVIconWarn = (p: P) => (
  <S {...p}>
    <path d="M12 8v5M12 16h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.4 3.9a2 2 0 00-3.4 0z" />
  </S>
);
export const WVIconInfo = (p: P) => (
  <S {...p}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 11v5M12 8h.01" />
  </S>
);
export const WVIconManual = (p: P) => (
  <S {...p}>
    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" />
    <circle cx="12" cy="12" r="2.5" />
  </S>
);
export const WVIconBack = (p: P) => (
  <S {...p} strokeWidth={2}>
    <path d="M15 18l-6-6 6-6" />
  </S>
);
export const WVIconZoomIn = (p: P) => (
  <S {...p}>
    <circle cx="11" cy="11" r="7" />
    <path d="M11 8v6M8 11h6M21 21l-4-4" />
  </S>
);
export const WVIconZoomOut = (p: P) => (
  <S {...p}>
    <circle cx="11" cy="11" r="7" />
    <path d="M8 11h6M21 21l-4-4" />
  </S>
);
export const WVIconRotate = (p: P) => (
  <S {...p}>
    <path d="M21 12a9 9 0 11-3-6.7L21 8M21 3v5h-5" />
  </S>
);
export const WVIconExternal = (p: P) => (
  <S {...p}>
    <path d="M14 3h7v7M21 3l-9 9M10 5H5v14h14v-5" strokeLinecap="round" strokeLinejoin="round" />
  </S>
);
export const WVIconDownload = (p: P) => (
  <S {...p}>
    <path d="M12 3v12M8 11l4 4 4-4M4 21h16" strokeLinecap="round" strokeLinejoin="round" />
  </S>
);
export const WVIconFile = (p: P) => (
  <S {...p}>
    <path d="M6 3h9l3 3v15H6z" />
    <path d="M9 12h6M9 16h4" />
  </S>
);
export const WVIconLock = (p: P) => (
  <S {...p}>
    <rect x="5" y="11" width="14" height="9" rx="2" />
    <path d="M8 11V8a4 4 0 018 0v3" />
  </S>
);
export const WVIconRefresh = (p: P) => (
  <S {...p}>
    <path d="M21 12a9 9 0 11-2.64-6.36M21 3v6h-6" strokeLinecap="round" strokeLinejoin="round" />
  </S>
);
