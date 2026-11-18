
# Wyncrest Forensic Audit and Launch-Readiness Review

Audit date: 18 November 2026. Starting commit: `1570c8d3`. Branch: `main`.
All fixture values in this report are **synthetic** (seeded dev world or test factories). No production data, credentials, or real payments were touched.

---

## 1. Launch verdict

**Verdict: NO-GO.**

The financial core is in a much better state than at the start of the audit. Two Critical double-charge defects and three High or Medium ledger and payment defects were found, fixed, and verified with regression tests, real multi-process races on file-backed SQLite, an upgrade rehearsal, and an independent balance reconciliation. The release is still not launchable, because three launch-critical gates fail or lack evidence:

1. **Operations (Fail).** Nothing in the repository or the known deployment starts the scheduler or a queue worker (WYN-010). Without `schedule:run`, rent after month one is never charged, overdue status is never set, contracts never expire, and email/SMS notifications are never delivered. No backup or restore procedure or evidence exists (WYN-011).
2. **Data integrity on the documented production engines (Fail).** A fresh install fails on PostgreSQL 18 (WYN-009). Two migrations were repaired during the audit, but the chain still stops at `create_reviews_table`. MySQL was not testable here, and the duplicate-charge index is SQLite/Postgres-only by design (WYN-019). Only SQLite is proven.
3. **Core workflows in a real browser (Unverified).** API-level feature tests pass. No browser walk-through of the release build was performed in this pass.

**Top outstanding risks**

| ID      | Severity | Risk                                                                                                         |
| ------- | -------- | ------------------------------------------------------------------------------------------------------------ |
| WYN-010 | High     | No scheduler or worker in deployment: recurring rent, overdue, expiry, and notifications silently do not run |
| WYN-011 | High     | No backup or restore evidence for the SQLite file or `storage/app` documents                               |
| WYN-009 | High     | Fresh install fails on Postgres (docs name MySQL/Postgres for production)                                    |
| WYN-015 | Medium   | Due-date rule produces due dates after the period has ended                                                  |
| WYN-012 | Medium   | Users can permanently delete identity evidence attached to a verification case, including mid-review         |

**Owner decisions required:** launch database engine (WYN-009/019); due-date anchoring rule (WYN-015); document retention policy (WYN-012); refund or credit handling for unapplied Stripe captures (WYN-003, only if Stripe is enabled); whether README claims about "compensating entries" should be corrected (WYN-016).

**What would change the verdict to CONDITIONAL GO or GO:** (a) a scheduler cron plus queue worker running on the target host, with one verified `ledger:generate-rent` run in its logs; (b) a performed backup and a restore that reproduces one tenant statement and its documents; (c) either a commitment to launch on SQLite (single host) or a green `migrate:fresh` plus the ledger suites on the chosen engine; (d) a browser walk-through of the tenant, landlord, and admin journeys in section 3.

---

## 2. Scope and audit environment

| Item                  | Value                                                                                                                                                                      |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Starting commit       | `1570c8d3ff4fd2f45ffac94f89f37e97e23e610c`                                                                                                                               |
| Starting tree         | Uncommitted user edits in `.internal/Claude_Project_Knowledge/*` (12 files plus 1 rename) and `CLAUDE.md`. **Preserved untouched.**                              |
| Backend               | PHP 8.3.30, Laravel 12, Sanctum 4, Pint                                                                                                                                    |
| Frontend              | Node 20.20.0, React 18, TypeScript 5, Vite                                                                                                                                 |
| Test DB               | SQLite `:memory:` (phpunit.xml), array mail, sync queue, array cache                                                                                                     |
| Runtime rehearsal DBs | Disposable SQLite files in the session scratchpad; a disposable PostgreSQL 18.4 cluster (scratchpad data dir, port 55432, deleted afterwards)                              |
| External access       | None. Stripe was never contacted (Mockery double, locally signed webhooks). No email, SMS, or EC2 access. Local `.env` holds no Stripe or Twilio keys.                   |
| Excluded              | EC2 host and its data (no authorization), real Stripe account configuration, MySQL (server not running; not started), browser/E2E testing, legal or compliance conclusions |

Environment safety was verified before any write: no config cache existed (`bootstrap/cache` holds only `packages.php`/`services.php`), and `tinker` confirmed that env overrides pointed at the scratch DB with array mail and sync queue.

---

## 3. System map and critical workflows

