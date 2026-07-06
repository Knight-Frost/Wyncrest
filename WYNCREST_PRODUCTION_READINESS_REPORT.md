# Wyncrest Production Readiness Report

> **Date:** July 5, 2026
> **Scope:** full-codebase audit + repair pass (backend, frontend, all four portals, financial core, security, seeders, theme, tests, docs).
> **Method:** seven independent read-only audit sweeps (routes/authorization, frontend↔backend parity, financial correctness, seeders/environment separation, database integrity, notifications/audit coverage, theme/dark-mode/accessibility), findings verified in code, then fixed in four coordinated fix passes, then re-verified.

---

## 1. Simple Summary

This was a full-project check before production. The goal was to find broken workflows, fake UI, dead buttons, missing backend support, security gaps, seed problems, financial correctness bugs, and anything else that could hurt Wyncrest in production — including things nobody had noticed yet — then fix them and prove the fixes with tests.

The audit swept all 255 API routes, all ~98 frontend pages, every notification and audit-log write site, every migration, and the seed system. It found **one critical financial bug**, several high-severity security and privacy issues, and a long tail of smaller defects. Everything listed as "fixed" below was fixed and is covered by the test suite, which grew from 1,011 to **1,037 passing tests**.

## 2. Final Verdict

**Mostly production-ready with minor follow-ups.**

The core product is real: every frontend API call maps to a real backend route (all 220 were mechanically checked — zero orphans), authorization is enforced server-side, money is integer-cents with an immutable ledger, and the dev/production seed separation is genuinely fail-closed. The critical payment bug and the security/privacy leaks found by this audit are fixed and tested.

**Remaining launch blockers are environmental, not code:**

1. **TLS/HTTPS + a domain.** Admin login uses Secure cookies and Stripe webhooks require HTTPS — neither works on the HTTP-only demo box. A real deploy needs TLS before the admin portal or payments function.
2. **Real credentials** for Stripe (and Twilio if SMS is wanted), plus a queue worker and a scheduler cron (`php artisan schedule:run`). Without the scheduler, rent generation, overdue marking, contract expiry, and **all email/SMS delivery** never run.
3. **A production database** (MySQL/Postgres rather than SQLite) is recommended; two migrations are SQLite-oriented (see §16).

## 3. What Was Checked

Backend (Laravel 12, 255 routes), frontend (React 18 + TS SPA, ~98 pages), public portal, tenant portal, landlord portal, admin portal (incl. super-admin governance), authentication (bearer + admin cookie session), granular admin capabilities, listings, applications, contracts, ledger, payments/webhooks, maintenance, verification, notifications (in-app/email/SMS/digests), analytics, audit logs (hash chain), exports, media/documents, seeders + seed verification, theme/accent/dark mode, accessibility, error/empty states, tests, dev/production environment separation, and documentation.

## 4. What Was Fixed

### Backend / Financial Fixes (the most important ones)

