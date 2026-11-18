# Wyncrest Launch Closeout

Date: 18 November 2026. Starting commit `1570c8d3` on `main` (remote `main` identical at start). Follows `WYNCREST_FORENSIC_AUDIT.md` (Nov 2026) and `WYNCREST_PRODUCTION_READINESS_REPORT.md` (Jul 2026), which are kept as historical records. All data below is synthetic (seeded dev world, test factories, rehearsal fixtures). No production system, real credential, real notification or real money was touched.

---

## 1. Verdict: NO-GO (repository ready; target host not yet verified)

The code is in launch shape. Every confirmed repository defect from the November audit is fixed or closed with evidence, the owner-approved first-of-month rent policy and both admin due-date controls are built and verified, and every launch gate **passes in local and isolated staging rehearsal**. The verdict stays NO-GO only because launch-critical checks can only be done on the target host, and nobody has done them yet:

| # | Blocker | Who | Proves it closed |
|---|---|---|---|
| B1 | Install the scheduler timer, queue worker and backup timer on the host (`deploy/README.md`) | Ops (host access) | `ops:scheduler-status` exits 0 after the first 01:00 run; `journalctl -u wyncrest-scheduler` shows ticks |
| B2 | TLS + domain on the host; `SESSION_SECURE_COOKIE=true`, `APP_ENV=production` | Ops / owner | Admin sign-in at `https://<domain>/admin/login`; `Set-Cookie` shows `secure; httponly` |
| B3 | First real backup copied off the host, and one restore into a separate directory | Ops | `wyncrest-backup.sh` OK line; `ops:restore-check` PASSED on the restored copy |
| B4 | Real outbound mail: SMTP credentials and a real from-address (currently Message-IDs use `nexus.com`) | Owner | A test notice received in a real inbox the owner controls |
| B5 | Deploy preflight on the live DB (duplicates, catch-up backlog; `docs/OPERATIONS.md` §Deployment) | Ops | Both queries reviewed; backlog decision recorded |

When B1–B5 are done and the post-deploy smoke checks pass, the verdict becomes **GO** for a single-host SQLite launch with Stripe and SMS disabled.

---

## 2. Launch gate matrix (final candidate)

| Gate | Local | Isolated staging rehearsal | Target host |
|---|---|---|---|
| Financial correctness | PASS | PASS | UNVERIFIED |
| Access and privacy | PASS | PASS | UNVERIFIED |
| Database integrity (SQLite) | PASS | PASS (fresh, upgrade, rollback) | UNVERIFIED |
| Browser workflows | n/a | PASS for admin, tenant, landlord journeys listed in §8; contract/application hand-off verified over the HTTP API, not clicked through | UNVERIFIED |
| Deployment / HTTPS | n/a | PASS (nginx + TLS, cookie admin sign-in) | **UNVERIFIED (B2)** |
| Scheduler and queue | n/a | PASS | **UNVERIFIED (B1)** |
| Backup and restore | n/a | PASS | **UNVERIFIED (B3)** |
| Enabled integrations: email | PASS (array/log transports) | PASS (log transport) | **UNVERIFIED (B4)** |
| Stripe (disabled for launch) | PASS (gated UI + 503 API) | PASS | NOT APPLICABLE until enabled |
| SMS (not configured) | PASS (reported unavailable) | PASS | NOT APPLICABLE |
| PostgreSQL / MySQL | — | Postgres installs/seeds/reconciles; app suite still fails | NOT APPLICABLE (not launch engines) |

---

## 3. Release configuration

