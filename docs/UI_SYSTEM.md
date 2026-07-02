# UI System

The visual design system behind Wyncrest's interface.

Who this is for: anyone building or reviewing a screen in Wyncrest, and anyone curious how the interface stays consistent across three very different user roles.

## Visual identity

Wyncrest's interface is built around a calm, uncluttered look: a light, airy background, soft translucent card surfaces, a serif display font for headings and numbers, and a plain, readable body font for everything else. The goal is a workspace that feels premium and trustworthy without being loud or busy.

## Light and dark mode

Every screen supports both a light and a dark appearance. Light mode uses a cool white background with soft, glassy card surfaces. Dark mode uses a deep, near-black background rather than a washed-out gray, so it stays comfortable in low light. A user can also choose a specific dark palette variation, not just a single dark look.

## The accent system

Users can personalize the interface with an accent color, chosen from a curated set of options. The accent controls interactive elements only, such as primary buttons, links, and focus outlines. It never touches the meaning-carrying colors described below (success, warning, danger). Every accent option is tuned separately for light and dark mode, so switching appearance never produces a hard-to-read result.

## Theme system, in three independent layers

| Layer | Controls | Example choices |
|---|---|---|
| Appearance | Light, dark, or match the system | Light |
| Dark palette | Which specific dark background is used, when dark mode is active | A deep near-black petrol tone |
| Accent | The interactive highlight color | A specific blue, teal, or red |

These three choices are independent. A user can be in dark mode with a light-mode-tuned accent, and it will still look correct, because every accent ships a dedicated dark variant.

## Semantic color meaning

Colors are never chosen for decoration. Each one carries a specific meaning, and that meaning is used consistently everywhere: green for success or good standing, amber for a warning or something due soon, red only for danger or something overdue, and a neutral tone for informational or default states. A red accent color never appears where success is meant, because the accent and the semantic colors are two separate systems.

## Role-specific interface principles

| Role | Interface goal |
|---|---|
| Tenant | Warm and reassuring: a clear "what do I owe, and when" summary front and center |
| Landlord | Operational and dense with real numbers: portfolio health, applicants, and collections at a glance |
| Admin | Governance-focused: moderation queues, platform health, and access control, with nothing invented or estimated |

All three roles share the same underlying components, so the platform never feels like three different products stitched together.

## Shared components

| Component | Purpose |
|---|---|
| Cards | Group related information; used at different visual weights depending on how important the content is |
| Record cards | Represent one item in a list (a property, a contract, an application) in a way that stacks cleanly on a phone instead of forcing a wide table to scroll sideways |
| Responsive tables | Used for dense, tabular data like money and audit history; reflow into a label-and-value layout on small screens instead of scrolling |
| Forms | Consistent field labeling, validation messaging, and spacing across every screen that collects input |
| Empty states | Every list has an honest, specific empty state rather than a blank screen or generic placeholder |
| Drawers | A right-side panel used for focused, multi-step actions like adding a property, so the user never leaves the page they were on |

## Notifications

Every real notification type maps to a visual category (payments, lease, listings and applications, reviews, or account and verification), each with its own icon and label. If a new notification type is ever added on the backend without also being categorized here, the interface is written to fail loudly during development rather than silently mis-displaying it.

## Audit log design

The audit log is presented as a readable timeline, not a raw table. Each entry shows what happened, who did it, and when, and links to a detail view with the real before-and-after values for anything that changed. Nothing shown is invented or approximated; if a detail is not available, the interface says so rather than guessing.

## Avatars and photos

A user's avatar shows their real uploaded photo when one exists, and falls back to their initials when it does not. Admin accounts, which live in a separate table with no photo storage, always show initials. No account ever shows a stock or placeholder photo pretending to be a real one.

## Accessibility principles

- Text and background combinations meet accessible contrast standards in both light and dark mode, and for every accent color choice.
- Interactive elements are reachable and usable by keyboard, not just by mouse.
- Motion (like a subtle count-up animation on a dashboard number) respects a user's system preference for reduced motion.
- Every meaningful icon is paired with a text label, so the interface does not rely on the icon alone to communicate.

## What makes the interface feel professional

- Nothing on screen is fabricated. A number, a status, or a history entry always traces back to a real record.
- Spacing is generous and consistent, not cramped.
- The same component looks and behaves the same way everywhere it appears.
- Every role gets an interface shaped for how they actually work, without diverging into a different visual language.
