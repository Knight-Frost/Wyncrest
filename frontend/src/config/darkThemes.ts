/**
 * Dark-theme (palette) registry for Wyncrest.
 *
 * A DARK THEME controls the dark-mode ATMOSPHERE — background, surfaces, cards,
 * borders and muted text. It is applied via the `data-dark-theme` attribute on
 * <html> and only takes visual effect while the resolved appearance is dark
 * (the CSS blocks are gated on `[data-theme='dark']`). Token values live in two
 * places: shared `--color-*` surfaces in index.css (root-level, so they reach
 * body + portals + the app skin by inheritance) and the sidebar `--nvx-*`
 * surfaces in editorial.css. This file is the metadata the picker + pre-paint use.
 *
 * It is SEPARATE from:
 *   - appearance mode (light/dark/system) — context/theme.ts
 *   - accent colour (brand/action)        — config/accents.ts
 *
 * "Midnight Slate" is the default (DEFAULT_DARK_THEME_KEY). "Petrol Black"
 * remains the CSS base (@theme values, no override block needed) but is no
 * longer the default selection; the other three (incl. Midnight Slate) are
 * override blocks in index.css.
 */

export interface DarkThemeDefinition {
  key: string;
  label: string;
  hint: string;
  /** Swatches for the picker preview: [background, surface, border/edge]. */
  swatch: [string, string, string];
}

export const DARK_THEMES: DarkThemeDefinition[] = [
  {
    key: 'petrol-black',
    label: 'Petrol Black',
    hint: 'Deep black-blue with ink-teal undertones. Sharp and premium.',
    swatch: ['#071011', '#101C1D', '#263A3B'],
  },
  {
    key: 'midnight-slate',
    label: 'Midnight Slate',
    hint: 'Navy-slate base. Calmer and slightly softer than black.',
    swatch: ['#0E1525', '#18213A', '#2E3B5C'],
  },
  {
    key: 'graphite-blue',
    label: 'Graphite Blue',
    hint: 'Charcoal base with blue-gray surfaces. High, practical contrast.',
    swatch: ['#0F1318', '#1A2028', '#36414F'],
  },
  {
    key: 'obsidian-teal',
    label: 'Obsidian Teal',
    hint: 'Near-black with subtle teal-blue panels. Elegant, not green-heavy.',
    swatch: ['#060B0E', '#0E1A1F', '#253D44'],
  },
  {
    key: 'storm-ink',
    label: 'Storm Ink',
    hint: 'Stormy blue-gray. Clean, serious and refined.',
    swatch: ['#0D1117', '#171D26', '#333E50'],
  },
];

export const DEFAULT_DARK_THEME_KEY = 'midnight-slate';
export const DARK_THEME_STORAGE_KEY = 'nexus.darkTheme'; // internal key retained

export type DarkThemeKey = string;

/** Read the persisted dark-theme key from localStorage. */
export function getStoredDarkTheme(): DarkThemeKey {
  try {
    const v = localStorage.getItem(DARK_THEME_STORAGE_KEY);
    if (v && DARK_THEMES.some((t) => t.key === v)) return v;
  } catch {
    /* storage unavailable */
  }
  return DEFAULT_DARK_THEME_KEY;
}

/** Persist a dark-theme key. */
export function storeDarkTheme(key: DarkThemeKey): void {
  try {
    localStorage.setItem(DARK_THEME_STORAGE_KEY, key);
  } catch {
    /* no-op */
  }
}

/** Apply the dark-theme attribute on <html> (read by the gated CSS blocks). */
export function applyDarkTheme(key: DarkThemeKey): void {
  document.documentElement.setAttribute('data-dark-theme', key);
}

/** Look up a dark theme by key; falls back to the default. */
export function findDarkTheme(key: string): DarkThemeDefinition {
  return (
    DARK_THEMES.find((t) => t.key === key) ??
    DARK_THEMES.find((t) => t.key === DEFAULT_DARK_THEME_KEY) ??
    DARK_THEMES[0]
  );
}