| Item | Launch value | Evidence / note |
|---|---|---|
| Database | SQLite, single file, WAL (`DB_JOURNAL_MODE=wal`), `DB_BUSY_TIMEOUT=10000` | The existing deployment and every verification use SQLite. Single application host only. |
| Hosting | One Amazon Linux 2023 host: nginx → php-fpm (`apache`), SPA from `frontend/dist` | `docs/DEPLOYMENT.md`, `deploy/README.md` |
| Scheduler | `wyncrest-scheduler.timer` (every minute → `schedule:run`, as `apache`) | `deploy/systemd/` |
| Queue | `QUEUE_CONNECTION=database`, `wyncrest-queue.service` (`queue:work`, restarts, `queue:restart` on deploy) | |
| Billing calendar | `BILLING_TIMEZONE=Africa/Accra`, `BILLING_GRACE_DAYS=0`, catch-up ≤12 periods | `config/billing.php` |
| Backups | Daily 03:15 `wyncrest-backup.timer`, 14 days local; off-host via `WYNCREST_BACKUP_S3_URI` (to set) | `deploy/backup/` |
| HTTPS | Required (admin uses Secure cookies). Not yet on the host (B2) | |
| Payments | Manual/offline payments recorded by landlords. Stripe **off** (no keys). | Unapplied-capture handling (WYN-003) documented for when it is enabled |
| Notifications | In-app always; email via the configured mailer; **SMS off** (no Twilio), shown as unavailable | |
| Supported engines | SQLite only. Postgres partially repaired, not supported. MySQL untested. | README and `docs/DATABASE.md` corrected |

Push to `main` runs CI only (`.github/workflows/ci.yml`); there is no deploy workflow, and the EC2 host is updated by a manual rsync. Pushing is not a production release.

---

## 4. Rent policy and due-date controls

**Policy:** monthly rent is due on the 1st of each calendar month. The owner chose **daily proration** of a partial first or last month (rent × days ÷ days in that month, rounded half-up to the pesewa).

| Example (synthetic, hard-coded in tests) | Result |
|---|---|
| Lease from 1 Dec 2026, GH₵2,500 | Dec 1–31 GH₵2,500 due 1 Dec; Jan due 1 Jan; still pending all day on 1 Dec, overdue from 2 Dec |
| Lease from 10 Nov 2026 | Opening stub Nov 10–30 = 21/30 → GH₵1,750.00 due 10 Nov; then Dec 1 GH₵2,500 |
| Lease from 10 Jan 2026 | GH₵1,774.19 (22/31) due 10 Jan, then GH₵2,500 due 1 Feb |
| Lease ending 14 Feb 2027 | Feb 1–14 = 14/28 → GH₵1,250.00; nothing billed after |
| February 2028 (leap) start 15 Feb | 15/29 → GH₵1,293.10 |
| Due day 31 | 31 Jan, 28 Feb, 31 Mar, 30 Apr, 31 May |
| 23:30 UTC on 31 Jan | Still January in Accra (no Feb charge); already February in Auckland |

**One-time adjustment (admin, `manage_ledger`).** Example rehearsed in the browser: GH₵2,500 November rent, overdue since 1 Nov, moved to 28 Nov with a reason. Result: status pending again, amount, period and reference `INV-20261101-…` unchanged, `original_due_date` 1 Nov kept, one revision row, one audit row, notices to tenant ("now due on 28 Nov 2026 (was 1 Nov 2026)") and landlord. The tenant Payments page, the landlord ledger and the admin case file all showed 28 Nov. The overdue sweep left it pending through 28 Nov. Rules: the new date must be on or after today and the lease start, and no later than 60 days after the period ends. Rent charges only. Paid and waived charges are refused. A stale preview returns 409 and a repeat is a no-op. An existing late fee is untouched.

**Future schedule change.** Example (tests): from January 2027, due day 31 (31 Jan, 28 Feb, 31 Mar); return to the 1st from April. The real scheduler then posted Jan 31, Feb 28, Mar 31, Apr 1, May 1, and December, already issued, stayed on Dec 1. The earliest effective month is the later of next month and the month after the last issued charge. History is append-only and a later change supersedes earlier plans from its month on.

