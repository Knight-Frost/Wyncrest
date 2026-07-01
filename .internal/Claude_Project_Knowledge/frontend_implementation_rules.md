# Frontend Implementation Rules for Nexus

Rules for writing TypeScript and React in the Nexus frontend. Based on established project patterns plus lessons from the study materials.

---

## Technology Stack (Do Not Change)

- React 18 (function components only, no class components)
- TypeScript 5 (strict mode, no `any` for API payloads)
- Vite (bundler)
- Tailwind CSS v4
- React Router 7
- Axios for API requests
- Sanctum Bearer token auth (NOT cookie/SPA mode)

---

## File Structure

The current structure is in `frontend/src/`. Do not invent new structure. Use existing conventions.

```
frontend/src/
  components/
    brand/        — logo, wordmark, brand elements
    layout/       — AppShell, PageHeader, Sidebar
    listings/     — listing cards, listing-specific components
    ui/           — Button, Badge, Card, Field, Modal, Table, etc.
  context/        — auth context and hooks
  lib/            — api.ts, endpoints.ts, format.ts, storage.ts, types.ts
  pages/
    admin/        — admin-specific pages
    auth/         — login, register
    landlord/     — landlord-specific pages
    shared/       — pages accessible to multiple roles
    tenant/       — tenant-specific pages
  routes/         — nav.tsx, route definitions
```

---

## Component Rules

### Composition Over Complexity
Build small, composable components. Do not build god components.

```tsx
// Good: small, single-purpose
function PropertyAddress({ property }: { property: Property }) {
  return (
    <div className="text-sm text-muted">
      {property.address}, {property.city}
    </div>
  )
}

// Bad: one massive component doing everything
function PropertyCard({ property, onSave, onApply, isOwner, canEdit, ... }) {
  // 200 lines of conditional rendering
}
```

### Props Are Typed, Always
```typescript
// Good
interface ListingCardProps {
  listing: Listing
  onSave?: () => void
  variant?: 'default' | 'compact'
}

// Bad
function ListingCard(props: any) { }
```

### No `any` for API Responses
All API response shapes live in `frontend/src/lib/types.ts`. If a type does not exist, add it there. Do not use `any` or `unknown` without assertion.

---

## Money Formatting

Money in Nexus is always GH₵. The `format.ts` file handles this. Always use it.

```typescript
// Use the format utility
import { formatMoney } from '@/lib/format'

// Shows: "GH₵ 2,500"
formatMoney(250000) // amount_cents → divide by 100

// On a listing card
<span className="text-lg font-semibold text-navy">{formatMoney(listing.rent_amount)}/mo</span>
```

Never hardcode currency symbols. Never format money inline.

---

## API Calls

All API calls go through `frontend/src/lib/api.ts`. Do not call axios directly from components or pages.

```typescript
// Good
import { api } from '@/lib/api'
const listings = await api.get(endpoints.listings.browse)

// Bad
import axios from 'axios'
const listings = await axios.get('/api/listings')
```

Use `endpoints.ts` for all URL paths. Do not hardcode endpoint strings.

---

## State Management

Use local state (useState/useReducer) for component-level state. Use the AuthProvider context only for auth/user state. Do not introduce a global state library unless explicitly approved.

---

## Typography Rules — APPROVED (June 2026)

The Obsidian Pearl font pairing is confirmed:

| Use | Font | Notes |
|-----|------|-------|
| All headings, hero text, display | **Space Grotesk** | 700 for display/hero, 600 for section heads |
| Body text, descriptions, UI labels | **Hanken Grotesque** | 400 for body, 600 for labels |
| Reference numbers, ledger amounts | **JetBrains Mono** | Only where monospace precision matters |
| Fallback only | Inter | Never as primary personality |

When implementing:
- Load Space Grotesk and Hanken Grotesque from Google Fonts (or local if already installed)
- Font weights needed: Space Grotesk 600, 700; Hanken Grotesque 400, 500, 600
- Do not use Inter for any heading. If Space Grotesk is not loaded yet, add it before shipping.

