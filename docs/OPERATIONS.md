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

## Scheduler, queue and monitoring

Recurring rent, overdue marking, contract expiry and email delivery only happen when the scheduler runs. Install the systemd units in `deploy/` (see `deploy/README.md`): a timer runs `php artisan schedule:run` every minute and a service keeps `php artisan queue:work` alive (needed because `QUEUE_CONNECTION=database`; queued listeners such as "listing approved" notices only run there).

| Job | When (billing time zone) | Evidence |
|---|---|---|
| `ledger:generate-rent` | daily 01:00 | `storage/logs/scheduler-rent.log`, `rent_entry_automated` audit rows |
| `contracts:mark-expired` | daily 01:30 | `contract_expired` audit rows |
| `ledger:mark-overdue` | daily 02:00 | `ledger_entry_marked_overdue` audit rows |
| `notifications:deliver` / `sms-deliver` | every 5 minutes | `delivered_at` on notifications, mail log |

Every task outcome (success, failed, skipped because a previous run was still going) is appended to `storage/logs/scheduler-runs.log`. **Monitor `php artisan ops:scheduler-status`**: it exits 1 when rent, expiry or overdue has not succeeded in 26 hours or email delivery in 1 hour. A command run by hand does not count; only scheduled runs do.

Alert on (owner sets the channel): `ops:scheduler-status` non-zero; `ledger:reconcile` not PASS; any `first_rent_generation_failed`, `rent_generation_failed`, `payment_unapplied` or `payment_amount_mismatch` audit row; `failed_jobs` growing; `notifications:deliver` exiting non-zero.

### First rent failed / missing periods

- `missing_first_rent` (reconcile FAIL): a lease was activated but its opening charge was not posted. Read the `first_rent_generation_failed` audit row for the cause, fix it, then `php artisan ledger:backfill-rent {contract}` (dry run) and `--apply --reason="..."`.
- `rent_period_gap` (WARNING): a period between the first charge and today was never billed (scheduler outage or failed run). Same command. Each daily run also catches up periods after a lease's latest charge automatically (`BILLING_CATCH_UP_MAX_PERIODS`, default 12).

## Backups and restore

`deploy/backup/wyncrest-backup.sh` (daily at 03:15 via `wyncrest-backup.timer`) writes `/var/backups/wyncrest/<UTC stamp>/` with a consistent online SQLite copy (`.backup`, integrity-checked, single file), `storage-app.tar.gz` (private documents and public media), a manifest (ledger row count and signed sum, document count, latest migration) and SHA-256 sums. Local copies are kept 14 days. **Copy backups off the host** by setting `WYNCREST_BACKUP_S3_URI` (needs the AWS CLI and a bucket) or another off-host mechanism: a backup on the same disk does not survive losing the instance.

Files are archived just after the database copy, so a document uploaded or deleted in those seconds can be out of step; `ops:restore-check` lists any document row whose file is missing.

**Restore** (always into a separate directory first, never over the live one):

```bash
# target: a deployed copy of the same release with its own .env (DB_DATABASE -> target)
deploy/backup/wyncrest-restore.sh /var/backups/wyncrest/<stamp> /var/www/wyncrest-restore
# runs: checksum verify, integrity_check, file extract, then
php artisan ops:restore-check --manifest=/var/backups/wyncrest/<stamp>/manifest.json
```

`ops:restore-check` passes only if ledger totals match the manifest, `ledger:reconcile` is not FAIL, every contract balance recomputed in raw SQL equals the engine, and every live document has its file. Run it with the server clock at or after the backup time (a clock earlier than the data makes the overdue rules report future charges as premature). Then open one tenant's ledger and one document in the restored app before switching traffic.

Rehearse a restore at least quarterly.

## Deployment sequence

1. **Backup first:** `sudo systemctl start wyncrest-backup` and confirm the `OK` line in `journalctl -u wyncrest-backup`.
2. **Preflight (read-only)** on the live database:
   ```sql
   -- must return no rows (duplicate live rent per period; migration 2026_11_18_000001 refuses otherwise)
   select contract_id, billing_period_start, count(*) from ledger_entries
    where type='rent' and status<>'waived' group by 1,2 having count(*)>1;
   -- leases the first rent run will catch up (latest charge before this month): review before deploying
   select c.id, max(l.billing_period_start) from contracts c join ledger_entries l on l.contract_id=c.id and l.type='rent'
    where c.status='active' group by c.id having max(l.billing_period_start) < date('now','start of month');
   ```
   Resolve duplicates with an audited admin **waive** of the extra charge. If the second query returns leases that should not be back-billed (for example a demo box whose scheduler never ran), set `BILLING_CATCH_UP_MAX_PERIODS=1` for the first run and decide case by case with `ledger:backfill-rent`.
3. `php artisan down`, deploy code (`rsync`, excluding `.env`, `storage`, `database/*.sqlite`), `composer install --no-dev` (production seed needs no dev packages), `npm run build` locally and ship `frontend/dist`.
4. `php artisan migrate --force`. If a migration refuses, nothing has changed: fix the cause and re-run.
5. `php artisan config:cache route:cache view:cache`, `php artisan queue:restart` (systemd restarts the worker on the new code), reload php-fpm and nginx, `php artisan up`.
6. **Smoke checks:** `/up` returns 200; `php artisan ledger:reconcile` PASS; admin sign-in at `/admin/login` over HTTPS; one tenant's ledger opens; `php artisan ops:scheduler-status` (after the next 01:00 run).

## Rollback and forward recovery

- Code-only problems: redeploy the previous release; the new migrations are additive and older code ignores the new columns and tables.
- `2026_11_18_000001` (rent uniqueness index) and `000003` (integrity triggers) roll back safely.
- `2026_11_18_000002` (due-date controls) rolls back by dropping the revision and schedule tables: **any due-date adjustment history is lost**, and re-applying it re-derives `billing_anchor`, which would mark calendar leases created since the upgrade with a mid-month start as anniversary. Prefer restoring the pre-deploy backup.
- **Never roll back past `2024_01_01_000016`.** On SQLite its `down()` rebuilds `ledger_entries` without payment rows: every payment would be deleted. A rollback that far also removes the integrity triggers first, so nothing stops it. Restore from backup instead.
- Anything beyond a code rollback: restore the step-1 backup into a separate directory, verify with `ops:restore-check`, then switch.
