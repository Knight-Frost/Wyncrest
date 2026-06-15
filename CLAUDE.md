# CLAUDE.md — Nexus Project Memory

> Permanent project memory for AI sessions and human maintainers. Read this first.
> Keep it up to date when architecture, conventions, or status change.

---

## 1. Project Name & Purpose

**Nexus** is a full-stack **property rental management platform**. It manages the
complete lifecycle of rental properties in one system: property/unit catalogues,
public listings with admin moderation, landlord⇄tenant contracts, an immutable
financial ledger, Stripe-backed rent payments, multi-channel notifications
(in-app / email / SMS), and analytics.

## 2. Product Vision

A single, trustworthy system of record for landlords, tenants, and platform
administrators. Financial correctness, auditability, and least-privilege access
are first-class concerns — the ledger is **immutable by design** and every
privileged action is written to an append-only audit log.

## 3. Current Project Status

| Area | Status |
|------|--------|
| Backend (Laravel 12) | **Mature & passing** — 258 tests green, full domain implemented |
| RBAC / Auth | Implemented (Sanctum tokens, dual User/Admin model, middleware + policies) |
| Payments / Webhooks | Implemented (Stripe PaymentIntents + signature-verified webhook) |
| Notifications | Implemented (in-app, email, SMS, digests, preferences) |
| Analytics / Caching | Implemented (scoped, selectively invalidated, async jobs) |
| Frontend (`frontend/`) | **Being built** — React 18 + TS + Vite + Tailwind v4 SPA against the API |
| Docs | README + `docs/` exist; expanded during completion work |

See **Known Unfinished Work** (§22) for the live punch list.

## 4. Tech Stack

- **Backend:** PHP 8.2+ (runs on 8.3), Laravel 12, Laravel Sanctum 4 (API tokens)
- **Payments:** `stripe/stripe-php` ^19 · **SMS:** `twilio/sdk` ^8
- **DB:** SQLite by default (dev/test); MySQL/Postgres supported via config
- **Queue / Cache / Session:** database driver by default (Redis-ready)
- **Frontend:** React 18, React Router 7, TypeScript 5, Vite, Tailwind CSS v4, Axios
- **Tooling:** Pint (PHP formatter), PHPUnit 11, ESLint, Vite

## 5. Backend Architecture

Layered Laravel app with deliberate separation of concerns:

```
Route (middleware: auth:sanctum + role guard + rate limit)
  → FormRequest (validation + authorize())
    → Controller (thin; orchestration only)
      → Service (business logic, transactions)        ← app/Services
        → Model (Eloquent; enums, scopes, invariants) ← app/Models
      → Policy (per-model authorization)              ← app/Policies
  → Observer / Event / Listener (side effects: audit, notifications, cache)
```

- **Controllers are thin.** Business rules live in `app/Services/*`.
- **Validation** lives in `app/Http/Requests/*` FormRequests (never inline).
- **Authorization** is enforced in three places: route middleware (coarse role
  gate), FormRequest `authorize()` / Policy (per-resource ownership), and
  service-level checks for sensitive operations.
- **Side effects** (audit logging, notifications, cache invalidation) are wired
  through Observers (`app/Observers`), Events (`app/Events`), and Listeners.

## 6. Frontend Architecture

- Standalone **SPA in `frontend/`** (NOT the Laravel `resources/` Blade layer,
  which only serves a welcome page).
- Talks to the backend over the JSON API using **Bearer tokens stored client-side**
  (token-based Sanctum, not cookie/SPA mode — see `bootstrap/app.php:31-35`).
- Structure (target): `src/{api,components,context,hooks,pages,routes,lib,types}`.
- Role-aware routing: public, tenant, landlord, and admin areas guarded by an
  auth context + route guards. **UI authorization is cosmetic only — the API is
  the source of truth.**

## 7. Database Overview

25 migrations in `database/migrations` (run in filename order). Highlights:

