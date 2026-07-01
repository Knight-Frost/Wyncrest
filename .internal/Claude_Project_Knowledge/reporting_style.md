# Reporting Style for Nexus

Every completion report must use this exact structure. No exceptions. No variations.

---

## The Required Format

```
## Simple Summary

[What happened in plain English. 2-5 sentences. No jargon. Assume the reader is smart but busy.]

## What I Changed

[Bullet list. Real changes only. Not vague claims. Each bullet = one actual thing that changed.]

- [file or feature]: [what changed]
- [file or feature]: [what changed]

## Why It Matters

[How this helps Nexus. 1-3 sentences. Explain the value, not just the mechanics.]

## Files Touched

[List of every file that was created or modified.]

- `path/to/file.tsx` — created / modified
- `path/to/file.php` — created / modified

## What I Applied From Knowledge

[Which knowledge files or lessons influenced this work. Be specific.]

## Checks I Ran

[Everything you actually checked. Be honest. If you did not check something, do not list it.]

- [ ] TypeScript: `tsc --noEmit` — passed / failed
- [ ] ESLint: `npx eslint src/` — passed / failed / skipped
- [ ] Build: `npm run build` — passed / failed / skipped
- [ ] Backend tests: `php artisan test` — 287 passed / X failed / not run
- [ ] Visual check: opened in browser — yes / no
- [ ] Checked mobile layout — yes / no / not applicable

## Problems or Risks

[Anything unfinished, broken, risky, ugly, or unclear. Be honest. If everything is clean, say so and why you believe that.]

## Next Best Step

[One clear recommendation for what should happen next.]
```

---

## Tone Requirements

- Casual and direct, like explaining to a smart friend
- Easy to understand without developer background
- No jargon as the first thing you say (explain it if you must use it)
- No walls of text
- No fake confidence ("everything is working perfectly" when you have not actually checked)
- No em dashes
- No "I've successfully completed..."
- No hiding failures
- No vague "done"
- No "I also took the liberty of..."

---

## Examples

### Good Simple Summary
"Fixed the button on the tenant dashboard that was showing 'undefined' instead of the rent amount. The bug was in how we were reading the ledger response — it expected `amount_cents` but the API returns `amount`. Changed one line."

### Bad Simple Summary
"Successfully implemented a comprehensive fix to the rendering logic in the TenantDashboard component by updating the data mapping layer to correctly interface with the backend API's response schema, ensuring proper type coercion and null safety throughout the data flow pipeline."

---

### Good What I Changed
- `frontend/src/pages/tenant/TenantDashboard.tsx:142` — changed `entry.amount_cents` to `entry.amount`
- `frontend/src/lib/format.ts` — added null check to `formatMoney` for safety

### Bad What I Changed
- Updated the dashboard to fix the amount display issue
- Made improvements to the format utility

---

### Good Problems or Risks
"The fix works for tenants with active leases. I did not check how it behaves when a tenant has no lease yet (the section may just be hidden in that state). Should verify that edge case."

### Bad Problems or Risks
"No issues found. Everything is working as expected."

---

## When to Use This Format

Every time you finish a task and report back to the user. Even for small changes. Even when nothing went wrong. The format is fast and clear. It takes 2 minutes to fill out honestly.

If a task is so small it feels silly to use the full format, still use the Simple Summary + What I Changed + Checks I Ran sections at minimum.
