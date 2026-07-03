# CLAUDE.md: Nexus Project Memory

> Permanent project memory for AI sessions and human maintainers. Read this first.
> Keep it up to date when architecture, conventions, or status change.

---

## 0. Extended Project Knowledge System

**Before doing any Nexus work, read `.internal/Claude_Project_Knowledge/README.md` first.**

A dedicated knowledge system lives in `.internal/Claude_Project_Knowledge/` (moved
under `.internal/` during the repo cleanup pass so AI working notes don't sit
beside application source). It was built by studying six learning archives and
contains:

- `DESIGN.md`: Nexus visual identity in design token format (colors, typography, components)
- `nexus_design_memory.md`: Design direction, visual rules, role-specific UX
- `ui_ux_quality_bar.md`: What "good UI" means for Nexus specifically
- `frontend_implementation_rules.md`: TypeScript/React rules
- `backend_implementation_rules.md`: Laravel/PHP rules
- `claude_behavior_rules.md`: How Claude must behave in this project
- `reporting_style.md`: Required format for every completion report
- `lessons_to_apply.md`: Lessons extracted from study materials
- `lessons_to_ignore.md`: What was studied but does not apply to Nexus
- `study_index.md`: Proof of what was inspected and when
- `agent_workflow.md`: When and how to use multi-agent patterns
- `memory_workflow.md`: How to use and update this knowledge system

**The `Claude_Study_Guide/` folder contains the original zip archives.**
It is read-only. Never modify, rename, or delete files inside it.

### Mandatory Behavior for All Claude Sessions

- Read `.internal/Claude_Project_Knowledge/README.md` before doing Nexus work
- Read design files before making any UI or frontend changes
- Format all money as GH₵ using the format utility, always, no exceptions
- Avoid cramped layouts: minimum 24px card padding, 40px section spacing
- Use a display font with personality for headings, not Inter alone
- Prefer spacious, personalized, role-specific interfaces
- Never produce a generic SaaS dashboard look
- Report completions using the format in `reporting_style.md`
- Keep `.internal/Claude_Project_Knowledge/` updated when new lessons are learned
- Project memory lives in the repository, not just in this chat

### Approved Visual Direction: White Liquid Glass (Confirmed June 2026, Homecrest rebrand)

> **SUPERSEDES "Warm Paper & Oxblood."** During the Nexus→Homecrest rebrand the user reviewed the warm-paper editorial direction and **chose to pivot** to a **white background + liquid-glass** identity: open, airy, premium, enterprise-grade. This reverses the earlier warm-paper approval *intentionally and explicitly*: do NOT "correct" the codebase back to warm paper. Warm paper, gold, and obsidian/mint are all **dead directions** now. The token set is being migrated in `.internal/Claude_Project_Knowledge/DESIGN.md`.

| Decision | Status |
|----------|--------|
| Page background: **cool white** `#FFFFFF` / `#FBFCFD` (architectural light, never warm paper) | **APPROVED** |
| Cards / surfaces: **liquid glass**: `rgba(255,255,255,0.6)` + `backdrop-blur`, hairline `rgba(15,23,42,0.08)`, soft shadow `0 8px 32px rgba(15,23,42,0.06)` | **APPROVED** |
| Accent: **ink-teal** primary, **oxblood** rationed (danger/punctuation only) | **APPROVED** |
| Display font: **Fraunces** (serif, characterful), headings, hero, numerals | **KEPT** |
| Body font: **Hanken Grotesque** · Mono (eyebrows/labels): **IBM Plex Mono** | **KEPT** |
| Money: **ink-teal** (`#075865`), never gold/brown | **KEPT** |
| User-selectable **accent color** (curated palette, per-user, accessible) | **PLANNED (Phase 14)** |
| Warm bone paper `#F3EEE6` / `#FBF8F2` surfaces (old Warm Paper & Oxblood) | **RETIRED (was prior approved direction)** |
| Gold / bronze / brown / mustard · mint / jade / obsidian-dark sidebar | **HARD REJECTED** |
| Purple / violet / lavender · generic SaaS blue · AI gradient blobs · low-contrast gray soup | **HARD REJECTED** |

