# Testing

What is tested in Wyncrest, and how to check your work before pushing.

Who this is for: anyone making a change to the backend or frontend who wants to confirm it did not break anything.

## What the tests cover

Wyncrest's backend has 692 automated tests, covering:

| Area | What is checked |
|---|---|
| Authentication | Login, registration, password rules, rate limiting |
| Authorization | Every role and admin capability, including that denied access actually gets denied |
| Contracts and ledger | The full lease lifecycle, and that rent, payments, and balances stay mathematically consistent |
| Payments | Stripe integration, webhook handling, and that a payment cannot be recorded twice |
| Notifications | That the right event sends the right notification, through the right channel |
| Admin access | Every admin capability, including attempts to bypass one that was not granted |
| Security | Headers, rate limits, and the kind of access attempts a real attacker would try |

## Backend tests

| Command | What it does |
|---|---|
| `php artisan test` | Runs the full backend test suite |
| `composer test` | Same thing, via Composer |
| `./vendor/bin/pint --test` | Checks code style without changing anything |

A change is not considered done until this suite passes.

## Frontend checks

The frontend does not currently have an automated test suite (no Vitest or React Testing Library is wired up). Frontend correctness is verified with:

| Command | What it does |
|---|---|
| `npm run lint` | Checks code style and catches common mistakes |
| `npm run build` | Runs the TypeScript type checker and produces a production build |

If both of these pass, the code is structurally sound, but that does not confirm the feature actually works the way it looks on screen. That is what browser review is for.

## Why browser review matters

Lint and a clean build prove the code compiles. They do not prove a button does the right thing when clicked, or that a form shows the right error message. For any visible change, the expected way to confirm it works is to actually open it in a browser and use it: click through the flow, check for errors in the browser console, and confirm the real backend responds the way the screen suggests it did.

## How to think about tests

- A test should describe a real rule ("a tenant cannot see another tenant's lease"), not just repeat what the code already does.
- If you fix a bug, add a test that would have caught it. That is what keeps the same bug from coming back later.
- A green test suite means the rules it checks are still true. It does not mean the feature is finished or that it looks right; that is a separate, human judgment.

## Before pushing, checklist

| Check | How |
|---|---|
| Backend tests pass | `php artisan test` |
| Code style is clean | `./vendor/bin/pint --test` |
| Frontend lint is clean | `npm run lint` |
| Frontend build succeeds | `npm run build` |
| Any visible change was opened and used in a real browser | Manual review |
| No secrets or local-only files were staged | `git status` |
