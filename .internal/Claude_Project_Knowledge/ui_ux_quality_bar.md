# UI/UX Quality Bar for Nexus

What "good" actually looks like when building Nexus screens. Use this as a checklist before saying any UI task is done.

**Note on colors and fonts:** Many checklist items below reference approved structural rules (spacing, state-awareness, GH₵ formatting). Where a checklist item references a specific color hex or a specific font name, treat it as the current candidate direction, not a final requirement. The exact palette and font pairing still need user visual approval.

---

## The Minimum Bar — Obsidian Pearl Direction (Approved)

A screen or component is not done until it passes all of these:

### Visual Quality
- [ ] Headings use **Space Grotesk** — not Inter, not a system font
- [ ] Body text uses **Hanken Grotesque** at minimum 13px, line height 1.5+
- [ ] Sidebar background is obsidian (`#1B1C2A`) — not navy, not dark blue, not charcoal gray
- [ ] Mint accent (`#22C49A`) is used ONLY for: active nav, CTAs, name highlight in hero, verified badges, progress fills, status chips
- [ ] No purple, orchid, violet, lavender, or aubergine anywhere
- [ ] No gold, bronze, brown, mustard, or dusty warm colors anywhere
- [ ] No AI-style glowing gradients
- [ ] All money values show GH₵ and are formatted correctly (GH₵ 2,500 not $2500)
- [ ] Page background uses pearl (`#F0F0EA`) — not pure white
- [ ] Cards use white (`#FFFFFF`) — lifts off the pearl background
- [ ] Cards have at least 24px internal padding
- [ ] Spacing between sections is at least 24px (prefer 32-40px)

### State Awareness
- [ ] Loading states are visible — skeleton loaders or spinner (never blank/frozen)
- [ ] Empty states explain why something is empty AND what the user should do next
- [ ] Error states are friendly — they tell the user what went wrong in plain language
- [ ] Authenticated user's name appears somewhere prominent (dashboard/hero level)

### Role Specificity
- [ ] The tenant, landlord, and admin views are visually and functionally distinct
- [ ] Tenants see rent-related information — not property management information
- [ ] Landlords see property management information — not tenant application flows
- [ ] Admins see platform-wide information with moderation capabilities

### Responsiveness
- [ ] The layout does not break at 375px wide (mobile minimum)
- [ ] Card grids collapse to 1 column on mobile, not squished
- [ ] Text does not overflow containers on small screens
- [ ] Buttons have at least 44px tap targets

---

## The Premium Bar

Beyond the minimum:

### Typography
- The heading font is used generously — section names, card titles, hero text — not just page titles
- Line heights are generous (1.5+ for body, tight 1.1-1.2 only for very large display sizes)
- Type scale creates a clear visual hierarchy — the most important thing is obviously bigger

### Color and Visual Clarity
- A strong, grounding primary color creates a visual anchor on every page
- Accent color appears in 1-2 places per screen maximum — not scattered
- Borders are subtle — they provide structure, not decoration
- No element competes equally with everything else — hierarchy is clear

### Spacing
- Nothing feels cramped
- Related elements are grouped with tight spacing
- Unrelated elements have generous separation
- Hero sections feel open and grand

### Interaction
- Buttons have visible hover states
- Cards that are clickable show a hover lift or cursor change
- Navigation active states are obvious at a glance

### Personalization
- The tenant dashboard hero addresses the user by name
- The experience changes based on what state the tenant is actually in (no lease / pending / active)
- The content shown is relevant to what the user needs right now

---

## Red Flags (Immediate Quality Failures)

These mean the UI is not ready:

1. **Purple / orchid / violet / lavender / aubergine anywhere** — hard rejected, full stop
2. **Gold / bronze / brown / mustard / dusty warm color anywhere** — hard rejected
3. **AI gradient (glowing blobs, purple/pink/blue shimmer)** — hard rejected
4. **Inter as the only font** — Space Grotesk must be used for all headings
5. **Sidebar not in obsidian (#1B1C2A)** — never navy, never blue
6. **Mint accent used decoratively** — it is only for active states, CTAs, verified, progress, name
7. **Generic "Welcome to your dashboard" unpersonalized text** — must use the tenant's actual name
8. **GH₵ not showing** — money is always GH₵
9. **Hero crammed with widgets, tiles, or stat cards** — hero = greeting + one message + one status + two buttons
10. **Empty stat cards with zeros for a tenant with no lease** — use a helpful empty state
11. **Cards with less than 16px internal padding** — minimum 24px
12. **Missing loading state** — never a frozen screen during data fetch
13. **Pale, washed-out text or low-contrast elements** — always strong contrast
14. **Western luxury mansion stock photography** — Ghana-realistic architecture

---

## Color/Font Approval Check

Before implementing specific colors or fonts in a new screen, ask:

- Is this color from the approved direction (dark grounding primary, controlled accent, high contrast)?
- Is this font the approved candidate or an approved fallback?
- Have I checked the rejected list in DESIGN.md?
- If I am picking a new color or font, has the user approved it visually?

If the answer to the last question is "not yet" — implement it as a proposal, say so in the report, and ask for visual confirmation before treating it as final.

---

## How to Judge a Design Decision

**Does it feel premium?**
Compare to a well-designed fintech or property app. Does it hold up? Or does it look like a template?

**Is it specific to Ghana?**
Does anything communicate that it is built for Ghana? (GH₵, context-appropriate images, the kind of trust a Ghanaian renter needs to feel?)

**Would a real tenant feel at home?**
If someone opened this dashboard after paying their first month's rent, would it feel like it was built for them? Or would it feel cold and generic?

**Is it honest?**
Does the UI tell the user what is actually happening? Or does it hide empty states and failures behind neutral-looking layouts?

**Is anything unapproved being presented as final?**
If implementing a color or font that has not been visually confirmed by the user, that must be clearly stated in the completion report.

---

## Reference Standards

For inspiration on what the design direction should feel toward:
- Large, warm, personalized hero sections (Camp Burnt Gin dashboard approach — state-aware, greeting-driven, action-oriented)
- Spacious card layouts with generous whitespace
- Premium apps with distinctive typography (not just Inter everywhere)
- Products that feel like they were designed specifically for their audience

Not references:
- Default admin dashboard templates (Bootstrap, Material, AdminLTE)
- Real estate portals with tiny search bars and dense property card grids
- Fintech apps with cold clinical data tables as the first thing you see
- Western SaaS products styled for US/European markets