**The look is glass over white architectural light, not SaaS sludge:** translucent layered surfaces, soft borders, gentle blur where performant, strong text contrast, precise spacing, subtle motion that respects `prefers-reduced-motion`. Tasteful, never an overdone blurry mess. Implement tokens in DESIGN.md.

> **Brand is now CONFIG-DRIVEN, current name: "Wyncrest" (June 2026).** The product
> was Nexus → Homecrest → **Wyncrest**, and the name may change again. There is a **centralized
> brand layer** so future renames are a config/env change, NOT a code sweep:
> - **Frontend:** `frontend/src/config/brand.ts` (`brand.*` + `pageTitle()`), reads `VITE_*` env with
>   safe defaults. The W-crest logo (`components/brand/Logo.tsx`) takes its initial from `brand.brandInitial`.
>   `index.html` title/meta use `%BRAND_*%` placeholders injected by a vite plugin in `vite.config.js`.
> - **Backend:** `config/brand.php` (`config('brand.display_name'|'short_name'|...)`), reads `BRAND_*` env.
>   All mailables/Blade emails/SMS/console output read brand from config, never hardcode the app name.
> - To rename: change env (or the defaults in those two files) + optionally the logo asset. See README "Branding".
> **Do NOT hardcode the app name in pages, emails, or notifications.** Internal technical identifiers
> (`nexus.*` localStorage/cache keys, `nexus_` Sanctum prefix, `NexusCard`, `nvx-`/`nx-`/`--nexus-*` CSS,
> `nexus-frontend` package) are deliberately retained to avoid breakage. Remaining "Nexus/Homecrest" in
> code COMMENTS only, not user-facing.
>
> **Other stale facts corrected:** `Conversation`/`Message` messaging is **fully built** (endpoints +
> `MessagesPage.tsx`), not "schema only". Backend baseline is **688 green** (deterministic).

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
are first-class concerns; the ledger is **immutable by design** and every
privileged action is written to an append-only audit log.

## 3. Current Project Status

| Area | Status |
|------|--------|
| Backend (Laravel 12) | **Mature & passing**: 688 tests green (deterministic), full domain implemented |
| RBAC / Auth | Implemented + proven (Sanctum tokens, dual User/Admin model, middleware + policies, IDOR/escalation test suite) |
| Payments / Webhooks | Implemented (Stripe PaymentIntents + signature-verified webhook, idempotent) |
| Notifications | Implemented (in-app, email, SMS, digests, preferences) |
| Analytics / Caching | Implemented (scoped, selectively invalidated, async jobs) |
| Frontend (`frontend/`) | **Built & truthful**: React 18 + TS + Vite + Tailwind v4 SPA, role-aware, integrated with the API (tsc + eslint + build clean). **All three portals (tenant/landlord/admin) now run on 100% real backend data, no mock files remain.** |
| Security | OWASP audit complete (no high/critical); CSP + HSTS added; `SECURITY.md` |
| Docs | README, `CLAUDE.md`, `docs/{API_REFERENCE,AUTHORIZATION,ARCHITECTURE,DEPLOYMENT}.md` |

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
  (token-based Sanctum, not cookie/SPA mode; see `bootstrap/app.php:31-35`).
- Structure (target): `src/{api,components,context,hooks,pages,routes,lib,types}`.
- Role-aware routing: public, tenant, landlord, and admin areas guarded by an
  auth context + route guards. **UI authorization is cosmetic only; the API is
  the source of truth.**

## 7. Database Overview

25 migrations in `database/migrations` (run in filename order). Highlights:

- **Money is stored in integer cents** (`amount_cents`, `rent_amount`), never floats.
- **`contracts` and `ledger_entries` use UUID primary keys** (prevents ID
  enumeration / IDOR on financial records).
- **`ledger_entries` is immutable**: `update()`/`delete()` throw; status changes
  only via `transitionStatus()`; corrections are compensating entries.
- **`audit_logs` is append-only** (`UPDATED_AT = null`).
- Soft deletes on user-facing models (users, properties, units, listings).
- Foreign keys are indexed; composite indexes on common query paths; unique
  constraints on `(property_id, unit_number)`, `(user_id, listing_id)`,
  `(landlord_id, feature_id)`, `(user_id, notification_type)`.

## 8. Key Folders

| Path | Contents |
|------|----------|
| `app/Models` | Eloquent models (one class per file, PSR-4) |
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

