# Database Seeding

Wyncrest ships a **mode-aware** seeding system with two clearly separated modes:

| Mode | Purpose | Creates |
|------|---------|---------|
| **development** | A small, controlled, fully-truthful local world that exercises the whole platform without demo noise | 1 admin, 5 landlords, 5 tenants, 4 properties, 10 units/listings, 5 active leases, an immutable ledger, plus supporting applications/reviews/maintenance/notifications/audit - all tied to real state |
| **production** | Safe baseline initialization | Reference data (feature definitions) + an **optional** bootstrap admin from env. **Never** any demo people, money, or inventory. |

The fastest way to drive either mode locally is `./dev.sh` (development) or
`./dev.sh --prod` (production preview) - both reset the database, seed the chosen
mode, and boot the API + queue + SPA. See the [README](../README.md) quick start.

## Quick start

```bash
# Local development world (auto-resolves to development mode)
php artisan migrate:fresh --seed

# Explicit development mode
WYNCREST_SEED_MODE=development php artisan migrate:fresh --seed

# Production-safe baseline ONLY (idempotent - safe to re-run)
WYNCREST_SEED_MODE=production php artisan db:seed

# Verify the development world + ledger consistency
php artisan wyncrest:seed:verify
```

## Mode resolution

`DatabaseSeeder` chooses a mode in this order:

1. `WYNCREST_SEED_MODE` env (`development` | `production`), if set.
   *(The legacy `NEXUS_SEED_MODE` is still honoured as a fallback.)*
2. Otherwise: `APP_ENV=production` → **production**, else **development**.

## Environment variables

The project prefers `WYNCREST_*` env names; the legacy `NEXUS_*` names are still
read as fallbacks so existing `.env` files keep working.

| Variable | Default | Purpose |
|----------|---------|---------|
| `WYNCREST_SEED_MODE` | *(auto)* | Force a mode. |
| `WYNCREST_DEMO_PASSWORD` | `password` | Shared password for **all** demo accounts (dev only). |
| `WYNCREST_DEMO_DOMAIN` | `wyncrest.test` | Reserved, non-routable email domain for demo accounts. |
| `WYNCREST_DEMO_TENANTS` | `5` | Expected demo tenant count (enforced by the verify command). |
| `WYNCREST_DEMO_LANDLORDS` | `5` | Expected demo landlord count. |
| `WYNCREST_DEMO_CURRENCY` | `GHS` | Currency for demo money (presented as GH₵). |
| `WYNCREST_BOOTSTRAP_ADMIN_EMAIL` | - | Production bootstrap-admin email (optional). |
| `WYNCREST_BOOTSTRAP_ADMIN_NAME` | - | Production bootstrap-admin name (optional). |
| `WYNCREST_BOOTSTRAP_ADMIN_PASSWORD` | - | Production bootstrap-admin password (optional). |
| `WYNCREST_ALLOW_DEV_SEED_IN_PROD` | `false` | Safety latch - must be `true` to run the dev seeder under `APP_ENV=production`. |

> Production seeding **never** creates demo data and **never** invents admin
> credentials. If the three `WYNCREST_BOOTSTRAP_ADMIN_*` vars are not all present,
> the admin is skipped with a logged warning. (`./dev.sh --prod` supplies safe
> local defaults so the production preview always has exactly one admin to log in.)

## The development world

A deliberately **small and recognisable** dataset - every number on every
dashboard is derivable from these records.

**Accounts** (password `password`, `@wyncrest.test` domain):

