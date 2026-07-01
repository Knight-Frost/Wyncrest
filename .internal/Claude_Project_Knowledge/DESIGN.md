---
name: Nexus
description: Premium Ghana property rental platform. "Warm Paper & Oxblood" direction approved June 2026 (supersedes Obsidian Pearl). Warm bone-paper surfaces, light editorial sidebar, a single rationed oxblood accent, hairline dividers. Fraunces display, Hanken Grotesque body, IBM Plex Mono labels.
version: alpha

# ============================================================
# APPROVED TOKENS — WARM PAPER & OXBLOOD DIRECTION
# Approved by user June 2026 (live, after rejecting Obsidian Pearl
# and a gold variant). These are confirmed. Implement them.
# SUPERSEDES the old Obsidian Pearl (mint/obsidian) token set.
# ============================================================
colors:
  # Paper — surfaces (warm, never pure white)
  canvas: "#F3EEE6"            # page background
  surface: "#FBF8F2"           # cards / panels / sidebar
  surface-elevated: "#FFFDF8"  # raised surfaces, rail gutter
  # Oxblood — the single accent (rationed: active state, prices, name, CTAs)
  accent: "#9E3024"            # button fill, active marker, prices
  accent-deep: "#872117"       # text links, active label, hover
  accent-tint: "#F6E7E2"       # active row background (very light wash)
  on-accent: "#FBF8F2"         # text on an oxblood fill
  # Ink — text
  ink: "#14110D"               # primary text
  ink-heading: "#0C0A06"       # headings
  ink-body: "#38322A"          # body
  ink-secondary: "#524B40"     # secondary
  muted: "#6B6358"             # muted / metadata (AA on canvas)
  placeholder: "#A89E8B"
  # Hairlines (used instead of heavy shadows)
  hairline: "#DED5C6"
  hairline-strong: "#CCC1AE"
  # Semantic — NOT brand colors
  success: "#2F7D52"
  success-bg: "#E4EFE7"
  warning: "#B5701A"
  warning-bg: "#F7EAD2"
  danger: "#C8372A"            # scarlet — kept REDDER than oxblood so it ≠ brand
  danger-bg: "#F8E2DD"
  info: "#2F5D86"
  info-bg: "#E2ECF3"
  # Domain
  money: "#92611F"             # GH₵ amounts (bronze) — or plain ink
  # Hero over photography (dark scrim)
  hero-text: "#FBF8F2"
  hero-name: "#E59A82"         # lightened terracotta — name accent over photos only

typography:
  # =========================================================
  # APPROVED: Fraunces (serif) for display/headings/numerals
  # APPROVED: Hanken Grotesque for body and UI
  # APPROVED: IBM Plex Mono for tracked uppercase labels/eyebrows/badges
  # (Replaces the old Space Grotesk / JetBrains Mono pairing.)
  # =========================================================
  hero:
    fontFamily: Fraunces
    fontSize: 66px
    fontWeight: "500"
    lineHeight: 1.0
    letterSpacing: -0.02em
  display:
    fontFamily: Fraunces
    fontSize: 40px
    fontWeight: "600"
    lineHeight: 1.05
    letterSpacing: -0.015em
  headline-lg:
    fontFamily: Fraunces
    fontSize: 26px
    fontWeight: "600"
    lineHeight: 1.2
  headline-md:
    fontFamily: Fraunces
    fontSize: 19px
    fontWeight: "600"
    lineHeight: 1.25
  body-lg:
    fontFamily: Hanken Grotesque
    fontSize: 16px
    fontWeight: "400"
    lineHeight: 1.6
  body-md:
    fontFamily: Hanken Grotesque
    fontSize: 14px
    fontWeight: "500"
    lineHeight: 1.55
  body-sm:
    fontFamily: Hanken Grotesque
    fontSize: 13px
    fontWeight: "400"
    lineHeight: 1.5
  eyebrow:
    fontFamily: IBM Plex Mono
    fontSize: 10px
    fontWeight: "500"
    lineHeight: 1
    letterSpacing: 0.16em
    textTransform: uppercase
  mono:
    fontFamily: IBM Plex Mono
    fontSize: 13px
    fontWeight: "500"
    lineHeight: 1.4

rounded:
  sm: 8px
  md: 12px
  lg: 16px
  full: 9999px

spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 40px
  section: 48px

components:
  sidebar:
    backgroundColor: "{colors.surface}"     # LIGHT paper, not dark
    textColor: "{colors.muted}"
    border: "1px solid {colors.hairline}"
    railWidth: 76px
    panelWidth: 300px      # rail + label panel (collapses to railWidth)
  sidebar-active:
    backgroundColor: "{colors.accent-tint}"
    textColor: "{colors.accent-deep}"
    marker: "3px left bar {colors.accent}"
  card:
    backgroundColor: "{colors.surface}"
    border: "1px solid {colors.hairline}"
    rounded: "{rounded.lg}"
    padding: "{spacing.lg}"
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.md}"
    height: 44px
  button-ghost:
    backgroundColor: transparent
    textColor: "{colors.ink-secondary}"
    border: "1px solid {colors.hairline-strong}"
    rounded: "{rounded.md}"
  badge:
    backgroundColor: "{colors.accent-tint}"
    textColor: "{colors.accent-deep}"
    rounded: "{rounded.full}"
    typography: "{typography.eyebrow}"
  input-field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    border: "1px solid {colors.hairline-strong}"
    rounded: "{rounded.md}"
    height: 44px
---

## Overview