**Two intentionally ISOLATED mechanisms** (see `docs/ADMIN_AUTH.md`):

- **Tenant / Landlord — Sanctum bearer tokens** (`Authorization: Bearer <token>`,
  stored client-side). Endpoints: `POST /register`, `POST /login`, `GET /user`,
  `POST /logout`. Guard: `auth:sanctum`.
- **Admin console — first-party HttpOnly COOKIE SESSION** on the native `admin`
  guard (`config/auth.php`). No bearer token is ever issued to or stored by the
  admin SPA, so client state can never diverge from the real authenticated admin.
  Endpoints (CSRF-protected via `GET /sanctum/csrf-cookie`): `POST /admin/login`,
  `GET /admin/me` (**source of truth**), `POST /admin/logout`, `POST /admin/password`.
  Admin routes run on `['web','auth:admin','auth.session','admin','rate.limit.role']`.
  The `admin` guard is deliberately absent from `config('sanctum.guard')` so an
  admin cookie can never authenticate the shared bearer pipeline. Admins sign in
  at **`/admin/login`** (a separate SPA surface); `/login` no longer accepts admins.
- **`401` vs `403` are distinct:** no admin session → `401`; authenticated admin
  lacking a capability → `403`. **Requires HTTPS in production** (Secure cookies);
  the HTTP-only EC2 demo needs TLS before admin login works there.
- **Two authenticatable models:** `User` (`user_type` = tenant|landlord) and
  `Admin` (separate `admins` table, `is_super_admin`).
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
| Save listings | - | ✅ | - | - |
| Manage properties/units/listings | - | - | ✅ (own) | - |
| Listing moderation (approve/reject) | - | - | - | ✅ |
| Create/send contracts | - | - | ✅ (own) | - |
| Accept contracts | - | ✅ (own) | - | - |
| Terminate contracts | - | ✅ (own) | ✅ (own) | ✅ (any) |
| View ledger | - | ✅ (own) | ✅ (own) | ✅ (all) |
| Initiate payment | - | ✅ (own) | - | - |
| Generate late fees | - | - | - | ✅ |
| Feature flags per landlord | - | - | - | ✅ |
| Audit logs | - | - | - | ✅ |
| Analytics | - | scoped | scoped | platform-wide |
| Metrics | - | - | ✅ | ✅ |

## 13. API Structure

- Base path: `/api/*` (JSON only). Health check at `/up`.
- Route files: `routes/api.php` (auth, public, tenant, landlord, admin,
  notifications, analytics, metrics, webhooks), plus `api_contracts.php` and
  `api_ledger.php` for contract/ledger sub-domains.
- Guards: `auth:sanctum` + a role middleware + `rate.limit.role`.
- Webhook: `POST /webhooks/stripe`: **no auth**, verified by Stripe signature.
- **Response convention:** JSON. (Completion work standardizes the envelope,
  see §15 / Known Work.)

## 14. Security Rules (OWASP-aligned)

- **Never trust the client.** All authorization is enforced server-side.
- Validation on every write via FormRequests; **mass assignment** controlled by
  explicit `$fillable` whitelists.
- **SQL injection:** use Eloquent / query bindings only, no raw interpolation.
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
  metadata overlaps (same tenant/property/date range); see
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
- Run: `php artisan test` (or `composer test`). **Keep the suite green**:
  never commit with failing tests.
- Coverage focus areas for completion: payments/webhook edge cases, admin
  authorization, IDOR/privilege-escalation negatives.

## 19. Local Setup