**Entry points.** SPA (`frontend/`) → JSON API `routes/api.php` (269 routes). Tenants and landlords use Sanctum bearer tokens. Admins use a cookie session on the `admin` guard with CSRF (`web` group). Stripe webhook `POST /api/webhooks/stripe` (signature-verified). Artisan schedule in `routes/console.php`. Health: `/up`.

**Data stores.** One SQL database (SQLite in dev and on the EC2 demo), the `local` private disk `storage/app/private` (documents, message attachments, restricted media), and the `public` disk (galleries).

**Source of truth.**

- Balances: `ledger_entries` rows only. Derived figures come exclusively from `app/Services/Ledger/LedgerComputationEngine.php` (balance = sum of `amount_cents`; waived obligations count 0; payments stored negative).
- Lease state: `contracts.status`, changed only through `app/Services/Contracts/ContractLifecycleService.php` (draft → pending_tenant → active → terminated or expired).

**Names confirmed in source.** `LedgerComputationEngine` exists. There is **no `PaymentEngine` or `AuditEngine`**: payments live in `PaymentService` plus `Ledger/PaymentEntryFactory`, and audit in `AuditService` and `AuditLogService` (hash chain).

**Ledger writers (complete list found):** `LedgerService::generateFirstRentEntry` (via `ContractObserver` on activation), `generateNextRentEntry` (dev seeder only), `generateLateFee` (admin), `recordManualPayment` (landlord), `waiveEntry` (admin); `LedgerAutomationService::generateRentForContract` (scheduler) and `markOverdueEntries` (scheduler); `PaymentService::recordSuccessfulPayment` (webhook); `Dev/LedgerSeeder`.

**Payment operations actually supported**

| Operation                                                                        | Status                                                                                                                            |
| -------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Rent charge (first on activation, then monthly by scheduler)                     | Live; scheduler not running in deployment (WYN-010)                                                                               |
| Late fee (admin-initiated, one per rent entry)                                   | Live                                                                                                                              |
| Manual/offline payment (cash, MoMo, bank) recorded by landlord, full amount only | Live; records an accounting event, moves no money                                                                                 |
| Stripe card payment                                                              | **Implemented but gated off**: no `STRIPE_SECRET_KEY`, and the SPA hides checkout without `VITE_STRIPE_PUBLISHABLE_KEY` |
| Waive (admin)                                                                    | Live; the only correction mechanism                                                                                               |
| Partial payment, refund, reversal, credit, deposit handling                      | **Absent.** `refund` exists in the enum, but no code path writes it                                                       |

**Critical journeys**

| Journey                         | Caller / permission                                    | Persisted change                                | Side effects                  | Evidence in this audit               |
| ------------------------------- | ------------------------------------------------------ | ----------------------------------------------- | ----------------------------- | ------------------------------------ |
| Tenant accepts lease            | tenant,`ContractPolicy::accept`                      | contract → active, exactly 1 first-period rent | audit, landlord notification  | Regression tests, 3-process race x3  |
| Monthly rent                    | scheduler                                              | 1 rent per contract per period                  | audit, RentGenerated          | Tests, 4-process race x3             |
| Overdue sweep                   | scheduler                                              | pending → overdue (CAS)                        | audit, notification           | Race regression test                 |
| Manual payment                  | landlord owner,`recordPayment` policy                | +payment (−amount), obligation → paid         | audit, receipt                | Existing suite plus reconciliation   |
| Stripe payment                  | tenant `pay` policy, webhook                         | +payment, obligation → paid                    | audit, receipt                | Signed-webhook tests (mocked Stripe) |
| Waive                           | admin `manage_ledger` capability                     | obligation → waived                            | audit                         | Upgrade rehearsal                    |
| Document download               | owner, uploader, or landlord of the linked application | none                                            | audit                         | Code review plus existing tests      |
| Admin login and capability      | admin cookie session                                   | session                                         | `admin_access_denied` audit | Code review plus existing tests      |
| Browser UI for all of the above | all roles                                              |                                                 |                               | **Not performed**              |

---

## 4. Baseline (before any edit)