---

## Color Token Rules — APPROVED (June 2026)

The Obsidian Pearl palette is confirmed. Use these tokens:

```css
/* Core palette */
--color-obsidian: #1B1C2A;        /* sidebar, hero overlay */
--color-obsidian-light: #252638;  /* active nav background */
--color-mint: #22C49A;            /* accent: CTAs, active, verified, progress, name */
--color-mint-light: #D4F5EC;      /* status badge backgrounds */
--color-mint-dim: #19A881;        /* hover states on mint */
--color-surface: #FFFFFF;         /* cards */
--color-surface-pearl: #F0F0EA;   /* page background */
--color-on-surface: #14141E;      /* primary text */
--color-on-surface-muted: #7A7A8A; /* secondary text */
--color-border: #E5E5EA;          /* card borders, dividers */
--color-on-obsidian: #FFFFFF;     /* text on dark */
--color-on-obsidian-muted: rgba(255,255,255,0.6); /* inactive nav */
```

Hard rejected — do not add these to any CSS or component:
- Any purple/violet/orchid/lavender — hard rejected
- Any gold/bronze/brown/mustard/dusty warm — hard rejected  
- Generic SaaS blue as identity — hard rejected

---

## Tailwind CSS v4 Rules

Nexus uses Tailwind v4. The configuration is in `frontend/` as CSS-first (not config file).

- Add the approved palette tokens as CSS custom properties in `index.css`
- Use semantic Tailwind classes that map to these tokens (`bg-obsidian`, `text-mint`, etc.)
- Responsive classes are mobile-first (`sm:`, `md:`, `lg:`)
- Never use arbitrary Tailwind values for colors that have tokens (`bg-[#1B1C2A]` → use `bg-obsidian`)

### Critical Spacing Rules
- Card padding: `p-6` (24px) minimum
- Section gaps: `gap-6` minimum between cards, `gap-10` between major sections
- Never `p-2` or `p-3` on a card users read content inside

---

## Role-Specific Page Rules

Every page must know what role is viewing it. The `AuthProvider` context has the user and their role.

```typescript
// In a page or component
const { user } = useAuth()

// Good: conditional rendering based on role
{user?.user_type === 'tenant' && <TenantHeroSection />}
{user?.user_type === 'landlord' && <LandlordMetricsSection />}

// Bad: showing everything to everyone and hoping it looks right
```

Route guards are in `routes/nav.tsx`. Check that guards exist before adding new authenticated pages.

---

## Loading and Error States

Never leave a screen blank while loading. Never leave an error silent.

```tsx
// Pattern for data-fetching components
function TenantDashboard() {
  const { data, loading, error } = useDashboardData()

  if (loading) return <DashboardSkeleton />
  if (error) return <ErrorState message="Could not load your dashboard" />
  if (!data) return <EmptyState message="No dashboard data yet" />

  return <DashboardContent data={data} />
}
```

Skeleton loaders should match the layout of the actual content (not a generic spinner in the middle of a complex page).

---

## Form Rules

Forms use controlled components. Validation happens on submit and on blur. Error messages appear below the relevant field, not in a toast that disappears.

```tsx
// Good: field-level errors
<Field
  label="Rent Amount (GH₵)"
  error={errors.rent_amount}
>
  <input type="number" {...register('rent_amount')} />
</Field>
```

---

## What Not to Do

- Do not import from node_modules directly in a component (use the abstraction layer)
- Do not use `console.log` in committed code
- Do not use `// @ts-ignore` — fix the type error
- Do not create new pages without adding them to the route definitions
- Do not skip loading/error states thinking "the data will load fast enough"
- Do not duplicate components that already exist in `ui/`
- Do not write inline styles — use Tailwind classes

---

## Before Submitting Frontend Changes

Run these in order:
1. `cd frontend && npx tsc --noEmit` — must have zero errors
2. `npx eslint src/` — must have zero errors
3. `npm run build` — must succeed
4. Visual check: open the page in the browser and verify it looks right