- **Money is stored in integer cents** (`amount_cents`, `rent_amount`) — never floats.
- **`contracts` and `ledger_entries` use UUID primary keys** (prevents ID
  enumeration / IDOR on financial records).
- **`ledger_entries` is immutable** — `update()`/`delete()` throw; status changes
  only via `transitionStatus()`; corrections are compensating entries.
- **`audit_logs` is append-only** (`UPDATED_AT = null`).
- Soft deletes on user-facing models (users, properties, units, listings).
- Foreign keys are indexed; composite indexes on common query paths; unique
  constraints on `(property_id, unit_number)`, `(user_id, listing_id)`,
  `(landlord_id, feature_id)`, `(user_id, notification_type)`.

## 8. Key Folders

| Path | Contents |
|------|----------|
| `app/Models` | Eloquent models (one class per file — PSR-4) |
| `app/Http/Controllers/{Admin,Landlord,Tenant,Analytics,Public}` | Thin controllers grouped by audience |
| `app/Http/Requests` | FormRequest validation classes |
| `app/Http/Middleware` | Role guards, rate limiting, security headers, metrics |
| `app/Policies` | Per-model authorization |
| `app/Services` | Business logic (ledger, payments, listings, notifications, analytics, audit, features) |
| `app/Enums` | Type-safe domain values (statuses, types, cycles) |
| `app/Events` / `app/Listeners` / `app/Observers` | Side-effect wiring |
| `app/Console/Commands` | Scheduled jobs (rent generation, overdue marking, digests) |
| `app/Support/Cache` | Analytics cache keys, metadata, selective invalidation |
| `database/{migrations,factories,seeders}` | Schema, test factories, demo seeder |
| `routes/api.php` `api_contracts.php` `api_ledger.php` | API route definitions |
| `tests/{Feature,Unit,Load}` | PHPUnit feature/unit tests + k6 load scripts |
| `frontend/` | React/TS SPA (the real user-facing app) |
| `docs/` | Architecture, API examples, deployment notes |

## 9. Main Domain Entities

`User` (tenant/landlord) · `Admin` (separate table) · `Property` → `Unit` →
`Listing` (→ `ListingPhoto`) · `Contract` · `LedgerEntry` · `Notification` /
`NotificationPreference` · `Feature` / `LandlordFeature` · `AuditLog` ·
`EmailLog` · `Conversation` / `Message` (schema only, no UI yet).

**Core workflow:** Landlord creates Property → Unit → Listing (DRAFT) → submits →
Admin approves (ACTIVE/published). Landlord drafts a Contract for a tenant →
sends → tenant accepts (ACTIVE) → ledger auto-generates rent entries → tenant
pays via Stripe → webhook marks the entry PAID → notifications fire. Overdue
entries get late fees. Either party (or an admin) can terminate.

## 10. Authentication Model

- **Laravel Sanctum personal access tokens** (`Authorization: Bearer <token>`).
- **Two authenticatable models:** `User` (`user_type` = tenant|landlord) and
  `Admin` (separate `admins` table, `is_super_admin`). Login checks Admin first,
  then User (`AuthController::login`).
- Endpoints: `POST /register`, `POST /login`, `GET /user`, `POST /logout`.
- Login is **rate-limited** (5 attempts/min per email+IP, then lockout).
- Accounts are gated on `is_active` and `suspended_at`.
- Token lifetime via `SANCTUM_TOKEN_EXPIRATION` (default 1440 min).
- Passwords hashed via Laravel `hashed` cast (bcrypt, `BCRYPT_ROUNDS=12`).

## 11. RBAC Model

Three roles + super-admin: **Tenant**, **Landlord**, **Admin** (all admins are
super-admins in the current phase).

- **Coarse gate (route middleware):** `tenant`, `landlord`, `admin`,
  `admin.or.landlord` (aliases in `bootstrap/app.php`).