> **APPROVED DIRECTION: WARM PAPER & OXBLOOD** (June 2026).
> **Supersedes "Obsidian Pearl."** The user rejected the mint/obsidian Obsidian Pearl
> direction live (and an interim gold variant), and approved this warm, light,
> print-editorial identity. Implement these tokens. Do not reintroduce mint/obsidian
> or gold without explicit approval.

Nexus uses the **Warm Paper & Oxblood** visual direction — a magazine-editorial language, not dark-mode SaaS.

- **Warm Paper** — a bone-warm canvas (`#F3EEE6`) with slightly lighter card surfaces (`#FBF8F2`). Never pure white. Calm, tactile, premium.
- **Oxblood** — a single deep brick-red accent (`#9E3024`), *rationed*: active nav, prices, the italic name in the hero, CTA fills. Nowhere decorative.
- **Hairlines, not shadows** — separation comes from `#DED5C6` rules; shadows are minimal and warm.
- **Serif headlines** — Fraunces does the hierarchy work; oversized, characterful.

---

## Colors

### Surfaces
- **Canvas `#F3EEE6`** — warm bone paper. Intentionally warm (this reverses the old "no warm surfaces" rule, per the user's explicit choice). Never pure white.
- **Surface `#FBF8F2`** — cards, panels, and the **light sidebar**.
- **Surface-elevated `#FFFDF8`** — the rail gutter and raised elements.

### Accent (rationed)
- **Oxblood `#9E3024`** — the ONLY chroma. Active nav marker, CTA fills, prices, hero name accent. Links/active text use the deeper `#872117`. Active rows use the light wash `#F6E7E2`.
- Rule: if it is not an active state, a CTA, a price, or the name accent — it is not oxblood.

### What Is Rejected (Hard Rules)
- **Mint / jade / teal / obsidian-dark** (old Obsidian Pearl) — rejected live, do not reintroduce.
- **Gold / bronze / brown / mustard / dusty warm** — hard rejected. Oxblood is a brick RED, not these.
- Purple / orchid / violet / lavender — hard rejected.
- Generic SaaS blue as identity, AI gradient blobs — hard rejected.
- Dark/black sidebars — rejected; the sidebar is LIGHT paper.
- Pure-white surfaces — rejected; surfaces are warm paper.

---

## Typography

- **Fraunces** (serif) — display, hero greeting, section headlines, card titles, big numerals & prices. Characterful, optical, editorial. Does the hierarchy work.
- **Hanken Grotesque** — body, UI labels, buttons, form fields. Clean, humanist, warm.
- **IBM Plex Mono** — tracked uppercase eyebrows ("— TENANT READINESS"), small labels, badges, and mono data. Adds the magazine/technical rhythm.

Inter is NOT the identity font (fallback only). The old Space Grotesk / JetBrains Mono pairing is retired.

---

## Layout (Tenant Dashboard — approved, single editorial column)

NOT a boxy grid. A **single full-width editorial column**, sections stacked and separated by mono rule-headings:

1. **Hero** (full-bleed, interpolating photos, state-aware, intelligent subtitle).
2. **Figures index strip** — hairline-ruled row of 4 figures (mono label + Fraunces numeral). Replaces stat-card boxes.
3. **Applications** (or **Active Lease**).
4. **Tenant Readiness** — full-width horizontal band (donut + checklist across + CTA).
5. **Curated for you** — full-width property gallery.
6. **Messages** — full-width 3-up.
7. **Saved homes** — full-width 3-up.

Rhythm variation (a short wide band between taller sections) keeps the tall column from reading as a list. Generous spacing; warm paper; hairlines; one oxblood accent.

---

## The Hero

- Full-bleed **interpolating** home photography (cross-fade + slow ken-burns through real `Homes_Photos`), warm dark scrim.
- **State-aware greeting** in Fraunces (e.g., "Good morning, **Alice.**" — name italic terracotta `#E59A82`), in a wide block that uses the horizontal space.
- **Rotating context subtitle** ("intelligence"), live `Accra · Saturday, 20 June` kicker, featured-photo credit, slide dots, subtle glass tool-chips, contextual CTAs (oxblood primary + ghost).
- Three states: `no_lease_no_apps`, `apps_in_progress`, `active_lease`.

### Not in the hero
Weather widget, stat tiles, budget info, multiple progress bars, document lists, more than two buttons.

---

## Sidebar (Icon rail + label panel — light)

- **Icon rail** (76px, always visible) + **label panel** (300px, collapsible to the rail). Light paper, `#DED5C6` hairline.
- **Oxblood active marker** (tint bg `#F6E7E2` + 3px left bar + accent icon).
- Fixed-width `<aside>` with `overflow: hidden` → **boundary-locked**, the active highlight cannot bleed across the page.
- Footer **pinned to the bottom** (collapse · theme · user). Collapse persists in `localStorage`. **No Refer & Earn card.**
- Implemented self-contained in `AppShell.tsx` (co-located `<style>` + literal colors) so layout can't desync from a stylesheet — see `lessons_to_apply.md`.

---

## Do's and Don'ts

Do:
- Warm paper surfaces; hairlines for separation; one rationed oxblood accent.
- Fraunces for all headings/numerals; IBM Plex Mono eyebrows; Hanken body.
- GH₵ for every money value, always.
- Keep the hero calm: greeting + one rotating message + contextual CTAs.
- Single editorial column for the tenant dashboard; spacious; legible.

Don't:
- Reintroduce mint/jade/obsidian-dark or gold/bronze/brown.
- Use purple, generic SaaS blue, or AI gradient blobs.
- Make the sidebar dark, or let any nav highlight escape the sidebar box.
- Use pure-white surfaces or cramped boxy grids.
- Use Inter (or system fonts) for headings.
