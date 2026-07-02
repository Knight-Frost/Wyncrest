# Operations

Practical safety checks for running or demoing Wyncrest.

Who this is for: whoever is responsible for deploying, demoing, or maintaining a running copy of Wyncrest.

## Production safety

| Check | Why it matters |
|---|---|
| `APP_ENV=production` and `APP_DEBUG=false` | Prevents internal errors from leaking stack traces to users |
| Production setup was run, not development setup | Development setup creates fictional demo accounts and money; production setup never does |
| A real `APP_KEY` is set and kept secret | Protects encrypted data and signed tokens |
| Database credentials are least-privilege | Limits damage if credentials are ever exposed |
| Backups are configured and tested | Protects against data loss |

## Seed safety

Production setup creates only reference data and, if fully configured through environment variables, a single administrator account. It never creates a landlord, a tenant, a property, a contract, a payment, or a notification. Full detail: [`docs/SEEDING.md`](SEEDING.md).

If a real deployment ever shows demo-looking data (fictional names, a `wyncrest.test` email domain, or obviously fake dollar amounts), that is a sign development mode was seeded by mistake, and should be treated as an incident, not ignored.

## Environment safety

- Secrets live only in a local `.env` file on the server, never in version control.
- Real Stripe and Twilio credentials are required for real payments and SMS to work; without them, those features stay safely disabled instead of failing unpredictably.
- CORS and login-session settings should only ever point at the real, deployed frontend address.

## Admin access operations

- Keep the number of super admins small. A super admin can grant or remove any capability from any other admin, so this account type should be trusted carefully.
- When an admin leaves or changes role, revoke or adjust their capabilities immediately rather than leaving stale access in place.
- Wyncrest will not let the last super admin be demoted or deactivated, so the platform can never be left with zero super admins by accident.

## Before a public demo

| Check | Why |
|---|---|
| Confirm you are looking at development-mode data, and say so out loud if showing it publicly | Avoids anyone mistaking demo data for a real customer's data |
| Confirm real payment credentials are not accidentally active on a demo box | Prevents a real charge happening during a demo |
| Confirm the demo account passwords are not reused anywhere real | Demo credentials are meant to be disposable |
| Walk through the core flow once before presenting: listing, application, contract, payment, notification | Catches anything broken before an audience sees it |
