# Database Seeding

Wyncrest ships a **mode-aware** seeding system with two clearly separated modes:

| Mode | Purpose | Creates |
|------|---------|---------|
| **development** | Rich, realistic local/demo data that exercises the whole platform end-to-end | Users, properties, units, listings, applications, contracts, ledger, notifications, reviews, maintenance, audit logs, … |
| **production** | Safe baseline initialization | Reference data (feature definitions) + an **optional** super admin from env. **Never** any demo people or money. |

## Quick start

```bash
# Local development demo (auto-resolves to development mode)
php artisan migrate:fresh --seed

# Explicit development mode
NEXUS_SEED_MODE=development php artisan migrate:fresh --seed

# Production-safe baseline ONLY (idempotent, safe to re-run)
NEXUS_SEED_MODE=production php artisan db:seed

# Verify the development graph + ledger consistency
php artisan nexus:seed:verify
```

## Mode resolution

`DatabaseSeeder` chooses a mode in this order:

1. `NEXUS_SEED_MODE` env (`development` | `production`), if set.
2. Otherwise: `APP_ENV=production` → **production**, else **development**.

## Environment variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `NEXUS_SEED_MODE` | *(auto)* | Force a mode. |
| `NEXUS_DEMO_PASSWORD` | `password` | Shared password for **all** demo accounts (dev only). |
| `NEXUS_DEMO_DOMAIN` | `wyncrest.test` | Reserved, non-routable email domain for demo accounts. |
| `NEXUS_DEMO_TENANTS` | `20` | Number of demo tenants. |
| `NEXUS_DEMO_LANDLORDS` | `10` | Number of demo landlords. |
| `NEXUS_DEMO_CURRENCY` | `GHS` | Currency for demo money (presented as GH₵). |
| `NEXUS_BOOTSTRAP_ADMIN_EMAIL` | - | Production super-admin email (optional). |
| `NEXUS_BOOTSTRAP_ADMIN_NAME` | - | Production super-admin name (optional). |
| `NEXUS_BOOTSTRAP_ADMIN_PASSWORD` | - | Production super-admin password (optional). |
| `NEXUS_ALLOW_DEV_SEED_IN_PROD` | `false` | Safety latch: must be `true` to run the dev seeder under `APP_ENV=production`. |

> Production seeding **never** creates demo data and **never** invents admin
> credentials. If the three `NEXUS_BOOTSTRAP_ADMIN_*` vars are not all present,
> the super admin is skipped with a logged warning.

## Demo accounts (development only)

All demo accounts use `@wyncrest.test` and the password `password` (override with
`NEXUS_DEMO_PASSWORD`). Highlights:

| Role | Email | Notes |
|------|-------|-------|
| Super admin | `admin@wyncrest.test` | Separate `admins` table |
| Support admin | `support@wyncrest.test` | Second super admin |
| Landlord | `landlord.verified@wyncrest.test` | Verified, **full** feature access, owns inventory |
| Landlord | `landlord.limited@wyncrest.test` | Verified, **listings-only** access |
| Landlord | `landlord.pending@wyncrest.test` | Verification pending, **no** features |
| Landlord | `landlord.suspended@wyncrest.test` | Suspended account (login refused) |
| Tenant | `tenant.showcase@wyncrest.test` | Active lease with **overdue + late fee + partial payment** (balance GH₵4,680) |
| Tenant | `tenant.active@wyncrest.test` | Active lease, paid up to date |
| Tenant | `tenant.former@wyncrest.test` | Terminated lease + a published review |
| Tenant | `tenant.suspended@wyncrest.test` | Suspended account (login refused) |
| Tenant | `tenant.blocked@wyncrest.test` | Blocked account (login refused) |

…plus 6 more landlords and 15 more tenants that drive the applications, saved
listings, verification queue and analytics. See
[`database/seeders/Dev/SeedCatalog.php`](../database/seeders/Dev/SeedCatalog.php)
for the complete, deterministic catalog.

## What the development seed creates

- **30 users** (20 tenants, 10 landlords) + 2 admins, verification and
  account-status variety, incl. suspended/blocked accounts.
- **10 properties** across Accra, Tema, Kumasi & Takoradi.
- **20 rental units**, each a **distinct type** (studio → penthouse → family
  compound, no clones).
- **20 listings** spanning every status (draft, pending_review, active,
  inactive, rejected, archived).
- **Applications** across every status (submitted, in_review, landlord_review,
  approved, rejected, withdrawn).
- **9 contracts** across the full lifecycle (draft, pending_tenant, active,
  terminated, expired).
- **An immutable, mathematically-consistent ledger**: paid history, overdue,
  late fees, partial payments, and current pending charges. Payments are stored
  as negative entries so balances stay derivable
  (`PaymentService::getTenantBalance`).
- **Notifications** across types, read/unread, and email/SMS delivery states.
- **Verification requests** (incl. a live admin review queue), **reviews**
  (approved/pending/rejected + landlord responses), **maintenance requests**
  (all statuses), **feature gates** (full/limited/none), **saved listings**,
  **email logs**, and **audit logs**.

## Architecture

```
DatabaseSeeder            ← entry point; resolves mode
├── ProductionSeeder      ← reference data + optional env super admin (idempotent)
│   └── ReferenceDataSeeder   (feature definitions, shared, idempotent)
└── DevelopmentSeeder     ← orchestrates the demo graph (refuses to run in prod)
    ├── ReferenceDataSeeder
    └── Dev\…  UserSeeder, VerificationSeeder, FeatureGateSeeder, PropertySeeder,
               ListingSeeder, ApplicationSeeder, ContractSeeder, LedgerSeeder,
               MaintenanceSeeder, ReviewSeeder, NotificationSeeder,
               EngagementSeeder, AuditSeeder
```

Each focused sub-seeder owns one slice and reads its prerequisites back from the
database, so the pipeline is modular yet coherent. The single source of truth for
the demo dataset is `database/seeders/Dev/SeedCatalog.php`.

## Resetting safely

- **Development:** `php artisan migrate:fresh --seed` drops and rebuilds
  everything. Order is deterministic and safe.
- **Production:** never run destructive resets. `ProductionSeeder` is fully
  idempotent (`updateOrCreate` / `firstOrCreate`), re-running it never
  duplicates rows or overwrites existing data.

## Known limitations

- **Media binaries are not seeded.** `EngagementSeeder` creates `media_assets`
  **metadata** rows (so galleries, counts and admin media views are populated),
  but the underlying image files do not exist, streaming a seeded asset will
  404. Upload real files through the gallery UI to back them.
- **No real Stripe/Twilio calls.** Payment entries use clearly-fake
  `pi_demo_seed_*` intent ids; no external services are contacted during seeding.