- **Fine gate (Policies):** ownership + state checks, e.g. a landlord may only
  edit *their own* DRAFT listing; contracts viewable only by their landlord or
  tenant; financial records are view-only.
- **Service gate:** sensitive operations (termination, feature toggling, late
  fees) re-check authorization and write audit logs.

## 12. Permissions & Access Rules (summary)

| Area | Public | Tenant | Landlord | Admin |
|------|:------:|:------:|:--------:|:-----:|
| Browse listings | ✅ | ✅ | ✅ | ✅ |
| Save listings | — | ✅ | — | — |
| Manage properties/units/listings | — | — | ✅ (own) | — |
| Listing moderation (approve/reject) | — | — | — | ✅ |
| Create/send contracts | — | — | ✅ (own) | — |
| Accept contracts | — | ✅ (own) | — | — |
| Terminate contracts | — | ✅ (own) | ✅ (own) | ✅ (any) |
| View ledger | — | ✅ (own) | ✅ (own) | ✅ (all) |
| Initiate payment | — | ✅ (own) | — | — |
| Generate late fees | — | — | — | ✅ |
| Feature flags per landlord | — | — | — | ✅ |
| Audit logs | — | — | — | ✅ |
| Analytics | — | scoped | scoped | platform-wide |
| Metrics | — | — | ✅ | ✅ |

## 13. API Structure

- Base path: `/api/*` (JSON only). Health check at `/up`.
- Route files: `routes/api.php` (auth, public, tenant, landlord, admin,
  notifications, analytics, metrics, webhooks), plus `api_contracts.php` and
  `api_ledger.php` for contract/ledger sub-domains.
- Guards: `auth:sanctum` + a role middleware + `rate.limit.role`.
- Webhook: `POST /webhooks/stripe` — **no auth**, verified by Stripe signature.
- **Response convention:** JSON. (Completion work standardizes the envelope —
  see §15 / Known Work.)

## 14. Security Rules (OWASP-aligned)

- **Never trust the client.** All authorization is enforced server-side.
- Validation on every write via FormRequests; **mass assignment** controlled by
  explicit `$fillable` whitelists.
- **SQL injection:** use Eloquent / query bindings only — no raw interpolation.
- **XSS:** API returns JSON; the SPA must escape output (React does by default).
- **Secrets** live in `.env` only (git-ignored). Never commit `.env`, keys, or
  `auth.json`.
- **Stripe webhooks** must verify the signature and reject placeholder secrets.
- **Payments are idempotent** via `stripe_payment_intent_id` on the ledger entry.
- **Errors:** `APP_DEBUG=false` in production; no stack traces in API responses.
- **Security headers** added to all API responses (`SecurityHeaders` middleware).
- **Rate limiting** is role-aware (`RateLimitByRole`).
- **Audit logging** for all privileged/sensitive actions.
- IDOR mitigated by UUID PKs on financial records + ownership policies.

## 15. Validation Rules

- One FormRequest per write action (`app/Http/Requests`). `authorize()` performs
  the policy/ownership check; `rules()` performs field validation.
- Money is validated as positive integers (cents). Dates validated for ordering
  (start < end). Enums validated against `Enum` rules.

## 16. Caching Strategy

- Analytics responses are cached with **scoped keys** (`app/Support/Cache`).
- **Selective invalidation:** a write only invalidates cache entries whose
  metadata overlaps (same tenant/property/date range) — see
  `AnalyticsCacheInvalidator`.
- Large invalidations (>100 keys) are **dispatched to a queue**
  (`InvalidateAnalyticsCacheJob`, queue `analytics-invalidation`, tries=3).
- Cache events are emitted for observability.

## 17. Queue & Job Strategy

- Default queue connection: `database` (`sync` in tests).
- Scheduled commands: rent generation, overdue marking, notification delivery
  (email/SMS), daily/weekly digests, queue-health validation.
- Notification delivery and cache invalidation run as queued work.

## 18. Testing Strategy