```bash
# One-command runner (resets DB, seeds, boots API + queue + SPA):
./dev.sh            # development world (API :8000, SPA dev :5173)
./dev.sh --prod     # production preview (built SPA :3000, only a bootstrap admin)
# ./dev.sh --help   # all flags (also --no-reset). Reset is guarded to local sqlite.

# Backend (manual)
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite          # sqlite default
php artisan migrate:fresh --seed         # mode-aware seed (controlled dev world: 1 admin/5 LL/5 T)
# production-safe baseline only: WYNCREST_SEED_MODE=production php artisan db:seed
# verify the dev world + ledger: php artisan wyncrest:seed:verify
# Seeding architecture + demo accounts: docs/SEEDING.md  (env: WYNCREST_*, legacy NEXUS_* still works)

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

### 21a. Live EC2 Demo Deployment (set up June 2026)

A **demo/staging** instance is live. This is a showcase box, **not** hardened production
(see caveats below); do not treat it as the production environment.

- **URL:** http://18.216.245.190; **HTTP only, no domain/TLS.** (So Stripe webhooks
  and secure cookies do NOT work; auth is Bearer-token so login works fine.)
- **Host:** `ec2-user@18.216.245.190` · region us-east-2 (Ohio) · instance
  `i-0f4e0733ca1f463bc` · t3.micro (912 MB RAM) · Amazon Linux 2023.
  SSH key: `~/Downloads/Wyncrest.pem` (must be `chmod 600`). Ports 22 + 80 open in the SG.
- **App dir:** `/var/www/wyncrest` (owner `ec2-user:apache`; `storage/`,
  `bootstrap/cache/`, `database/` are `775` group-writable so php-fpm, user `apache`, can write).
- **Stack:** PHP 8.3 + php-fpm (socket `/run/php-fpm/www.sock`,
  `listen.acl_users=apache,nginx`) · nginx (`/etc/nginx/conf.d/wyncrest.conf`) · composer.
  A **2 GB swapfile** (`/swapfile`) was added so `composer install` doesn't OOM on 912 MB.
  Both services run under **systemd** (`enable --now`), so they survive reboots and are
  independent of any SSH session (closing your laptop terminal does NOT stop the site).
- **Serving model, single origin:** nginx serves the SPA build (`frontend/dist`) at `/`
  with an `index.html` fallback, and proxies the backend prefixes **`/api`, `/sanctum`,
  `/storage`, `/up`** to Laravel's `public/index.php`. Because it's same-origin, the SPA
  needs **no** API-URL env (`lib/api.ts` defaults `baseURL` to `/api`) and there's no CORS.
- **Data layer:** SQLite at `/var/www/wyncrest/database/database.sqlite`;
  `QUEUE_CONNECTION=sync` (no worker needed), `CACHE`/`SESSION=database`,
  `APP_ENV=production`, `APP_DEBUG=false`.
- **Demo data:** the dev world is seeded **in a production env** via
  `WYNCREST_SEED_MODE=development` + `WYNCREST_ALLOW_DEV_SEED_IN_PROD=true` in `.env`
  (legacy `NEXUS_*` names still work). ⚠️ The demo seeders depend on **faker (a
  `require-dev` package)**: `composer install --no-dev` breaks seeding with `Call to
  undefined function fake()`, so the **full** dependency set is installed on this box.
  A genuine production deploy would keep `--no-dev` and seed only the safe baseline
  (`WYNCREST_SEED_MODE=production`, which needs no faker).
  Demo logins (password `password`): `admin@wyncrest.test` (super admin),
  `reviewer@wyncrest.test` (scoped admin, granted review_verifications/
  moderate_listings/moderate_reviews/view_audit), `landlord.1@wyncrest.test`
  … `landlord.4@wyncrest.test`, `landlord.empty@wyncrest.test`, `tenant.good1@wyncrest.test`
  … `tenant.good4@wyncrest.test`, `tenant.owing@wyncrest.test` (owes one month).
  A third admin, `pending.admin@wyncrest.test`, exists as an unaccepted invite
  (no usable password) to exercise the invite-lifecycle UI.
- **Redeploy after code changes:** build the frontend locally
  (`cd frontend && npm run build`), `rsync -az --delete` the tree to
  `/var/www/wyncrest` (exclude `.git node_modules vendor .env *.sqlite Claude_Study_Guide .internal`),
  then on the server: `composer install` (if deps changed) → `php artisan migrate` (or
  `migrate:fresh --seed --force` to reset demo data) → `php artisan config:cache route:cache`
  → `sudo systemctl reload php-fpm nginx`.
- **Cost note:** a running instance bills per hour regardless of traffic. Use EC2 console
  **Stop** to pause compute cost (keeps the EBS disk + setup); restarting yields a **new
  public IP** unless an Elastic IP is attached. Local AWS CLI is **not** configured;
  security-group / instance changes must be done in the AWS console.
- **Caveats (not done: this is a demo):** no TLS/domain, no real Stripe/Twilio/Google
  creds (those features stay gated off), no queue worker or scheduler cron, dev
  dependencies present, SQLite rather than MySQL/Postgres.

## 22. Known Risks

- All admins are super-admins (granular admin RBAC is a future phase).
- SQLite is the default; production should use MySQL/Postgres + Redis.
- ~~`Conversation`/`Message` exist as schema only~~ **Correction:** messaging is fully built
  (endpoints + `MessagesPage.tsx`); this old note was stale.
- Sensitive PII is not field-encrypted at rest.

## 23. Known Unfinished Work (live punch list)

Done in the completion pass: frontend SPA built; payment/webhook/admin/IDOR test
coverage expanded (287 tests); security audit + hardening; full docs set.

Done in the Landlord/Admin truthfulness pass (June 2026): redesigned + wired the
Landlord and Admin portals to 100% real data using the shared design system.
Deleted all frontend mock (`lib/mockData.ts`, `pages/admin/adminMockData.ts`, dead
admin components). Removed invented Admin "Disputes"/"Risk" stubs. New backend:
`GET /landlord/dashboard`, `GET /admin/users` (+ show/suspend/activate, audited),
and extended `GET /admin/dashboard` with real platform aggregates, all tested
(suite now **400 tests**). Wired previously-dead landlord screens (Applicants→real
applications/decide; Tenants→derived from contracts+ledger; Maintenance & Analytics
built on existing endpoints; Properties/Listings dead buttons fixed; fake 76%
occupancy replaced with real unit statuses).

Remaining / future work:
- [ ] Standardize the API response envelope across controllers. *Deliberately
  deferred*: the SPA is the only consumer and the typed client already adapts
  per-endpoint; refactoring would churn the green suite for little gain. Revisit
  if a second/external consumer appears.
- [x] ~~Wire remaining notification types (late fee added, contract signed/terminated).~~ **DONE (Phase 2, Homecrest pass):** contract signed/terminated, account suspended/reactivated, late-fee, and listing approved/rejected now create real in-app notifications; the 4 dispatched-but-orphaned listing/user events are registered; `UserCreated` fires on registration. Notification money is now GH₵ (was `$`).
- [~] Media storage + upload: **backend DONE (Phase 3)**: new `media_assets` (UUID PK,
  polymorphic, public/private disks, checksum, sort/alt/caption, soft-delete), `MediaService`,
  `MediaAssetPolicy`, upload/stream/reorder/delete routes for property/unit/listing galleries +
  avatars, S3/R2-ready via `config/media.php`. Coexists with legacy `ListingPhoto` (consolidation
  later). **Frontend upload UI still pending**: wired in the liquid-glass redesign phase.
- [x] ~~Real account verification~~ **DONE (Phase 4):** `verification_requests` (UUID) +
  `VerificationStatus` enum + `VerificationService` (submit/approve/reject/needs-info),
  admin review queue, document-backed, notifications + `IdentityVerified` wired. **Hard gating**
  (server-side 403): landlords must verify before submitting a listing; tenants before applying.
- [x] ~~Account governance~~ **DONE (Phase 5):** `AccountStatus` enum (active/suspended/blocked/
  archived); admin block/archive (+ existing suspend/activate) with reason+audit+notify; middleware
  + login reject blocked/archived; **frontend self-delete removed** (no self-delete endpoint exists).
- [x] ~~Google sign-in + auth hardening~~ **DONE (Phase 6):** env-gated Socialite Google OAuth
  (admin-email refusal, safe email linking), password reset (broker, no-leak), email verification,
  all SPA-friendly + tested. Frontend: Google button (config-gated), forgot/reset/verify pages.
- [x] ~~Rating/review system~~ **DONE (Phase 8):** `reviews` table, governed eligibility (tenant must
  have an active/terminated/expired Contract on the property; 1 review per contract), admin moderation,
  landlord response, **approved-only aggregates** (pending reviews never affect averages, tested).
  Backend complete; review UI lands with the portal/redesign phase.
- [x] ~~Application lifecycle notifications~~ **DONE (Phase 7):** submit→landlord, decide→tenant.
- Fixed a **pre-existing flaky suite** (Carbon::setTestNow leak from LedgerAutomationTest) via a
  global `tearDown` reset in `tests/TestCase.php`: suite is now deterministic (596 green, 6/6 runs).
- [ ] Granular admin RBAC (all admins are super-admins today).
- [ ] `Conversation`/`Message` messaging feature (schema only).
- [x] ~~Feature UIs for media/verification/reviews~~ **DONE (Wyncrest rebuild):** tenant
  verification center + reviews + avatar; landlord photo galleries (GalleryManager) + verification
  + review responses; admin verification & review moderation + audited doc download; **accent-color
  picker** (16 curated, localStorage, brand/action ramps only); **audit-log investigation redesign**
  (filters + detail drawer with real before/after, never fabricated). All wired to real endpoints.
- [ ] Per-page glass polish: a few existing pages still need cosmetic white-glass refinement
  (Listing detail, Browse, some landlord sub-pages); they already render real data on tokens.
- [ ] Frontend automated tests (Vitest/RTL): currently validated via tsc + lint
  + build + live smoke test.
- [x] ~~Known backend nit: `GET /admin/contracts` validates `landlord_id`/`tenant_id`
  filters as `uuid`, but those FKs are bigint.~~ **FIXED (Phase 2):** admin contract & ledger
  filters now validate `integer` for bigint user FKs (`uuid` kept for `contract_id`).

## 24. Coding Standards

- **PHP:** PSR-12 via **Laravel Pint** (`./vendor/bin/pint`). One class per file,
  PSR-4 paths. Thin controllers, fat services. Enums for domain constants.
  No business data hardcoded: use config/`.env`.
- **TypeScript/React:** ESLint clean, typed props/responses, function components +
  hooks, no `any` for API payloads, components small and composable.
- **Naming:** descriptive and consistent with the surrounding file's idiom.

## 25. Documentation Standards

- Keep this file, `README.md`, and `docs/*` in sync with reality.
- Document any deliberate hardcoded value or deviation inline with a `// why:`.
- API changes update `docs/API_REFERENCE.md`.

## 26. Git & Branch Rules

- Work on **`main`** unless explicitly told otherwise. No branch clutter.
- **Never commit** secrets, `.env`, `vendor/`, `node_modules/`, build output,
  logs, `*.sqlite`, or `backups/`.
- Commits are logical and professional; keep the suite green per commit.

### 26a. Mandatory Safe Commit Workflow

**Never use `git commit` with pathspecs in this repository.** Forbidden, no
exceptions:

- `git commit -m "..." -- file`
- `git commit -- file`
- `git add .`
- `git add -A`
- `git commit -a`

Pathspec commits previously caused real contamination in this repo: passing
explicit file paths to `git commit` captures the CURRENT WORKING TREE content
of those files at commit time, not the staged content. When a working tree
held a carefully staged, hunk-scoped change plus unrelated pending work in the
same file, a pathspec commit silently committed the unrelated work too. This
happened more than once and required git-plumbing history repairs to fix.

The only approved commit sequence:

```bash
git diff --cached --name-status   # confirm exactly the intended files
git diff --cached                 # confirm exactly the intended hunks
git commit -m "clear message"     # no pathspec, ever
```

- Stage exact files, or exact hunks (via `git add -p`, or by writing the
  intended content to a scratch copy, restoring `HEAD`'s version, applying
  only the intended edit, staging, then restoring the rest of the pending
  work back into the working tree unstaged) whenever a file mixes an
  intended change with unrelated pending work.
- Always inspect `git diff --cached --name-status` and `git diff --cached`
  before every commit; the staged index, not the working tree, is what gets
  committed.
- Always run a final `git status --short` check immediately before
  committing to confirm nothing unexpected is staged.

## 27. How Future Sessions Should Continue

1. Read this file. Run `php artisan test` to confirm the baseline is green.
2. Pick the next item from §23. Prefer the highest item that blocks build/run/
   test/security.
3. Verify in the codebase before assuming; this file summarizes, the code rules.
4. Add/update tests for any behavior you change. Run Pint. Keep docs in sync.

## 28. What Must NOT Change Without Explicit Approval

- **Ledger immutability** and the audit-log append-only contract.
- **Money-in-cents** representation and UUID PKs on `contracts`/`ledger_entries`.
- **Stripe webhook signature verification** and payment idempotency keys.
- **Authorization model** (server-side enforcement; UI is never the gate).
- Anything touching real credentials, payment capture, or destructive data ops.
</content>
</invoke>