| Check         | Command                                  | Result                                                                                  |
| ------------- | ---------------------------------------- | --------------------------------------------------------------------------------------- |
| Backend suite | `php artisan test --compact`           | **1101 passed**, 4507 assertions, 26.25s                                          |
| Format        | `./vendor/bin/pint --test`             | Pass, 524 files                                                                         |
| Types         | `cd frontend && npx tsc --noEmit -p .` | Exit 0                                                                                  |
| Lint          | `npm run lint`                         | 0 errors, 2 warnings (react-refresh)                                                    |
| Build         | `npm run build`                        | Pass, 3.46s (chunk-size warning)                                                        |
| CI definition | `.github/workflows/ci.yml`             | Backend tests, frontend lint and build,`.env` tripwire. **No Pint or tsc job.** |

Pre-existing defects found by the audit but invisible in the single baseline run: two flaky tests (WYN-018, observed about 1 in 8 and about 1 in 3 full runs). The project's "deterministic" claim did not hold.

---

## 5. Findings register

| ID      | Sev                | Workflow                     | Evidence                                                                                                                                         | Disposition                                                                                               | Release impact                                                    |
| ------- | ------------------ | ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| WYN-001 | **Critical** | Rent charging                | No DB constraint on rent per period;`generateFirstRentEntry` had no existence check                                                            | Fixed and verified                                                                                        | Was a direct double-charge path                                   |
| WYN-002 | **Critical** | Lease acceptance/termination | Guards ran on stale in-memory status; probe: 2 rent rows, balance GH₵5,000.00 instead of GH₵2,500.00                                           | Fixed and verified                                                                                        | Was a reproducible double charge                                  |
| WYN-003 | High               | Stripe webhook               | Captured money on a settled obligation: no audit row, HTTP 500, multi-day retry loop                                                             | Fixed and verified (mocked Stripe)                                                                        | Only if Stripe is enabled; refund handling is an owner action     |
| WYN-005 | High               | Stripe checkout              | Each panel open minted a new PaymentIntent, so two panels could each capture                                                                     | Fixed, verification incomplete                                                                            | Real Stripe idempotency behaviour not exercised (no test account) |
| WYN-009 | High               | Install/upgrade              | `migrate:fresh` on Postgres 18 failed at 000015, then 000016, then reviews                                                                     | **Open** (2 of 3+ repaired)                                                                         | Blocks Postgres/MySQL launch                                      |
| WYN-010 | High               | Scheduler/queue              | No cron, worker, or deploy definition in repo; CLAUDE.md §21a: "no queue worker or scheduler cron"                                              | **Open**, owner/ops                                                                                 | Recurring billing does not run                                    |
| WYN-011 | High               | Recovery                     | No backup job, restore runbook, or restore evidence;`backups/` holds one January snapshot                                                      | **Open**, owner/ops                                                                                 | Unrecoverable data loss risk                                      |
| WYN-004 | Medium             | Overdue sweep                | Ignored `transitionStatus()` false: false audit row and "overdue" notice for an entry just paid                                                | Fixed and verified                                                                                        |                                                                   |
| WYN-006 | Medium             | Ledger immutability          | `$entry->save()` rewrote `amount_cents` (test reproduced)                                                                                    | Fixed and verified                                                                                        | No live caller found; hardening                                   |
| WYN-012 | Medium             | Documents/verification       | `DocumentController::destroy` deletes the file for any owned document, including those linked to a pending or approved `VerificationRequest` | **Open**, owner decision                                                                            | Evidence loss                                                     |
| WYN-013 | Medium             | Ledger retention             | All ledger FKs `onDelete('cascade')` (contract, tenant, landlord)                                                                              | **Open** (latent; no hard-delete path found)                                                        | A future hard delete would erase history                          |
| WYN-014 | Medium             | Rollback                     | Migration 000016 `down()` deletes every payment row on SQLite                                                                                  | Warning added;**Open** (runbook)                                                                    | Rollback past it destroys payments                                |
| WYN-015 | Medium             | Due dates                    | Start Jan 10 / day 15 gives Feb 10–Mar 9 due**Mar 15**, and nothing due in Feb (computed, section 7)                                      | **Open**, owner decision                                                                            | Affects overdue and late-fee timing                               |
| WYN-016 | Medium             | Docs vs behaviour            | README promises "opposite-signed compensating entries"; only waive exists                                                                        | **Open** (docs)                                                                                     | Misstates the correction model to readers                         |
| WYN-019 | Medium             | MySQL                        | Partial unique indexes (rent, late fee, payment intent) skipped on MySQL                                                                         | **Open**                                                                                            | App-level protection only on MySQL                                |
| WYN-007 | Low                | Reconciliation               | Reconciler counted waived duplicates as failures forever                                                                                         | Fixed and verified                                                                                        |                                                                   |
| WYN-008 | Low                | Contract observer            | `getOriginal()` enum compared to a string (always true)                                                                                        | Fixed and verified                                                                                        | No behaviour change today                                         |
| WYN-017 | Low                | Concurrency UX               | On SQLite the losing concurrent accept gets a 500 "database is locked"                                                                           | **Open**                                                                                            | Correct outcome, poor message                                     |
| WYN-018 | Low                | Test suite                   | Two factory flakes (audit description, unit number)                                                                                              | Fixed and verified                                                                                        | CI reliability                                                    |
| WYN-020 | Low                | Activation                   | First-rent failure is caught and only logged by `ContractObserver`                                                                             | **Open**                                                                                            | Silent missing charge possible                                    |
| FP-1    | n/a                | Files                        | Unauthenticated `GET storage/{path}`                                                                                                           | False positive: Laravel `ServeFile` requires a valid signature for the private disk; the app mints none |                                                                   |
| FP-2    | n/a                | Admin RBAC                   | Possible lag on revocation                                                                                                                       | False positive:`EnsureAdmin`/`EnsureAdminCan` re-check the freshly loaded admin per request           |                                                                   |

