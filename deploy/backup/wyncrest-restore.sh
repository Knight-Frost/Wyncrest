#!/usr/bin/env bash
# Restore a Wyncrest backup into a SEPARATE app directory and verify it.
#
#   wyncrest-restore.sh <backup_dir> <target_app_dir>
#
# The target must be a deployed copy of the same release (code + vendor +
# .env pointing DB_DATABASE at <target>/database/database.sqlite). This script
# refuses to restore over a directory whose database file already exists, so
# it cannot overwrite live data by accident.
set -euo pipefail
SRC="${1:?backup dir}"; TARGET="${2:?target app dir}"
log() { printf '%s wyncrest-restore %s\n' "$(date -u +%FT%TZ)" "$*"; }

( cd "$SRC" && (sha256sum -c SHA256SUMS 2>/dev/null || shasum -a 256 -c SHA256SUMS) ) >/dev/null || { log "checksum mismatch"; exit 1; }
[ ! -e "$TARGET/database/database.sqlite" ] || { log "refusing: $TARGET/database/database.sqlite exists"; exit 1; }

cp "$SRC/database.sqlite" "$TARGET/database/database.sqlite"
mkdir -p "$TARGET/storage/app"
tar -C "$TARGET/storage/app" -xzf "$SRC/storage-app.tar.gz"
[ "$(sqlite3 "$TARGET/database/database.sqlite" 'PRAGMA integrity_check;')" = "ok" ] || { log "integrity_check failed"; exit 1; }

log "restored; verifying"
cd "$TARGET"
php artisan ops:restore-check --manifest="$SRC/manifest.json"
