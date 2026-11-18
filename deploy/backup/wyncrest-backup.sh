#!/usr/bin/env bash
# Consistent backup of the Wyncrest SQLite database and uploaded files.
#
#   WYNCREST_APP_DIR          app root            (default /var/www/wyncrest)
#   WYNCREST_DB_PATH          SQLite file         (default $APP/database/database.sqlite)
#   WYNCREST_BACKUP_DIR       where backups go    (default /var/backups/wyncrest)
#   WYNCREST_BACKUP_KEEP_DAYS local retention     (default 14)
#   WYNCREST_BACKUP_S3_URI    optional off-host copy, e.g. s3://bucket/wyncrest (needs aws cli)
#
# The database copy uses SQLite's online backup API (".backup"), which yields a
# transactionally consistent snapshot while the app keeps serving. Files are
# archived right after; see docs/OPERATIONS.md for the small upload window.
set -euo pipefail
umask 077

APP="${WYNCREST_APP_DIR:-/var/www/wyncrest}"
DB="${WYNCREST_DB_PATH:-$APP/database/database.sqlite}"
OUT="${WYNCREST_BACKUP_DIR:-/var/backups/wyncrest}"
KEEP="${WYNCREST_BACKUP_KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="$OUT/$STAMP"

log() { printf '%s wyncrest-backup %s\n' "$(date -u +%FT%TZ)" "$*"; }
fail() { log "FAILED: $*"; exit 1; }

command -v sqlite3 >/dev/null || fail "sqlite3 is not installed"
[ -f "$DB" ] || fail "database not found at $DB"
mkdir -p "$DEST"

sqlite3 "$DB" ".timeout 30000" ".backup '$DEST/database.sqlite'" || fail "sqlite backup"
# Make the copy a single self-contained file (the live DB may run in WAL mode).
sqlite3 "$DEST/database.sqlite" 'PRAGMA journal_mode=DELETE;' >/dev/null || fail "journal mode on copy"
check="$(sqlite3 "$DEST/database.sqlite" 'PRAGMA integrity_check;')"
[ "$check" = "ok" ] || fail "integrity_check on the copy returned: $check"

rm -f "$DEST/database.sqlite-wal" "$DEST/database.sqlite-shm"

tar -C "$APP/storage/app" -czf "$DEST/storage-app.tar.gz" . || fail "archiving storage/app"

rows="$(sqlite3 "$DEST/database.sqlite" 'select count(*) from ledger_entries;')"
total="$(sqlite3 "$DEST/database.sqlite" 'select coalesce(sum(amount_cents),0) from ledger_entries;')"
docs="$(sqlite3 "$DEST/database.sqlite" 'select count(*) from documents where deleted_at is null;')"
migration="$(sqlite3 "$DEST/database.sqlite" 'select migration from migrations order by id desc limit 1;')"
rm -f "$DEST/database.sqlite-wal" "$DEST/database.sqlite-shm"
cat > "$DEST/manifest.json" <<JSON
{"created_at":"$STAMP","host":"$(hostname)","ledger_rows":$rows,"ledger_sum_cents":$total,"documents":$docs,"latest_migration":"$migration"}
JSON
( cd "$DEST" && sha256sum database.sqlite storage-app.tar.gz manifest.json > SHA256SUMS 2>/dev/null \
  || shasum -a 256 database.sqlite storage-app.tar.gz manifest.json > SHA256SUMS )

if [ -n "${WYNCREST_BACKUP_S3_URI:-}" ]; then
  command -v aws >/dev/null || fail "WYNCREST_BACKUP_S3_URI is set but the aws cli is missing"
  aws s3 cp --recursive --only-show-errors "$DEST" "$WYNCREST_BACKUP_S3_URI/$STAMP" || fail "off-host copy"
  log "copied off-host to $WYNCREST_BACKUP_S3_URI/$STAMP"
fi

find "$OUT" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP" -exec rm -rf {} + 2>/dev/null || true
log "OK $DEST ledger_rows=$rows ledger_sum_cents=$total documents=$docs"