**Existing leases (upgrade).** Leases already sent or signed that don't start on the 1st keep their signed anniversary periods (e.g. 10 Sep–9 Oct), billed in full, so each new period continues the identity sequence already on the ledger. Their due date is the signed day, now always inside the period (WYN-015). Leases that start on the 1st, and drafts, become calendar leases. Moving an anniversary lease to calendar months is not automated: it needs an owner decision on the stub. Rehearsed with data written by the pre-audit code (§7).

Permissions: both controls need `manage_ledger` (super admins implicitly). Every admin can read a lease's schedule. Verified: scoped admin without the capability 403, with it 200, super admin 200, tenant/landlord tokens refused.

---

## 5. Findings register

### November audit (WYN-001 to WYN-020)

| ID | Sev | Disposition | Evidence |
|---|---|---|---|
| WYN-001 | Critical | Fixed, verified (audit fix kept) | `RentChargeIdempotencyTest`; races below (4 processes × 3 months) |
| WYN-002 | Critical | Fixed, verified (kept) | HTTP race: 3 concurrent accepts → one 200, two 403, 1 charge, 1 audit row |
| WYN-003 | High | Fixed (kept); refund/credit procedure is an owner decision **if Stripe is enabled** | `StripePaymentSafetyTest` |
| WYN-004 | Medium | Fixed (kept), extended: sweep CAS also re-checks the due date | `RentDueDateControlsTest::overdue_sweep_does_not_overwrite…` |
| WYN-005 | High | Fixed (mocked); NOT APPLICABLE for launch (Stripe off) | Needs a Stripe test-mode check before enabling |
| WYN-006 | Medium | Fixed (kept) + now enforced by DB triggers | `LedgerIntegrityTriggersTest` |
| WYN-007, 008 | Low | Fixed (kept) | existing tests |
| WYN-009 | High | Partly fixed (reviews FK, morph IDs); Postgres installs, seeds, reconciles and triggers work; app suite still fails (`max()` over UUID in `latestOfMany`, factory data vs check constraints). NOT APPLICABLE for SQLite launch; claims scoped | Postgres 18.4 rehearsal |
| WYN-010 | High | Fixed in repo (systemd units, run log, `ops:scheduler-status`); host install = **B1** | staging rehearsal §7 |
| WYN-011 | High | Fixed in repo (backup/restore scripts, `ops:restore-check`); host = **B3** | restore rehearsal §7 |
| WYN-012 | Medium | Fixed: evidence on open/approved verification or submitted application cannot be deleted by its owner (409 + audit); staff access unchanged. **Retention period / purge policy needs owner approval** | `DocumentRetentionTest` |
| WYN-013 | Medium | Fixed: triggers refuse deleting ledger rows, contracts or users with ledger history | `LedgerIntegrityTriggersTest`; rehearsed on upgraded copy |
| WYN-014 | Medium | Documented: never roll back past `000016` | `docs/OPERATIONS.md` |
| WYN-015 | Medium | Fixed by the rent policy | calculator tests |
| WYN-016 | Medium | Fixed: README/model docs now say corrections are waivers and describe where immutability is enforced | README diff |
| WYN-017 | Low | Fixed: contention returns 409; lock-first transactions (WYN-023) make losers wait | HTTP race above |
| WYN-018 | Low | Fixed (kept); suite green in repeated runs | 2 consecutive full runs |
| WYN-019 | Medium | NOT APPLICABLE (MySQL not a launch engine; untested) | — |
| WYN-020 | Low | Fixed: critical audit row + `missing_first_rent` reconcile FAIL + backfill command | `LedgerIntegrityTriggersTest` |

### New findings in this pass