- **PHPUnit 11**, SQLite `:memory:` with `RefreshDatabase`.
- `tests/Feature` covers auth, RBAC/policies, contract & ledger workflows,
  payments, notifications (in-app/email/SMS/digest), analytics, caching, rate
  limiting, metrics. `tests/Load` holds k6 scripts.
- Run: `php artisan test` (or `composer test`). **Keep the suite green** —
  never commit with failing tests.
- Coverage focus areas for completion: payments/webhook edge cases, admin
  authorization, IDOR/privilege-escalation negatives.

## 19. Local Setup

```bash
# Backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite          # sqlite default
php artisan migrate
php artisan db:seed                      # demo data (Phase1Seeder)

# Frontend
cd frontend && npm install && npm run dev   # Vite dev server on :5173
```

One-shot dev (server + queue + logs + vite): `composer run dev`.

## 20. Build Instructions

- Backend has no build step (PHP). Production: `php artisan config:cache route:cache`.
- Frontend: `cd frontend && npm run build` → static assets in `frontend/dist`.

## 21. Deployment Notes

- Set `APP_ENV=production`, `APP_DEBUG=false`, real `APP_KEY`, HTTPS, secure
  session cookies. Provide real Stripe/Twilio credentials. See the production
  checklist at the bottom of `.env.example` and `docs/DEPLOYMENT.md`.
- Configure a real queue worker and scheduler (`php artisan schedule:run` cron).
- Point `CORS_ALLOWED_ORIGINS` / `SANCTUM_STATEFUL_DOMAINS` at the deployed SPA.

## 22. Known Risks

- All admins are super-admins (granular admin RBAC is a future phase).
- SQLite is the default — production should use MySQL/Postgres + Redis.
- `Conversation`/`Message` exist as schema only (no endpoints/UI).
- Sensitive PII is not field-encrypted at rest.

## 23. Known Unfinished Work (live punch list)

- [ ] Frontend SPA — build the real app (auth, role dashboards, core flows).
- [ ] Expand payment/webhook + admin-authorization + IDOR test coverage.
- [ ] Standardize API response envelope across controllers.
- [ ] Wire remaining notification types (late fee added, contract signed/terminated).

## 24. Coding Standards

- **PHP:** PSR-12 via **Laravel Pint** (`./vendor/bin/pint`). One class per file,
  PSR-4 paths. Thin controllers, fat services. Enums for domain constants.
  No business data hardcoded — use config/`.env`.
- **TypeScript/React:** ESLint clean, typed props/responses, function components +
  hooks, no `any` for API payloads, components small and composable.
- **Naming:** descriptive and consistent with the surrounding file's idiom.

## 25. Documentation Standards

- Keep this file, `README.md`, and `docs/*` in sync with reality.
- Document any deliberate hardcoded value or deviation inline with a `// why:`.
- API changes update `docs/API_EXAMPLES.md`.

## 26. Git & Branch Rules

- Work on **`main`** unless explicitly told otherwise. No branch clutter.
- **Never commit** secrets, `.env`, `vendor/`, `node_modules/`, build output,
  logs, `*.sqlite`, or `backups/`.
- Commits are logical and professional; keep the suite green per commit.

## 27. How Future Sessions Should Continue

1. Read this file. Run `php artisan test` to confirm the baseline is green.
2. Pick the next item from §23. Prefer the highest item that blocks build/run/
   test/security.
3. Verify in the codebase before assuming — this file summarizes, the code rules.
4. Add/update tests for any behavior you change. Run Pint. Keep docs in sync.

## 28. What Must NOT Change Without Explicit Approval

- **Ledger immutability** and the audit-log append-only contract.
- **Money-in-cents** representation and UUID PKs on `contracts`/`ledger_entries`.
- **Stripe webhook signature verification** and payment idempotency keys.
- **Authorization model** (server-side enforcement; UI is never the gate).
- Anything touching real credentials, payment capture, or destructive data ops.
</content>
</invoke>