Unverified controls (not defects): full role × route authorization matrix (only spot-checked; the existing IDOR and policy suites pass), real Stripe account settings, EC2 configuration, and browser UX.

---

## 6. Remediation details

### WYN-001 and WYN-002: double first-month rent (Critical)

**Root cause.** Two gaps combined. (1) Rent uniqueness was an application check-then-insert (`rentExistsForPeriod`) with no database constraint, and `generateFirstRentEntry()` did no check at all. (2) `ContractLifecycleService::accept()` validated `$contract->status` on the instance that route-model binding loaded at the start of the request, with no lock or compare-and-swap. A second overlapping accept therefore re-ran `update()`, re-fired `ContractObserver::updated`, and billed month one again. The same staleness let an accept land after a termination and resurrect the contract. SQLite (the deployed engine) uses `DEFERRED` transactions, so `lockForUpdate()` is a no-op there. That is why the late-fee and payment-intent paths each needed a partial unique index, and rent had none.

**Before evidence.** A probe on unfixed code (two stale copies, both accepted) produced `rent_rows=2 balance_cents=500000`. Five of the seven new tests failed on unfixed code.

**Changes.**

- `database/migrations/2026_11_18_000001_add_unique_rent_period_index_to_ledger_entries.php`: partial unique index `(contract_id, billing_period_start) WHERE type='rent' AND status<>'waived'` (SQLite and Postgres). **Preflight:** if live duplicates exist it throws and changes nothing, naming the contracts. Waived rows are excluded so the documented correction (waive) is also the upgrade recovery path.
- `app/Services/Ledger/RentChargeWriter.php` (new): the single rent insert. It wraps the insert in a nested transaction (a savepoint, so a refusal cannot poison an outer Postgres transaction) and maps `UniqueConstraintViolationException` to "already generated".
- `app/Services/LedgerService.php`: first and sequential generators return the existing period charge instead of inserting (no second audit row or notification).
- `app/Services/LedgerAutomationService.php`: the scheduled generator uses the writer; a refusal returns `null` (skipped).
- `app/Services/Contracts/ContractLifecycleService.php`: new `transition()` re-reads the row inside a transaction (row-locked on MySQL/Postgres), guards on the **persisted** status, and updates that fresh row, so activation and first charge commit atomically. Used by `send`, `accept`, `expire`, and all terminations.
- `app/Observers/ContractObserver.php`: `getRawOriginal()` comparison (WYN-008).

**After evidence.** `tests/Feature/Ledger/RentChargeIdempotencyTest.php` 7/7 pass. Real races on file SQLite: 3 rounds × 3 concurrent accept processes each gave 1 rent row (250,000 cents), 1 `contract_accepted` audit row, status active (losers: `database is locked`, see WYN-017). 3 rounds × 4 concurrent `generateRentForContract` processes each gave 1 created and 3 skipped, 1 row, 1 audit. Postgres: index DDL verified (`pg_indexes`), second insert absorbed, outer transaction still usable, 1 row committed.

**Migration impact.** Forward-only DDL, milliseconds on current data sizes. On MySQL it is a no-op (WYN-019). Rollback (`down`) drops the index only, with no data effect; rehearsed.

### WYN-003: captured Stripe money with no durable record (High)