| ID | Sev | Finding | Disposition |
|---|---|---|---|
| WYN-021 | **High** | Every notification email failed to render (`$message` is reserved in Laravel mail views); `Mail::fake()` hid it | Fixed; render + real-transport test |
| WYN-022 | Medium | `notifications:deliver` printed "All delivered" and exited 0 when all deliveries failed | Fixed: honest output, non-zero exit |
| WYN-023 | **High** | SQLite read-then-write transactions failed with "database is locked" under concurrent jobs; Laravel's `transaction_mode` is ignored on PHP 8.3 (host runs 8.3) | Fixed: `WriteLock` lock-first on rent, contract transitions, due-date changes, late fees, Stripe settlement; proven with races |
| WYN-024 | **High** | Scheduler billed only "today's" period: a missed month was never billed and reconcile said PASS | Fixed: bounded catch-up, `rent_period_gap` rule, audited `ledger:backfill-rent` |
| WYN-025 | Medium | Calendar dates showed one day early for viewers west of UTC | Fixed in shared formatters; 6 sort/day-count sites still parse locally (low; ordering only) |
| WYN-026 | Low | SMS could be switched on with no SMS provider | Fixed: channel endpoint, server refuses, UI explains |
| WYN-027 | Medium | Postgres: morph `*_id` columns bigint vs UUID subjects | Fixed for non-SQLite engines |
| WYN-028 | Low | Invoice reference derived from due date (would change on adjustment) | Fixed: uses posted date |
| WYN-029 | Medium | `isOverdue()` was true from 00:00:01 on the due date (late fee possible on the due day) | Fixed: billing calendar + grace |
| WYN-030 | Medium | One lease failing aborted the whole rent run | Fixed: per-lease isolation, `rent_generation_failed` audit, non-zero exit |
| WYN-031 | Low | Listing-approved queued listener runs twice (idempotent: one notice) | Open, low |
| WYN-032 | Low | "Pay now · Secured payment via Stripe" shown before revealing "not enabled" | Open, copy only (server gate verified) |

### July 2026 follow-ups still relevant

TLS (B2), scheduler (B1) and production DB (resolved: SQLite single host) are covered above. Final-period proration is now explicit (prorated for calendar leases). Still open and not launch-blocking: admins can read all contracts/ledger (by design); audit hash-chain head read not race-safe on Postgres/MySQL (SQLite serializes writers); no morph map; waivers don't notify tenants; no frontend unit tests; PII not field-encrypted.

---

## 6. Changes (summary)

- **Billing:** `config/billing.php`, `Support/Billing/BillingClock`, `Enums/BillingAnchor`, rewritten `Ledger/BillingPeriodCalculator`, `RentDueDayResolver`, `RentDueDateService`, generators and overdue sweep, reconcile rules 10/11, `ledger:backfill-rent`.
- **Migrations:** `2026_11_18_000002` (billing anchor + backfill, `original_due_date` + backfill, `rent_due_schedule_changes`, `ledger_due_date_revisions`), `000003` (integrity triggers, SQLite/Postgres), `000004` (UUID-capable morph IDs, non-SQLite only); non-SQLite fixes in the `reviews` and `documents` migrations.
- **API:** `/admin/ledger/{entry}/due-date[/preview]`, `/admin/contracts/{c}/rent-schedule[/preview|/reset]`, `/notification-preferences/channels`; `rent_due_day` on contracts; `original_due_date`/`due_date_adjusted` on entries. Landlords can no longer set `payment_day`.
- **Frontend:** adjust-due-date modal, rent schedule panel, policy note in contract creation, effective due day everywhere, document retention lock, SMS availability, date formatting fix.
- **Ops:** `deploy/` (systemd, backup/restore), `ops:scheduler-status`, `ops:restore-check`, scheduler run log, contention → 409, CI runs Pint and `tsc`.
- **Docs:** README, `docs/LEDGER.md`, `docs/OPERATIONS.md`, `docs/DEPLOYMENT.md`, `docs/DATABASE.md`, `.env.example`.
- The November audit's uncommitted fixes (13 modified files, 6 new files) are included unchanged in substance.

---

## 7. Verification evidence

### Local (final candidate)