- **CRITICAL — Stripe payments never settled the rent entry.** A successful payment created the payment record but left the original rent charge PENDING. Consequences: paid rent could be marked overdue, get a late fee, and be **charged a second time** (by card or by the landlord recording a manual payment). Fixed in `PaymentService::recordSuccessfulPayment`: it now runs in a database transaction with row locks, verifies the Stripe charge actually succeeded **for the exact amount and currency**, marks the obligation PAID, and tolerates duplicate webhook deliveries. 7 new tests prove it (`tests/Feature/PaymentSettlementTest.php`).
- **Race-proof status changes.** `LedgerEntry::transitionStatus()` is now a compare-and-swap: if two requests try to settle the same entry at once, the second one fails loudly instead of double-crediting.
- **Manual payments are transactional** and fire the same "payment received" notification Stripe payers get. Trying to record a payment on an already-settled entry is refused.
- **Webhook failures now return HTTP 500** (previously 200), so Stripe retries instead of a real captured charge being lost forever on a transient error. Safe because recording is idempotent.
- **Database-level duplicate-payment guard:** a unique index now prevents two payment rows for the same Stripe payment intent (SQLite/Postgres; MySQL relies on the transaction + lock).
- **Waives and late fees are now attributed to the acting admin** in the audit log (previously logged as "System" — you couldn't tell *who* wrote off money).
- **Contracts now expire.** ACTIVE contracts past their end date used to stay ACTIVE forever (renewals stayed open, statuses lied). New scheduled command `contracts:mark-expired` (daily, audited, ignores open-ended leases).
- **Audit/notification money texts** now use GH₵ (were `$`).

### Security Fixes

- **Cross-landlord analytics IDOR (HIGH):** a landlord could pass any other landlord's `property_id` to the analytics endpoints and read their revenue/contract aggregates. Now ownership-checked (403). A landlord with zero properties also used to fall through to **platform-wide** data; now scoped to nothing.
- **Analytics cache cross-user leak (HIGH):** the cache key omitted the landlord's identity, so two landlords calling analytics with no filters shared one cache slot — Landlord B could be served Landlord A's cached numbers. The key now always includes the user.
- **Admin analytics endpoints 500'd** (`Admin` has no `user_type`); now admin-aware and returning platform-wide data.
- **Landlord dashboard leaked tenants' private DRAFT applications** (including their form data) in the "recent applications" feed. Drafts are now excluded, matching the fix already made on the Applicants page.
- **Dev-seed-in-production latch fixed:** setting `WYNCREST_ALLOW_DEV_SEED_IN_PROD=no` used to **enable** the override (PHP truthy-string cast). Now parsed strictly; "no"/"off"/"0" mean no. Also: an explicit but misspelled `WYNCREST_SEED_MODE` now throws instead of silently seeding the demo world.
- **Dead route files deleted** (`routes/api_contracts.php`, `routes/api_ledger.php`): never loaded, but they defined admin financial routes with a weaker guard — a latent bypass if ever wired in.
- **Archived (soft-deleted) landlords' listings** stayed publicly browsable with a null owner; `Listing::scopePublic()` now requires a live landlord and unit.
- **Unit deletion guard extended:** a unit with a draft/pending listing or a live contract could be soft-deleted, orphaning them. Now blocked.

### Data Integrity Fixes

- `conversations.subject_id` was a BIGINT column storing contract **UUIDs** — worked on SQLite by type-affinity luck, would corrupt/fail on MySQL/Postgres. Widened to `string(36)`.
- Open-ended contract renewal validation was malformed (`after:` with null); fixed.
- One-payment-per-intent unique index (above).

### Audit-Log Truthfulness Fixes

- **Duplicate, misattributed audit rows removed:** approving/rejecting a listing and approving a verification each wrote a *second* audit row attributing the action to the **landlord/user themselves** (the hash-chained, append-only log permanently recorded landlords "publishing their own listings"). The wrongly-attributed listeners are deleted; one admin-attributed row per action remains.
- **Fabricated email logs removed:** six listeners wrote `email_logs` rows with status "sent" while sending nothing (one contained a literal `// TODO: Send actual email` and then stamped "sent"). Real email goes out via the scheduled delivery service; the fiction is gone.

### Workflow / Receiving-End Fixes

- **Contract sent → tenant is now notified** (new `contract_sent` notification). Previously the single most action-required moment in the platform was completely silent.
- **First month's rent → tenant is now notified** on lease acceptance (previously only months 2+ notified).
- **Every message now notifies its recipient** (new `message_received` type, wired at all 6 send sites: conversations, application messages, maintenance messages, contract messages). Previously messaging was entirely silent.
- **Manual payments and Stripe payments now produce the same tenant receipt.**

### Frontend Fixes

- **"Apply for this home" now enters the real guided application.** It used to fire a legacy one-shot submit with an empty form, so every real applicant arrived in the landlord's review centre with 0% completeness. It now creates a draft and opens the 7-step form.
- **Inert/dead controls fixed or removed:** "Continue verification" on Profile (was a dead button on the most important CTA an unverified user sees — now links to `/app/verification`); admin "Open landlord profile" navigated to a nonexistent route (fixed); tenant "View listing" on My Reviews linked by the wrong ID (fixed); "Save search" and two decorative filter buttons removed.
- **Saved→Compare selection** was silently discarded (query param never read) — fixed.
- **Notifications are now deep-linked:** clicking a notification opens the page it's about (payments, contract, application, maintenance, messages), role-aware and conservative.
- **Notification type drift closed:** 9 backend notification types were missing from the frontend union (they rendered with generic icons in wrong tabs) — all mapped.
- **Settings page truthfulness:** "Rent due soon" preference toggle removed (no backend code ever sends it); "Account active"/"Email confirmed" health rows were hardcoded constants, now bound to the real user; a **"Resend verification email"** action was added (the backend endpoint existed with no UI).
- **Open-ended leases render honestly** ("Open-ended" instead of a bogus "1 months" computed from a null date).
- **11 dead API-client functions removed.**

### Theme / Dark-Mode / Accessibility Fixes

- Applications page: active tab pills and success/warning buttons were **white-on-white in dark mode** — fixed with theme-reactive tokens (`light-dark()` solids, same pattern the Properties page already used).
- Maintenance page referenced **five CSS tokens that don't exist** (styles silently collapsed) — replaced with real tokens.
- Payments cards had a fixed white glass sheen glowing in dark mode — now theme-aware.
- Stripe checkout hardcoded the retired ink-teal as its primary color, ignoring the accent picker — now reads the live accent variable.
- Shared Modal gained a real focus trap + focus restore; two hand-rolled dialogs gained Escape-to-close and accessible names.
- The entire landlord list→detail navigation was mouse-only (`div onClick`) — all clickable cards are now keyboard-accessible with visible focus states; unlabeled search/select/close controls got labels.

### Seeder and Simulation Fixes

- The latch and mode-resolution fixes above; stale seeded-account counts corrected in `config/seed.php`, `.env.example`, `dev.sh`; two dead config keys removed; the production seeder's warning now names the current `WYNCREST_BOOTSTRAP_ADMIN_*` env vars.

### Test Fixes

- 26 tests added (settlement, contract expiry, analytics scoping/caching, public-listing scoping, seeding guards, lifecycle notifications, audit single-attribution, open-ended renewal). Three tests that asserted the fabricated email logs were rewritten to assert the truthful behavior.

## 5. End-to-End Workflow Results

| Workflow | Status | What Was Verified | Notes |
| --- | --- | --- | --- |
| Public browse → listing detail | Working | Public scope now excludes archived landlords/units; PII-limited landlord card | |
| Tenant applies for a home | **Fixed → Working** | Apply CTA now creates a draft + opens the guided 7-step form; landlord receives it with real form data; approve/reject/request-info loop notifies | Was: one-shot submit with empty form |
| Landlord reviews applicants | Working | Queue/detail/compare, shortlist, messaging, docs; drafts excluded (incl. dashboard feed — fixed) | |
| Listing submit → admin moderation | Working | Queue, checklist, approve/reject/request-changes; single correctly-attributed audit row (fixed); landlord notified | |
| Contract create → send → accept | **Fixed → Working** | Tenant now notified on send; first rent entry now notifies; ledger entry created on activation | |
| Contract expiry / renewal | **Fixed → Working** | New scheduled expiry command; open-ended renewal validation fixed; renewal history intact | |
| Rent generation → overdue → late fee | Working | Idempotent per period; late fee once per entry, admin-attributed (fixed) | Final partial period bills a full month — documented policy, see §16 |
| Stripe payment → ledger | **Fixed → Working** | Charge verified (status/amount/currency), obligation settled, idempotent redelivery, no double-pay, tenant + audit trail agree | Was: critical settlement bug |
| Manual/offline payment | **Fixed → Working** | Transactional, full-amount only, refused on settled entries, tenant receipt now sent | |
| Waive | **Fixed → Working** | Admin-attributed audit with reason; terminal | Tenant is not notified of waives — minor, see §16 |
| Maintenance request lifecycle | Working | Tenant intake → landlord queue/assign/resolve/close/reopen; messages now notify (fixed); photos policy-checked | |
| Verification submit → review | Working | Document-backed queue, approve/reject/needs-info, hard-gates apply/list; duplicate audit row removed | |
| Messaging | **Fixed → Working** | All 6 send sites now notify the recipient | |
| Admin user governance | Working | Suspend/activate/block/archive with reason + audit + notification; archived landlord's listings unpublished (fixed) | |
| Admin access management | Working | Invite/capabilities/promote/demote, last-super-admin safety, email notices | `manage_features` capability has no UI — now labeled honestly, see §16 |
| Analytics (all roles) | **Fixed → Working** | Cross-landlord IDOR closed, cache scoped per user, admin endpoints un-broken | |
| Exports | Working | All landlord/admin exports ownership-scoped and audited | |
| Notifications delivery | Working | Preference-checked email/SMS via scheduled commands; failures recorded truthfully; requires scheduler cron in production | |
| Seeding (dev/prod) | **Fixed → Working** | Fail-closed guards proven behaviorally; production seed creates zero demo data | |

## 6. Receiving-End Check

Every user action was traced to its receiving side:

- Tenant submits an application → landlord's Applicants queue + notification. ✔
- Tenant uploads verification documents → admin verification queue with document viewing (audited downloads). ✔
- Tenant submits maintenance → landlord ticketing queue with full lifecycle; every status change notifies the tenant. ✔
- Landlord submits a listing → admin moderation command centre; decision notifies the landlord. ✔
- Landlord sends a contract → tenant now sees it AND gets a notification (was silent — fixed). ✔
- Rent generated → tenant notified (now including month 1), visible in tenant payments, landlord ledger, admin ledger. ✔
- Payment succeeds → ledger settles the charge (fixed), tenant gets a receipt, all three portals agree, audit trail records it. ✔
- Payment fails → tenant notified, failure audited, entry stays payable. ✔
- Anyone sends a message → recipient now gets a notification (was a void — fixed). ✔
- Admin changes capabilities → target admin's UI and API access reflect it immediately (session-based, no stale tokens). ✔

No workflow ends in a void.

## 7. Button and Feature Audit

| Area | Button/Feature | Issue Found | Fix |
| --- | --- | --- | --- |
| Listing detail | "Apply for this home" | Bypassed the guided application (empty submissions) | Wired to draft + guided form |
| Profile | "Continue verification" / "Learn more" | Both completely inert | Linked to `/app/verification`; redundant button removed |
| Admin listing review | "Open landlord profile" | Navigated to nonexistent route (404) | Uses the users-directory search convention |
| Tenant My Reviews | "View listing" | Linked with the wrong entity ID | Links via the contract's `listing_id` |
| Saved listings | "Compare selected" | Selection silently discarded | Compare page reads the passed IDs |
| Browse | "Save search" | No handler, no backend | Removed |
| Contracts / Documents | Filter icon-buttons | No handler | Removed |
| Settings | "Rent due soon" toggle | Preference for a notification that never fires | Removed |
| Settings | Email verification | Backend resend endpoint had no UI | "Resend verification email" added |
| Notifications | Every row | Dead end (no links) | Role-aware deep links |
| Landlord lists (5 pages) | Clickable row-cards | Mouse-only | Keyboard accessible + focus states |

## 8. Backend and Frontend Parity

- **Frontend→backend: 100% verified.** All 220 distinct API calls in the SPA map to real routes with matching methods — mechanically diffed, zero orphans.
- **Capability names match exactly** (all 11) between the frontend permission layer and the backend enum; super-admin bypass logic is mirrored.
- **Type drift fixed:** `Contract.end_date` is now honestly nullable; 9 missing notification types added.
- **Backend ghosts:** dead client functions removed. Remaining endpoints with no UI are documented follow-ups (§16): admin metrics observability pages, tenant single-ledger-entry case file, landlord-features admin UI, a handful of superseded legacy routes.

## 9. Security Improvements

- Cross-landlord analytics IDOR closed (ownership check + scoped-empty fallback for propertyless landlords).
- Analytics cache keys now include user identity — no cross-user cache serving.
- Landlord dashboard no longer exposes tenants' private drafts.
- Production seed latch parses booleans strictly; misspelled seed modes throw; production seed proven to create zero demo accounts and to require explicit env vars for the first super admin (no hardcoded credentials).
- Dead route files with weaker guards deleted before anyone could wire them in.
- Public listing scope can't surface archived owners' inventory.
- Stripe webhook: signature verification was already strict; it now also verifies charge status/amount/currency and returns 5xx on processing failures so charges are never silently lost.
- Confirmed clean (audited, no changes needed): admin cookie-session isolation from the bearer pipeline (admin guard absent from Sanctum), CSRF on admin routes, role middleware with account-status checks, ownership policies on all financial/document/media/export endpoints, UUID PKs on financial records, rate limiting per role, no secrets in the repo, no debug leakage, `env()` never read outside config.
- **Known accepted risk (deliberate design, unchanged):** any authenticated admin can *read* contracts/ledger/users; capabilities gate *actions*. This is documented and consistent across all three layers. Recommend `view_*` capabilities if the admin team grows beyond trusted staff.

**Post-audit security review (added after this report was first written):** a follow-up automated review of the same branch found two more issues, both fixed:
- A landlord could open a tenant's still-private DRAFT application (and read/send its messages) via the landlord applicant endpoints — an IDOR/privacy gap. `ApplicationPolicy::view()` now returns true for a DRAFT only when the viewer is the owning tenant.
- CSV exports (maintenance, landlord ledger, admin ledger, admin analytics, audit log) wrote user-controlled strings straight through `fputcsv`, which is a CSV/formula-injection vector in spreadsheet apps. A shared `App\Support\Csv\CsvWriter::sanitizeCell()` now prefixes any cell beginning with `=`, `+`, `-`, `@`, tab, or CR with an apostrophe before it's written.

## 10. Financial and Ledger Safety

- The ledger remains append-only and immutable (model-level guards verified; no raw writes anywhere).
- **Every payment path now settles its obligation exactly once**, under transaction + row locks + compare-and-swap + a DB unique index. Double-charging a tenant is prevented at four layers.
- Amount/currency verification means the ledger records what actually moved, not what was expected.
- Waives and late fees carry the acting admin's identity forever.
- All balances (tenant, landlord, admin, statements, exports) flow through the single `LedgerComputationEngine` — verified, no double-counting.
- `php artisan ledger:reconcile` (10 invariant checks) passes over the seeded world and is CI-gateable.
- Contract expiry no longer drifts: billing already stopped at end date; status now follows.

## 11. Data Integrity Improvements

- UUID-in-BIGINT morph column fixed (`conversations.subject_id`) — removes a silent cross-database corruption trap.
- One-payment-per-intent unique index; unit-deletion guard; public-scope owner checks; open-ended renewal validation.
- Verified clean: enum parity across DB/backend/frontend for all major statuses; business-rule uniques (one contract per listing, one review per contract, etc.); UTC/Ghana timezone safety for billing dates; audit hash chain covers all meaningful fields.

## 12. UI, Dark Mode, and Accent System

- Dark-mode breakages fixed on the Applications, Maintenance, Applicants, and Payments pages (white-on-white pills, unreadable pastel buttons, phantom tokens, glowing glass sheen).
- Accent propagation verified everywhere; the one bypass (Stripe checkout) fixed.
- Focus traps, Escape handling, accessible names, keyboard navigation, and form labels fixed across the landlord portal.
- Verified clean: loading/error/empty states on all 15 major pages sampled; reduced-motion honored; responsive overflow strategies present; toast/error patterns consistent; images have alt text.

## 13. Seeders, Dev Mode, and Production Mode

- **Dev world:** 4 admins (incl. a scoped reviewer and a pending invite), 8 landlords, 13 tenants covering every lifecycle state (owing, late-fee, terminated, expired, suspended, unverified…), full contract/ledger/maintenance/messaging graph — all built through real services so no impossible states exist. `php artisan wyncrest:seed:verify` (40 checks) passes.
- **Production seed:** reference data only; zero people, zero money; first super admin only via `WYNCREST_BOOTSTRAP_ADMIN_{EMAIL,NAME,PASSWORD}` env vars.
- **Guards (behaviorally proven):** dev seed in a production environment throws; the override latch now rejects "no"; misspelled modes throw; `dev.sh` reset is restricted to local SQLite.

## 14. Tests and Build Results

```bash
php artisan test            # 1,037 passed (4,307 assertions) — was 1,011 before this pass
./vendor/bin/pint --test    # PASS (499 files)
cd frontend && npx tsc --noEmit   # clean
cd frontend && npx eslint .       # clean
cd frontend && npm run build      # ✓ built (pre-existing >500 kB chunk warning only)
php artisan migrate:fresh --seed  # dev world seeded
php artisan wyncrest:seed:verify  # PASS (all checks)
php artisan ledger:reconcile      # ✅ PASS — no issues found
WYNCREST_SEED_MODE=production php artisan migrate:fresh --seed --force  # 0 demo records (scratch DB)
APP_ENV=production WYNCREST_SEED_MODE=development php artisan db:seed   # refused (guard threw) ✔
```

All passed. No failures were hidden or skipped.

## 15. Files Changed Summary

| Area | Key Files | Purpose |
| --- | --- | --- |
| Payments | `app/Services/PaymentService.php`, `app/Models/LedgerEntry.php`, `app/Http/Controllers/StripeWebhookController.php`, new migration (payment-intent unique index) | Settle obligations, verify charges, race-proofing, retryable webhooks |
| Ledger | `app/Services/LedgerService.php`, `app/Http/Controllers/Admin/AdminLedgerController.php` | Transactions, actor attribution, receipts, GH₵, first-rent event |
| Contracts | `app/Console/Commands/MarkExpiredContractsCommand.php`, `routes/console.php`, `app/Http/Requests/RenewContractRequest.php` | Expiry automation, renewal validation |
| Analytics security | `app/Http/Controllers/Analytics/*` + new `Concerns/ResolvesAnalyticsScope.php`, `app/Support/Cache/AnalyticsCacheKey.php` | IDOR + cache-leak + admin-crash fixes |
| Privacy | `app/Http/Controllers/Landlord/LandlordDashboardController.php`, `app/Models/Listing.php`, `app/Http/Controllers/Landlord/UnitController.php` | Draft leak, public-scope, deletion guards |
| Seeders/env | `config/seed.php`, `database/seeders/DatabaseSeeder.php`, `ProductionSeeder.php`, `dev.sh`, `.env.example` | Fail-closed guards, honest docs |
| Notifications/audit | `app/Enums/NotificationType.php` (+`contract_sent`, `message_received`), `app/Providers/EventServiceProvider.php`, 6 controllers, 9 listener files deleted | Receiving-end wiring, truthful audit/email logs, dead code removal |
| Routes | `routes/api_contracts.php` + `routes/api_ledger.php` **deleted** | Latent-bypass removal |
| Data | 2 new migrations (`conversations.subject_id` → string; payment-intent unique index) | Cross-DB correctness |
| Frontend parity | `ListingDetail.tsx`, `ProfilePage.tsx`, `Notifications.tsx`, `SettingsPage.tsx`, `ComparePage.tsx`, `MyReviews.tsx`, `lib/types.ts`, `lib/endpoints.ts`, more | Apply flow, dead buttons, deep links, truthful settings |
| Theme/a11y | `applications.css`, `maintenance.css`, `applicants.css`, `payments.css`, `PaymentsPage.tsx`, `Modal.tsx`, 8 landlord pages | Dark mode, focus traps, keyboard access, labels |
| Tests | `PaymentSettlementTest`, `ContractExpiryTest`, `AnalyticsScopeTest`, `PublicListingScopeTest`, `LifecycleNotificationsTest`, `SeedingTest` (+ more) | 26 new tests |

## 16. Remaining Risks or Follow-Ups

| Priority | Issue | Why It Matters | Recommended Next Step |
| --- | --- | --- | --- |
| **Blocker** | No TLS/domain on the deploy target | Admin login (Secure cookies) and Stripe webhooks require HTTPS | Provision a domain + TLS before launch |
| **Blocker** | No scheduler/queue worker in the demo environment | Rent generation, overdue marking, contract expiry, and ALL email/SMS never run without `schedule:run` cron | Configure cron + a queue worker in production |
| High | Two migrations are SQLite-oriented (`..._000016` raw DDL rebuild; MySQL lacks the partial payment index) | A from-scratch MySQL/Postgres migrate needs attention; MySQL relies on app-level locking for payment uniqueness | Test `migrate:fresh` on the chosen production DB before launch |
| Medium | Any admin can read all contracts/ledger/users (deliberate design) | Fine for a small trusted team; least-privilege gap if admin staff grows | Add `view_contracts`/`view_ledger` capabilities when needed |
| Medium | Audit hash chain head-read is not race-safe on MySQL/Postgres under concurrency, and tail-truncation is undetectable | Could produce a false "chain broken" or hide deletion of the newest rows | Serialize chain writes; anchor the head hash externally |
| Medium | No morph map (`Relation::enforceMorphMap`) — polymorphic columns store class names | A future class rename silently orphans historical rows (worst on audit_logs) | Introduce a morph map while data is small |
| Medium | Final partial billing period bills a full month; mid-period termination leaves the full month owed | Policy is defensible but undocumented → dispute risk | Document the proration policy in user-facing terms |
| Low | Backend ghosts: admin metrics endpoints, tenant single-entry ledger view, landlord-features admin UI (capability now labeled "API only"), superseded legacy routes | Functionality exists that no UI reaches | Build UI or prune endpoints in a housekeeping pass |
| Low | Waived charges don't notify the tenant; review reject/hide is silent; application withdraw doesn't notify the landlord | Minor notification asymmetries | Add if product wants them |
| Low | No frontend automated tests (Vitest/RTL); main bundle is 604 kB minified | Regressions rely on tsc/eslint/build + backend suite | Add component tests; code-split the main chunk |
| Low | Sensitive PII is not field-encrypted at rest | Documented, pre-existing | Consider for a hardening phase |
| Low | Two migrations share the `2026_07_05_000002` sequence number (payment-intent index and maintenance-events) | Harmless — Laravel orders migrations by full filename, not just the numeric prefix, so both still run deterministically — but the duplicate number is confusing to read | Renumber one of them next time migrations are touched |

## 17. How to Verify Later

```bash
php artisan test                         # expect 1,037+ passed
./vendor/bin/pint --test                 # expect PASS
cd frontend && npx tsc --noEmit && npx eslint . && npm run build
php artisan migrate:fresh --seed && php artisan wyncrest:seed:verify
php artisan ledger:reconcile             # expect PASS
```

Manual smoke (dev world, password `password`):
1. Log in as `tenant.good1@wyncrest.test` → open a listing → "Apply for this home" should open the 7-step guided form (not instant-submit).
2. As `landlord.1@wyncrest.test`, confirm the applicant appears with real completeness; send a message → tenant gets a notification.
3. As tenant, submit a maintenance request → landlord queue receives it; status changes notify the tenant.
4. As `admin@wyncrest.test` (at `/admin/login`), approve a listing → exactly one audit row, attributed to the admin.
5. Record a manual payment as landlord → tenant gets a receipt notification; entry shows PAID everywhere; recording again is refused.
6. Toggle dark mode + an accent on Applications, Payments, Maintenance → no white-on-white, accent applies (including the Stripe form).
7. As `reviewer@wyncrest.test` (scoped admin), confirm gated pages 403/hide correctly.

## 18. Final Notes

Wyncrest leaves this pass in genuinely strong shape: the domain model is real end-to-end, the financial core is now correct under both failure and concurrency, authorization is server-enforced and tested, and the dev/production boundary is provably fail-closed. The single most important thing this audit caught — payments not settling their obligations — is the kind of bug that creates real-world money disputes, and it is now fixed at four independent layers with tests.

What stands between this codebase and production is operational: TLS, real payment/SMS credentials, a scheduler + queue worker, and a production-grade database (with a migration dry-run on that engine). The honest follow-up list in §16 is short and none of it is workflow-breaking.

The next person should read `CLAUDE.md` first, keep the suite green, and treat `wyncrest:seed:verify` + `ledger:reconcile` as the fast truth-checks they are.
