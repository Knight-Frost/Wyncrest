# Seeding: Demo Data and Production Setup

How Wyncrest fills a database with data, and why development and production never mix.

Who this is for: developers setting up a local copy, and anyone deploying Wyncrest who needs to know exactly what does and does not get created.

## Two modes, never mixed

| | Development mode | Production mode |
|---|---|---|
| Purpose | A small, realistic demo world for building and testing | A clean, real platform |
| Creates | 3 admins, 5 landlords, 5 tenants, properties, listings, contracts, a real ledger history, notifications, reviews, and more | Reference data only, plus one optional admin account |
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

**Admin team** (three accounts, so the access-control system is visible, not just described):

| Account | Role | What it can do |
|---|---|---|
| `admin@wyncrest.test` | Super admin | Everything |
| `reviewer@wyncrest.test` | Scoped admin | Review verifications, moderate listings, moderate reviews, view the audit log |
| `pending.admin@wyncrest.test` | Invited, not yet accepted | Nothing yet; demonstrates what an unaccepted invite looks like |

**Landlords and tenants** (five of each, all verified):

| Landlord notes | Tenant notes |
|---|---|
| One established landlord with a full property and tenants | Four tenants in good standing, paid up with a zero balance |
| One smaller landlord | One tenant who owes exactly one month's rent |
| One landlord with a listing awaiting review | |
| One landlord with limited features enabled | |
| One landlord with no properties yet, to show an empty state | |

**Money and inventory:** several properties and units across a handful of Ghanaian cities, a mix of active, pending, and draft listings, and active leases built through the real ledger logic, not invented numbers. The four good-standing tenants are paid to zero, and the one owing tenant has a single unpaid, overdue month. No late fee is invented; late fees only appear if the real overdue-processing rule creates one.

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

- Seeded photo galleries have metadata (so counts and galleries display correctly) but not real image files, so a seeded photo will not actually load until a real one is uploaded through the app.
- No real Stripe or Twilio calls happen during seeding. Payment records use clearly fake identifiers and never contact an external service.
