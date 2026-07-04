# Seeding: Demo Data and Production Setup

How Wyncrest fills a database with data, and why development and production never mix.

Who this is for: developers setting up a local copy, and anyone deploying Wyncrest who needs to know exactly what does and does not get created.

## Two modes, never mixed

| | Development mode | Production mode |
|---|---|---|
| Purpose | A small, realistic demo world for building and testing | A clean, real platform |
| Creates | 4 admins, 7 landlords, 9 tenants (plus a few verification-queue accounts), properties, listings, contracts across their full lifecycle, a real ledger history, messaging, notifications, reviews, and more | Reference data only, plus one optional admin account |

> **For the full account-by-account scenario matrix — every login, its purpose,
> and exactly what it lets you test — see [`SEEDED_SCENARIOS.md`](SEEDED_SCENARIOS.md).**
| Demo people, money, or properties | Yes, clearly fictional | Never |
| Safe to reset | Yes, any time | No |
| Safe to run more than once | Yes | Yes, it will not create duplicates |

**Production setup never invents a landlord, a tenant, a property, a contract, a payment, or a notification.** If it did, that would defeat the entire point of a production environment.

## Choosing a mode

Wyncrest decides which mode to use in this order:

1. The `WYNCREST_SEED_MODE` environment variable, if it is set to `development` or `production`.
2. Otherwise, if the environment is set to `production`, production mode is used; any other environment defaults to development mode.

The older `NEXUS_SEED_MODE` variable is still honored as a fallback, so existing setups keep working, but `WYNCREST_SEED_MODE` is the current, preferred name.

## Setup commands

| Goal | Command |
|---|---|
| Local development world (the default) | `php artisan migrate:fresh --seed` |
| Explicit development mode | `WYNCREST_SEED_MODE=development php artisan migrate:fresh --seed` |
| Production-safe baseline only | `WYNCREST_SEED_MODE=production php artisan db:seed` |
| Verify the development world is correct | `php artisan wyncrest:seed:verify` |

The `./dev.sh` script (see [`docs/DEVELOPMENT.md`](DEVELOPMENT.md)) runs the right command for you.

## The development world

**Warning: everything below is fictional demo data. None of it exists outside a local or demo environment set to development mode.**

**Admin team** (four accounts, so the access-control system is visible, not just described):

| Account | Role | What it can do |
|---|---|---|
| `admin@wyncrest.test` | Super admin | Everything |
| `reviewer@wyncrest.test` | Scoped — content | Review verifications, moderate listings, moderate reviews, view the audit log |
| `finance@wyncrest.test` | Scoped — finance | Manage contracts & the ledger, view analytics, view the audit log |
| `pending.admin@wyncrest.test` | Invited, not yet accepted | Nothing yet; demonstrates what an unaccepted invite looks like |

The two scoped admins are deliberate mirror images — each is denied what the other is allowed — so a `403` is demonstrable in both directions.

**Landlords** (seven) and **tenants** (nine) — each account exists to exercise one specific scenario (verification states, account states, financial states, contract lifecycle, empty states). The full breakdown lives in [`SEEDED_SCENARIOS.md`](SEEDED_SCENARIOS.md); in summary:

| Landlord notes | Tenant notes |
|---|---|
| Established, smaller, listing-in-review, and limited-feature landlords | Four tenants in good standing (zero balance) |
| An empty-state landlord (no properties) | One owing exactly one clean month's rent |
| A pending-verification landlord (blocked from listing by the hard-gate) | One owing rent **plus a real late fee** |
| A suspended landlord (login rejected) | Two former tenants (one terminated lease, one expired) |
| | One unverified tenant (blocked from applying) |

**Money and inventory:** several properties and units across a handful of Ghanaian cities, a mix of active, pending, draft and inactive listings, and leases across their full lifecycle (active, terminated, expired) built through the real ledger logic, not invented numbers. Good-standing and former tenants are paid to zero; one tenant owes a single clean overdue month; one owes that plus a late fee raised through the real service. Messaging threads carry a mix of read and unread messages, with two inboxes left empty on purpose.

All demo accounts share the password `password` and use the reserved `wyncrest.test` email domain, which cannot receive real mail.

## Production setup

Production setup creates only:

- Reference data that every deployment needs (such as feature definitions).
- One optional administrator account, only if all of its details were supplied through environment variables ahead of time.

If those environment variables are not fully supplied, no admin is created, and a warning is logged. Nothing is guessed or invented.

Production setup can be run more than once safely. It updates existing reference data instead of duplicating it.

## Resetting safely

| Environment | What happens |
|---|---|
| Development | `php artisan migrate:fresh --seed` (or `./dev.sh`) fully rebuilds the database. The dev runner also refuses to reset anything but a local SQLite database. |
| Production | Never run a destructive reset. Production setup is designed to be re-run safely without wiping anything. |

## Known limitations

- Seeded photo galleries use real image files when the bundled `Homes_Photos/` folder is present (copied to the public disk); otherwise they fall back to metadata-only rows so counts still populate.
- No real Stripe or Twilio calls happen during seeding. Payment records use clearly fake identifiers and never contact an external service.

## Scenarios that are deliberately not seeded

Two commonly-requested scenarios are **not supported by the backend**, so the seeder does **not** fake them (a fake would make the UI lie):

- **Partial payments** — the ledger requires every payment to settle the full obligation; there is no partial-payment state to represent.
- **Failed-payment ledger rows** — a failed payment writes only an audit entry and a notification, never a ledger row. The seed world includes that truthful trace (on `tenant.owing`) but no fabricated "failed" ledger entry.

See the [Unsupported scenarios](SEEDED_SCENARIOS.md#unsupported-scenarios) section of the scenario guide for the full rationale and what a backend change would require.
