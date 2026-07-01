# Nexus Design Memory

**APPROVED DIRECTION: WARM PAPER & OXBLOOD** — confirmed June 2026.

> **This supersedes the earlier "Obsidian Pearl" direction.** The user reviewed Obsidian Pearl (mint/obsidian) rendered live and rejected it as "terrible" and the color "brown"/cliché, then rejected an interim gold variant. They selected **Warm Paper & Oxblood**: a warm, light, editorial-magazine identity. Mint/jade/obsidian and gold are dead directions — do not reintroduce them without explicit approval.

---

## What Nexus Is

Nexus is a premium Ghana property rental management platform — full lifecycle: listings, contracts, payments, notifications, analytics. Three roles, each a distinct experience:
- **Tenants** — find homes, apply, track leases, pay rent
- **Landlords** — manage properties, listings, tenants, finances
- **Admins** — moderate listings, audit the system, manage the platform

The aesthetic mantra (user's words): **"Nothing cliché or generic. Everything unique."** The chosen answer is *print-editorial*, not dark-mode proptech SaaS.

---

## Approved Direction: Warm Paper & Oxblood

### Visual Feel
- Warm, light, editorial — like a well-set magazine spread, not a dashboard of boxes.
- Hierarchy comes from the **type scale + hairline rules**, not heavy shadows or color.
- Oxblood is **rationed** — used as punctuation (active state, prices, the italic name), never as fill across surfaces.
- Premium, calm, spacious, human, Ghana-specific. Distinctive — nobody else in Ghana rental looks like this.

### Approved Color System

| Token | Hex | Role |
|-------|-----|------|
| `canvas` | `#F3EEE6` | Page background — warm bone paper (intentionally warm, **never** pure white) |
| `surface` | `#FBF8F2` | Cards / panels / **sidebar** |
| `surface-elevated` | `#FFFDF8` | Raised surfaces, rail gutter |
| `ink` (text) | `#14110D` | Primary text |
| `ink-heading` | `#0C0A06` | Headings |
| `ink-body` | `#38322A` | Body text |
| `ink-secondary` | `#524B40` | Secondary text |
| `muted` | `#6B6358` | Muted text / metadata (AA on canvas) |
| `accent` (oxblood) | `#9E3024` | Primary accent — button fill, active marker, prices |
| `accent-deep` | `#872117` / `#7E1F19` | Text links, active label, hover |
| `hairline` | `#DED5C6` | Borders, dividers (the workhorse — used instead of shadows) |
| `success` | `#2F7D52` | — |
| `warning` | `#B5701A` | — |
| `danger` | `#C8372A` | Clean scarlet — kept visibly REDDER than oxblood so "destructive" ≠ "brand" |
| `info` | `#2F5D86` | — |
| `money` | `#92611F` | GH₵ amounts (bronze) — or plain ink |
| hero-text | `#FBF8F2` | Ivory text over hero photography (dark scrim) |
| hero-name | `#E59A82` | Lightened terracotta — the name accent over photos only |

### Approved Fonts

| Use | Font |
|-----|------|
| Display — headings, hero greeting, big numerals, prices | **Fraunces** (serif, optical, characterful) |
| Body text, UI labels, buttons | **Hanken Grotesque** |
| Tracked uppercase eyebrows, labels, badges, mono data | **IBM Plex Mono** |

(Changed from the old Space Grotesk / JetBrains Mono pairing. Inter is NOT the identity font.)

---

## Rejected Directions — Never Implement Without Explicit Approval

- **Mint / jade / teal / obsidian-dark sidebar** (the old "Obsidian Pearl") — rejected live.
- **Gold / bronze / brown / mustard / dusty warm** — hard rejected. (Oxblood `#9E3024` is a deep brick RED, distinct from these.)
- Purple / orchid / violet / lavender / aubergine — hard rejected.
- Generic SaaS blue as identity — rejected.
- AI-style glowing gradient blobs — hard rejected.
- Dark/black sidebar — rejected; the sidebar is LIGHT paper.
- Cramped card grids, 4-column stat grids, tiny text, hero crammed with widgets — rejected.
- Pure-white surfaces — rejected; surfaces are warm paper.

---

## The Tenant Dashboard (APPROVED, built & signed off this session)

The layout is a **single full-width editorial column** (the user explicitly rejected the old 2-column-split / boxy-grid layout). Top to bottom:

1. **Hero** — full-bleed, intelligent, interpolating.
2. **Figures index strip** — a hairline-ruled row of 4 figures (mono label + big Fraunces numeral): Applications · Saved · Tenant Readiness · Rent due/Verified homes. Replaces boxy stat cards.
3. **Applications** (or **Active Lease** when leased) — full-width feature.
4. **Tenant Readiness** — full-width horizontal band: donut + checklist across + CTA.
5. **Curated for you** — full-width property gallery (large image cards, zoom on hover).
6. **Messages** — full-width 3-up.
7. **Saved homes** — full-width 3-up horizontal cards.

Sections are separated by mono "rule-headings" (`— TENANT READINESS ————`), like magazine section breaks.

### Hero (Confirmed Rules)

- **Interpolating home photography** — cross-fade + slow ken-burns through the real `Homes_Photos` images.
- **State-aware greeting** (Fraunces, large): "Good morning, **Alice.**" — name in italic terracotta. Wide block that uses the horizontal space (not crammed left).
- **Rotating "intelligence" subtitle** — context-aware lines that cycle (e.g., "Two applications are moving forward" → "A landlord reviewed your profile today").
- **Live kicker**: `Accra · Saturday, 20 June`. **Photo credit** (Featured — property name, location). **Slide dots**. Subtle glass tool-chips top-right (search/notifications/messages). **Contextual CTAs** (oxblood primary + ghost).
- Three states drive copy/CTAs: `no_lease_no_apps`, `apps_in_progress`, `active_lease`.

---

## Sidebar Navigation (APPROVED, rebuilt this session)

**Architecture: icon rail + label panel (light).** Implemented self-contained in `frontend/src/components/layout/AppShell.tsx` (styles co-located in a `<style>` block + literal colors, so they can't desync from a stylesheet — see the lessons file).

- **Icon rail** = the always-visible first 76px (icons). **Label panel** = the rest (300px expanded), collapsible.
- **Collapse** toggle anchored in the footer; **persists in `localStorage`** (`nexus_nav_collapsed`); collapsed → only the 76px rail remains.
- **Light paper** surface (`#FBF8F2`), `#DED5C6` hairline, **oxblood** active marker (tint bg + left bar + accent icon). NOT a dark sidebar.
- **Boundary-locked**: the `<aside>` is a fixed-width box with `overflow: hidden` — the active highlight can never bleed across the page.
- Footer **pinned to the bottom** (`margin-top: auto`): collapse · theme toggle · user (avatar/name/role/sign-out).
- **Refer & Earn card REMOVED** (user request).
- Tenant nav groups/items: **Find a Home** (Dashboard, Browse Homes, Saved Homes, Compare) · **My Rental** (Applications, Lease & Rent, Payments, Maintenance) · **Communicate** (Messages, Documents) · **Account** (Notifications, Profile). *("Settings" was requested but has no route/page yet — add a page before adding the nav item.)*

---

## Scope Status

- **Tenant dashboard + sidebar: DONE & APPROVED** in Warm Paper & Oxblood.
- **Landlord / admin dashboards**: auto-recolor via tokens but still need the same editorial *layout* treatment — not yet hand-built.
- **Landing / Login / Register**: untouched so far; user later granted permission to redo them in this direction. Note: auth pages currently consume the GLOBAL Jade tokens in `frontend/src/index.css`; the editorial skin is applied as a scoped layer (`[data-skin='editorial']`) on the app shell so it doesn't disturb them.

---

## Money Formatting (Hard Rule — Always Apply)

Always `GH₵ [amount]` via the format utility. `GH₵ 2,500` on cards, `GH₵ 3,500 /mo` on listings, full precision on ledger. Never `$`.

---

## Layout Rules

1. Cards breathe — generous internal padding; sections separated by 40px+.
2. Warm paper surfaces, never pure white. Hairlines (`#DED5C6`) do the separating, not heavy shadows.
3. Oversized serif (Fraunces) headlines carry hierarchy.
4. Oxblood is punctuation only — active states, prices, the name accent. Never a fill across surfaces or section headers.
5. Text legible — nothing below ~12px; body 14px+.
6. Empty states guide — never a blank panel.
7. Prevent horizontal overflow (`min-width:0`, `overflow-x:hidden` on content).

---

## Approval Boundary Going Forward

Warm Paper & Oxblood is confirmed for the tenant dashboard + sidebar. The same token system applies to landlord/admin and landing/auth, but their layouts must be designed/confirmed before building — do not guess.

If a future design decision conflicts with this file, the newer explicit user instruction wins. **Update this file when that happens.**