**Root cause.** If Stripe captured a payment for an obligation already settled (landlord recorded cash first, or a second checkout panel), `recordSuccessfulPayment()` threw a generic exception before any audit write. The webhook answered 500 and Stripe retried for days. The only trace was log lines.

**Change.** `app/Services/Ledger/UnappliedPaymentException.php` (new). In `app/Services/PaymentService.php`, `settleObligation()` throws it when the obligation is not payable or the CAS loses; `recordUnappliedPayment()` writes **one** critical `payment_unapplied` audit row per intent (deduplicated across redeliveries) with reason, received amount, and obligation status. The amount/currency mismatch path uses the same mechanism. **No ledger entry is created**: refund versus credit is an owner decision. `app/Http/Controllers/StripeWebhookController.php` returns 200 `{"status":"unapplied"}` plus `Log::critical` for this permanent case. Transient errors still return 500 so Stripe retries.

**Before → after.** The signed-webhook test returned 500 before the fix and 200 after, with exactly 1 audit row after two deliveries, payment count unchanged, and tenant balance 0. The existing `PaymentSettlementTest` (including the mismatch message) still passes.

### WYN-005: one PaymentIntent per checkout open (High)

**Change.** `PaymentService::createPaymentIntent()` now sends `idempotency_key = wyncrest-ledger-{entry_id}-{amount}-{currency}`. Amount and currency are immutable on a ledger entry, so parameters never conflict. **Limit:** verified only against a Mockery double asserting the same key on repeated calls. Stripe retains keys for 24h, so an intent left open longer could still be duplicated; WYN-003's audit row is the backstop.

### WYN-004: overdue sweep reporting transitions it did not make (Medium)

`markOverdueEntries()` now `continue`s when the compare-and-swap returns false. Test: an entry settled at retrieval time (deterministic `retrieved` hook) yields 0 marked and 0 overdue audit rows; before the fix it yielded 1.

### WYN-006: `save()` bypassed ledger immutability (Medium)

`app/Models/LedgerEntry.php` overrides `performUpdate()` (the funnel behind `save`, `saveQuietly`, `touch`, `push`) and `incrementOrDecrement()` to throw `LogicException`. `transitionStatus()` is a query-builder CAS and is unaffected. `tests/Feature/Ledger/LedgerImmutabilityTest.php`: save attempt refused, amount unchanged, waive transition still works.

### WYN-007: reconciler and waived duplicates (Low)

`LedgerReconciliationService::checkDuplicateRentPeriods` excludes waived rows, matching the index. The existing duplicate-detection test now drops the index first to model MySQL or pre-upgrade data, with its assertions unchanged. A new test proves a waived duplicate no longer fails reconciliation and the balance counts once.

### WYN-009 (partial): Postgres install

`2024_01_01_000015`: on non-SQLite engines the self-referencing `related_rent_entry_id` FK is added after table creation (Laravel emitted it before the PK). SQLite DDL is byte-identical. `2024_01_01_000016`: the hand-written SQLite table rebuild is skipped on other engines (the enum already contains payment/refund), and `down()` gained a non-SQLite path plus a data-loss warning. Existing SQLite deployments do not re-run these migrations. **Still failing:** `2026_06_24_200001_create_reviews_table` (`contract_id` varchar → uuid FK). Not pursued further (time-boxed).

### WYN-018: flaky tests

`tests/Feature/AdminAuditLogTest.php`: pinned descriptions (the factory derives `description` from a random action before the override). 20/20 isolated runs pass. `database/factories/UnitFactory.php`: unit number space widened from `##?` (2,600 values) to `###??` (676,000).

---

## 7. Financial integrity and reconciliation

All values are synthetic. GH₵ amounts are shown in cents.