| Command | Result |
|---|---|
| `php artisan test --compact` | **1151 passed, 4820 assertions** (2 consecutive full runs; baseline was 1116) |
| `./vendor/bin/pint --test` | PASS, 558 files |
| `cd frontend && npx tsc --noEmit -p .` | exit 0 |
| `npm run lint` | 0 errors, 2 warnings (unchanged, react-refresh) |
| `npm run build` | built (chunk-size warning, unchanged) |
| New suites | `RentPolicyTest` 7, `RentDueDateControlsTest` 10, `RentCatchUpTest` 3, `LedgerIntegrityTriggersTest` 6, `DocumentRetentionTest` 6, `NotificationEmailRenderTest` 2, `NotificationChannelAvailabilityTest` 1, rewritten `BillingPeriodCalculatorTest` 11 |
| Fresh install (scratch SQLite) | `migrate:fresh --seed` OK, `wyncrest:seed:verify` passed, `ledger:reconcile` PASS, 8 triggers present |
| Production bootstrap (`APP_ENV=production`) | 0 users/admins/contracts/ledger rows, 5 features; idempotent; dev seed refused ("Refusing to run DevelopmentSeeder in production") |

### Upgrade rehearsal (data written by pre-audit commit `1570c8d3`)

Legacy leases built with the old code (anniversary lease starting 10 Sep with a paid first month, calendar-equivalent lease with a synthetic **live duplicate** charge, a sent lease and a draft): 106 rows, 1,736,000 cents.

1. `migrate`: the duplicate preflight refused and nothing changed (still 106 rows / 1,736,000, 60 migrations).
2. Audited waive of the extra charge (old code), then `migrate`: all 4 new migrations DONE; rows and sum unchanged; `original_due_date` set on every row; anchors anniversary for the 10 Sep and 20 Dec leases, calendar for the 1 Oct lease and the draft.
3. Scheduler with new code through Jan 10 2027: anniversary lease continued 10 Dec–9 Jan and 10 Jan–9 Feb (due the 15th, inside the period); no duplicates on repeat; the paid legacy charge was refused for adjustment and its receipt was intact; independent SQL balances **10 contracts, 0 mismatches**; `ledger:reconcile` PASS.
4. Rollback of the 2 newest migrations and re-apply: rows and sum unchanged, triggers restored.

### Isolated staging rehearsal (scratch copy of the candidate: `APP_ENV=staging`, database queue, log mailer, WAL)

- **Queued notification, receiving end:** admin cookie sign-in (CSRF) → approve listing → 2 jobs → worker ran the listener → landlord notice → `notifications:deliver` (fired by the real-clock `schedule:work` at 06:40:00) → email in the mail log. This run uncovered WYN-021/022; after the fix all 12 earlier failures were redelivered.
- **Scheduler at chosen instants** (real `schedule:run` with a rehearsal-only clock injected via a php.ini prepend outside the repo): Dec 1 01:00 rent (a second concurrent scheduler skipped it, logged as `skipped`); 01:30 expiry (lease ending 30 Nov → expired, audited); 02:00 overdue; 02:05 email delivery; `ops:scheduler-status` correctly reported STALE when rent had only been run by hand, and healthy after a scheduled run.
- **Races** (WAL, IMMEDIATE + `WriteLock`): Jan, Feb, Mar 2027, each with 4 concurrent `ledger:generate-rent` + 3 concurrent `notifications:deliver`: all exits 0, 0 failures, each eligible lease billed once (6/6, 6/6, 5/5), 0 duplicates. Before WYN-023 one lease failed with "database is locked".
- **Gap recovery:** lease activated after the Jan/Feb runs → reconcile WARNING `rent_period_gap` → backfill dry run listed exactly Jan and Feb → `--apply --reason` → PASS.
- **Backup → restore into a separate app directory:** manifest 127 rows / 9,330,071 cents / 34 documents → restored **MATCH**, reconcile PASS, 0 balance mismatches, 0 missing files. Restored app served a tenant ledger (outstanding 1,250,000 cents = raw SQL), a document download (`%PDF`, 200), and the landlord statement (403 for a non-owner landlord). Note: run the check with the clock at/after the backup time; earlier clocks make the overdue rule flag future-dated rows.
- **HTTPS (nginx + self-signed TLS, release build):** `XSRF-TOKEN` and `wyncrest-session` set `secure`, session `httponly`, `samesite=lax`; admin login 200, `/admin/me` 200 over HTTPS; the same cookies over HTTP → 401. HSTS header set.