| Role | Email | Notes |
|------|-------|-------|
| Admin | `admin@wyncrest.test` | System administrator |
| Landlord | `landlord.1@wyncrest.test` | Established - 1 property, 2 active tenants, an available listing |
| Landlord | `landlord.2@wyncrest.test` | Landlord of the owing tenant - a listing in review |
| Landlord | `landlord.3@wyncrest.test` | Smaller - 1 property, 1 active tenant, an available listing |
| Landlord | `landlord.4@wyncrest.test` | Listings-only (limited features), no tenants yet |
| Landlord | `landlord.empty@wyncrest.test` | **Empty-state** account - verified, full features, no properties |
| Tenant | `tenant.good1@wyncrest.test` | Good standing - paid up, **balance 0** |
| Tenant | `tenant.good2@wyncrest.test` | Good standing - paid up, **balance 0** |
| Tenant | `tenant.good3@wyncrest.test` | Good standing - paid up, **balance 0** |
| Tenant | `tenant.good4@wyncrest.test` | Good standing - paid up, **balance 0** |
| Tenant | `tenant.owing@wyncrest.test` | **Owes exactly one month** (GH₵2,500) via a single overdue rent entry |

**Inventory & money:**

- **4 properties / 10 units** across Accra, Osu, Kumasi & Tema.
- **10 listings**: 3 `active` (browse surface), 1 `pending_review` (admin
  moderation queue), 1 `draft`, and 5 `inactive` (the off-market listings on
  occupied units).
- **5 active leases** (one per occupied unit) - 4 good-standing + 1 owing.
- **An immutable, mathematically-consistent ledger** built through the real
  `LedgerService`: each lease has a per-month paid history; the four good tenants
  are paid to **zero**, the owing tenant has every prior month paid and the latest
  month left **overdue and unpaid** so the balance equals exactly one month's
  rent. Payments are stored as negative entries so balances stay derivable
  (`PaymentService::getTenantBalance`). **No late fee is invented** - late fees
  come from the real overdue-processing rules, not the seeder.
- **Supporting data, all tied to real state:** verification records (every demo
  account is verified), per-landlord feature gates (full/limited), approved
  application histories + 2 live applicants, one maintenance request per active
  lease, a few reviews (approved/pending/rejected + responses), notifications that
  each map to a real seeded event, saved listings, email logs, media metadata, and
  privileged audit-log activity.

The single, deterministic source of truth is
[`database/seeders/Dev/SeedCatalog.php`](../database/seeders/Dev/SeedCatalog.php).

## Verifying truth

`php artisan wyncrest:seed:verify` asserts the exact counts, the expected listing
statuses, ledger consistency (payments negative & linked, obligations positive,
no invented late fees, every balance re-derivable), and the standing split
(exactly 4 good + 1 owing exactly one month). It exits non-zero on any failure, so
it doubles as a CI smoke check.

## Architecture

```
DatabaseSeeder            ← entry point; resolves mode
├── ProductionSeeder      ← reference data + optional env bootstrap admin (idempotent)
│   └── ReferenceDataSeeder   (feature definitions - shared, idempotent)
└── DevelopmentSeeder     ← orchestrates the dev world (refuses to run in prod)
    ├── ReferenceDataSeeder
    └── Dev\…  UserSeeder, VerificationSeeder, FeatureGateSeeder, PropertySeeder,
               ListingSeeder, ApplicationSeeder, ContractSeeder, LedgerSeeder,
               MaintenanceSeeder, ReviewSeeder, NotificationSeeder,
               EngagementSeeder, AuditSeeder
```

Each focused sub-seeder owns one slice and reads its prerequisites back from the
database, so the pipeline is modular yet coherent.

## Resetting safely

- **Development:** `php artisan migrate:fresh --seed` (or `./dev.sh`) drops and
  rebuilds everything. `./dev.sh` additionally **guards** the reset: it refuses to
  wipe anything but a local SQLite database (override with
  `WYNCREST_ALLOW_NONLOCAL_RESET=1`) and never runs when `APP_ENV=production`.
- **Production:** never run destructive resets. `ProductionSeeder` is fully
  idempotent (`updateOrCreate` / `firstOrCreate`) - re-running it never duplicates
  rows or overwrites existing data.

## Known limitations

- **Media binaries are not seeded.** `EngagementSeeder` creates `media_assets`
  **metadata** rows (so galleries, counts and admin media views are populated),
  but the underlying image files do not exist - streaming a seeded asset will
  404. Upload real files through the gallery UI to back them.
- **No real Stripe/Twilio calls.** Payment entries use clearly-fake
  `pi_demo_seed_*` intent ids; no external services are contacted during seeding.