| Scenario                                                           | Expected                                    | Observed                                         | Where                                |
| ------------------------------------------------------------------ | ------------------------------------------- | ------------------------------------------------ | ------------------------------------ |
| Initial charge on accept                                           | 1 rent row, 250000                          | 1 row, 250000                                    | RentChargeIdempotencyTest, races     |
| Repeated accept (API)                                              | 403, still 1 row                            | 403, 1 row                                       | test                                 |
| Stale double accept (unfixed code)                                 |                                             | **2 rows, 500000**                         | probe (before evidence)              |
| Concurrent accept, 3 processes × 3 rounds                         | 1 row each round                            | 1 row, 1 audit, each round                       | file-SQLite race                     |
| Repeated scheduler (2 runs × 2 months)                            | 2 rows, 500000                              | 2 rows, 500000, starts 2026-03-01 / 2026-04-01   | test                                 |
| Concurrent scheduler, 4 processes × 3 rounds                      | 1 created, 3 skipped                        | identical in all 3 rounds                        | file-SQLite race                     |
| Retry after write succeeded (racing worker passes existence check) | null, no audit                              | null, audit count unchanged                      | test                                 |
| Stripe redelivery (HTTP, signed)                                   | 1 payment, contract sum 0                   | 1 payment, sum 0                                 | StripePaymentSafetyTest              |
| Capture on already-paid obligation                                 | 0 new payments, 1 critical audit, balance 0 | as expected                                      | test                                 |
| Partial payment                                                    | Not supported                               | N/A (full-amount only, by design)                | `LedgerComputationEngine` docblock |
| Correction                                                         | Waive (status → waived, balance impact 0)  | Upgrade rehearsal: waive audited, reconcile PASS | rehearsal                            |
| Refund / reversal / credit                                         | Not implemented                             | N/A                                              | no writer exists                     |

**Independent reconciliation** (seeded dev world after the upgrade rehearsal and races). Balances were recomputed in raw SQL from the documented rule only (sum of `amount_cents`, waived obligations = 0) and compared with `LedgerComputationEngine`: **14 contracts, 0 mismatches; 8 tenants, 0 mismatches.** Platform outstanding: 1,826,000 (SQL) = 1,826,000 (engine). Collected (engine): 17,970,000. Paid obligations without a payment row: 0. Payments not equal to the negated obligation: 0. Obligations with more than one payment: 0. `ledger:reconcile`: PASS.

**Due-date computation (WYN-015)**, synthetic contract start 2026-01-10, payment day 15:

| Generator                 | Period                   | Due                                     |
| ------------------------- | ------------------------ | --------------------------------------- |
| First period (activation) | 2026-01-10 .. 2026-02-09 | 2026-01-15                              |
| Scheduler                 | 2026-02-10 .. 2026-03-09 | **2026-03-15** (after period end) |
| Scheduler                 | 2026-03-10 .. 2026-04-09 | 2026-04-15                              |

Coverage gaps: real Stripe test-mode round trip, MySQL behaviour, statement rendering in the browser.

---

## 8. Verification record

| Check                                                                        | Environment                         | Baseline                       | Final                                                                                                      | Limits                                              |
| ---------------------------------------------------------------------------- | ----------------------------------- | ------------------------------ | ---------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| `php artisan test --compact`                                               | SQLite :memory:                     | 1101 passed (4507)             | **1116 passed (4555), 5 consecutive green runs** after flake fixes                                   | SQLite only                                         |
| `pint --test`                                                              | local                               | pass                           | pass                                                                                                       |                                                     |
| `tsc --noEmit`                                                             | frontend                            | exit 0                         | exit 0                                                                                                     | no frontend changes                                 |
| `npm run lint`                                                             | frontend                            | 0 err / 2 warn                 | 0 err / 2 warn                                                                                             |                                                     |
| `npm run build`                                                            | frontend                            | pass                           | pass (3.46s)                                                                                               |                                                     |
| New ledger tests (3 files, 14 tests)                                         | SQLite :memory:                     | 9 of 14 failed on unfixed code | 14/14 pass                                                                                                 | Stripe mocked                                       |
| Fresh install (SQLite)                                                       | scratch file                        | n/a                            | `migrate:fresh --seed` OK                                                                                |                                                     |
| Upgrade (SQLite): rollback to pre-audit schema, inject duplicate,`migrate` | scratch file                        | n/a                            | Refused, 100 rows / 786,000 unchanged; after audited waive: DONE, reconcile PASS; rollback and re-apply OK | Reconstructed baseline, not a copy of EC2 data      |
| Fresh install (Postgres 18.4)                                                | disposable cluster                  | n/a (failed at 000015)         | Passes 000015/000016,**fails at reviews migration**                                                  |                                                     |
| Rent index + savepoint (Postgres)                                            | disposable cluster                  | n/a                            | Index correct; refusal absorbed; outer tx usable                                                           | ledger-only fixture, FK checks off for that session |
| Production seed                                                              | scratch file,`APP_ENV=production` | n/a                            | 0 users, 0 admins, 0 contracts, 0 ledger; idempotent; dev seed refused without latch                       |                                                     |
| Preflight on local dev DB (read-only)                                        | `sqlite3 -readonly`               | n/a                            | 0 live duplicate groups / 99 rows                                                                          | EC2 DB not checked                                  |
| Browser walk-through                                                         |                                     | not run                        | **not run**                                                                                          | Gate unverified                                     |