### Browser (Chrome, release build, staging data)

| Journey | Result |
|---|---|
| Admin sign-in (cookie) → ledger case file → Adjust due date: preview, reason, confirm | PASS; DB: due 2026-11-28, original 2026-11-01, pending, 1 revision, 2 notices |
| Tenant sign-in → Payments | PASS: next due 28 Nov 2026, "moved from 1 Nov 2026", November label (after WYN-025 fix) |
| Tenant: Pay now with Stripe off | PASS: "Online card payments are not enabled…"; API `POST /tenant/payments/initiate/{id}` → 503 same message |
| Tenant notifications | PASS: due-date notice present |
| Landlord sign-in → Rent ledger → Record mobile-money payment | PASS: UI totals moved by exactly GH₵2,500; DB entry `paid`, payment −250,000 MTN with reference; tenant "Payment Received" notice; landlord outstanding 286,000 = raw SQL; reconcile PASS |
| Maintenance tenant → landlord (HTTP API through release front) | PASS; other landlord 403 |
| Private documents (HTTP API) | PASS: other tenant 403, unrelated landlord 403, anonymous 401 |
| Contract create → send → accept between roles | PASS over HTTP API (landlord/tenant tokens), not clicked through in the browser |

### Postgres 18.4 (disposable cluster, deleted afterwards)

`migrate:fresh --seed` DONE, seed verify passed, reconcile PASS; triggers refused delete/amount change/contract delete and allowed due-date update; ledger feature suites still fail on app-level incompatibilities (WYN-009).

---

## 8. Deployment and recovery checklist

1. Back up (`systemctl start wyncrest-backup`; confirm `OK`).
2. Preflight queries (`docs/OPERATIONS.md`): no live duplicate rent; review the catch-up backlog. **The EC2 demo box has had no scheduler since June:** its seeded leases would be back-billed up to 12 months on the first run. Set `BILLING_CATCH_UP_MAX_PERIODS=1` there or reseed.
3. `php artisan down`; rsync code (exclude `.env`, `storage`, `database/*.sqlite`); `composer install --no-dev`; ship `frontend/dist`.
4. `php artisan migrate --force` (a refusal changes nothing).
5. `config:cache route:cache view:cache`, `queue:restart`, reload php-fpm/nginx, `php artisan up`.
6. Smoke: `/up` 200; `ledger:reconcile` PASS; HTTPS admin sign-in; one tenant ledger; next morning `ops:scheduler-status` exit 0.
7. Recovery: code rollback is safe; `000002` rollback loses due-date history (prefer restore); **never roll back past `000016`**; anything else: restore to a separate directory, `ops:restore-check`, switch.

---

## 9. Owner decisions still open

| Decision | Why it matters | Closed when |
|---|---|---|
| Document retention period and purge policy (WYN-012) | Evidence is now kept indefinitely while linked | Policy written; purge job specified |
| Moving legacy anniversary leases to calendar billing | Needs a stub rule for those tenants | Decision recorded per lease (or "keep anniversary") |
| Refund/credit handling for unapplied Stripe captures (WYN-003) | Only when Stripe is enabled | Written procedure + alert owner |
| Alert channel for `ops:scheduler-status`, reconcile, critical audit rows | Failures must reach a person | Monitor configured and tested |
| Off-host backup location (`WYNCREST_BACKUP_S3_URI`) | A same-disk backup does not survive instance loss | First off-host copy listed |
| Whether PostgreSQL support is needed | Several app fixes remain (WYN-009) | Only if multi-host is planned |