---

## 9. Launch gate matrix

| Gate                    | Status                  | Evidence / dependency                                                                                                                                                                        |
| ----------------------- | ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Financial correctness   | **Pass** (SQLite) | Independent reconciliation, 0 mismatches; duplicate, retry, and real-concurrency results in §7. Depends on WYN-015 decision. Stripe path verified against mocks only (Stripe is gated off). |
| Access and privacy      | **Unverified**    | Existing IDOR/policy suites pass; spot review of downloads, attachments, storage route, admin session. No full independent role × route matrix built. WYN-012 retention open.               |
| Data integrity          | **Fail**          | SQLite fresh install and upgrade verified; Postgres fresh install fails (WYN-009); MySQL untested and lacks partial indexes (WYN-019). Passes only if the owner commits to SQLite.           |
| Core workflows          | **Unverified**    | API feature tests pass; no browser verification of the release build.                                                                                                                        |
| Build and deploy        | **Unverified**    | Build, lint, types, and tests green. No deploy definition in repo; EC2 not accessible; CI lacks Pint/tsc steps.                                                                              |
| Operations and recovery | **Fail**          | No scheduler/worker (WYN-010); no backup/restore evidence (WYN-011).                                                                                                                         |
| Remaining risk          | **Pass**          | Every open item in §11 has a severity, an action, and an owner role.                                                                                                                        |

---

## 10. Deployment, rollback, and recovery

**Required sequence (pending, owner/ops):**

1. Take a backup **before** deploying: copy `database/database.sqlite` (with the app in maintenance mode, or using `sqlite3 .backup`) and `storage/app/`.
2. Run the read-only preflight on the target DB: `select contract_id, billing_period_start, count(*) from ledger_entries where type='rent' and status<>'waived' group by 1,2 having count(*)>1;`. Any rows need an audited admin **waive** of the extra charge first.
3. Deploy code, run `composer install`, `php artisan migrate --force`. If the new migration refuses, nothing has changed; resolve step 2 and re-run.
4. `php artisan config:cache route:cache`, then reload php-fpm and nginx.
5. Restart any queue workers (`php artisan queue:restart`) so they load the new code.
6. Post-deploy: `php artisan ledger:reconcile` reports PASS; `/up` returns 200; one tenant statement matches the reconcile totals.

**Scheduler and worker (missing today):** cron `* * * * * cd /var/www/wyncrest && php artisan schedule:run >> /dev/null 2>&1`. Plus `php artisan queue:work --tries=3` under systemd if `QUEUE_CONNECTION` is not `sync`. `withoutOverlapping()` uses the cache store; on multiple hosts add `onOneServer()` (the new index already prevents duplicate rent).

**Rollback.** Rolling back the new migration is safe (index only). **Never roll back past `2024_01_01_000016`**: on SQLite its `down()` deletes every payment row. For anything beyond a code rollback, restore from the step-1 backup. Ledger rows are never edited in place; corrections are waives (audited).

**Launch hold or rollback triggers (proposed; owner to set thresholds):** `ledger:reconcile` not PASS; any `payment_unapplied` or `payment_amount_mismatch` audit row; scheduler log missing a daily `ledger:generate-rent` entry; any 5xx on `/api/webhooks/stripe`.

---

## 11. Open work and owner actions

| ID      | Sev                 | Action                                                                                                                                             | Dependency                       | Owner role          | Evidence to close                                                                              |
| ------- | ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- | ------------------- | ---------------------------------------------------------------------------------------------- |
| WYN-010 | High                | Install scheduler cron (and worker if not `sync`) on the target host                                                                             | Host access                      | Ops                 | Scheduler log showing `ledger:generate-rent` and `ledger:mark-overdue` ran                 |
| WYN-011 | High                | Define a backup of DB plus `storage/app`; perform one restore                                                                                    | Host/storage access              | Ops                 | Restored copy where `ledger:reconcile` is PASS and one tenant's statement and documents open |
| WYN-009 | High                | Choose launch engine. If Postgres/MySQL: fix remaining migrations (string-vs-uuid FKs starting at `create_reviews_table`)                        | Owner decision, then engineering | Owner + backend     | Green `migrate:fresh` plus ledger suites on that engine                                      |
| WYN-019 | Medium              | If MySQL: add functional/generated-column unique indexes for rent, late fee, payment intent                                                        | WYN-009 decision                 | Backend             | Duplicate-insert tests passing on MySQL                                                        |
| WYN-015 | Medium              | Decide due-date rule (start- vs end-anchored); then align `BillingPeriodCalculator`                                                              | Contractual policy               | Owner               | Decision recorded; tests for chosen rule                                                       |
| WYN-012 | Medium              | Decide retention: block deleting documents linked to pending/approved verification and submitted applications, or soft-delete without file removal | Policy                           | Owner, then backend | Policy plus regression test                                                                    |
| WYN-013 | Medium              | Change ledger FKs to restrict (table rebuild on SQLite) or forbid hard deletes of users/contracts                                                  | Engineering, migration rehearsal | Backend             | Migration rehearsed on a copy                                                                  |
| WYN-014 | Medium              | Add "never roll back past 000016" to the ops runbook                                                                                               | none                             | Ops                 | Runbook updated                                                                                |
| WYN-003 | High (if Stripe on) | Decide refund/credit procedure for `payment_unapplied`; monitor critical audit rows                                                              | Stripe account                   | Owner/finance       | Written procedure; alert on the audit action                                                   |
| WYN-005 | High (if Stripe on) | Exercise checkout in Stripe**test mode** with two panels open                                                                                | Stripe test keys                 | Backend             | One captured charge per obligation                                                             |
| WYN-016 | Medium              | Correct README "compensating entries" and "immutable at the policy layer" wording (corrections are waives; immutability is model-level plus CAS)   | none                             | Maintainer          | README diff                                                                                    |
| WYN-017 | Low                 | Map SQLite busy on contract transitions to 409, or use `IMMEDIATE` transactions                                                                  | none                             | Backend             | Race test shows 4xx                                                                            |
| WYN-020 | Low                 | Surface first-rent generation failures (audit or alert) instead of log only                                                                        | none                             | Backend             | Test                                                                                           |
| Gate    | n/a                 | Browser walk-through of tenant, landlord, and admin journeys (§3) on the release build                                                            | Running stack                    | QA                  | Recorded pass per journey                                                                      |
| CI      | Low                 | Add `pint --test` and `tsc --noEmit` to CI                                                                                                     | none                             | Maintainer          | CI run                                                                                         |

---

## 12. Change summary

**Application code**

- `app/Services/Ledger/RentChargeWriter.php` (new): idempotent rent insert.
- `app/Services/Ledger/UnappliedPaymentException.php` (new).
- `app/Services/LedgerService.php`: first and sequential rent generators idempotent.
- `app/Services/LedgerAutomationService.php`: race-safe rent generation; overdue sweep honours CAS.
- `app/Services/Contracts/ContractLifecycleService.php`: transitions guard on persisted status in a transaction.
- `app/Services/PaymentService.php`: idempotency key; unapplied-capture audit record; `settleObligation()` extracted.
- `app/Http/Controllers/StripeWebhookController.php`: acknowledges unapplied captures with 200.
- `app/Services/Ledger/LedgerReconciliationService.php`: waived rows excluded from the duplicate rule.
- `app/Models/LedgerEntry.php`: `performUpdate`/`incrementOrDecrement` refuse.
- `app/Observers/ContractObserver.php`: raw-original comparison.

**Migrations**

- New `2026_11_18_000001_add_unique_rent_period_index_to_ledger_entries.php` (with preflight). **Operational impact:** step 2 of §10 must run first on any existing DB.
- Edited `2024_01_01_000015` and `000016`: non-SQLite paths only; SQLite schema unchanged; no effect on already-migrated databases.

**Tests**

- New: `tests/Feature/Ledger/RentChargeIdempotencyTest.php` (7), `StripePaymentSafetyTest.php` (4), `LedgerImmutabilityTest.php` (3).
- Updated: `LedgerComputationEngineTest.php` (fixture models pre-index data, plus 1 new test), `AdminAuditLogTest.php` (deterministic fixture).
- Factory: `database/factories/UnitFactory.php`.

**Runtime and configuration:** no env, config, or dependency changes. Nothing was committed or pushed; the user's pre-existing uncommitted `.internal/` and `CLAUDE.md` edits were left as found.
