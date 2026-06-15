#!/usr/bin/env bash
#
# Nexus dev runner — boots the Laravel API + React SPA together.
#
# Usage:
#   ./dev.sh           Start the backend (API + queue) and the frontend SPA.
#   ./dev.sh --seed    Also (re)seed demo accounts before starting.
#   ./dev.sh --fresh   Drop, re-migrate, and re-seed the database, then start.
#
# It is idempotent: missing dependencies, .env, app key, and the SQLite database
# are set up automatically on first run. Press Ctrl+C to stop everything.

set -euo pipefail

# Resolve the project root (directory of this script) so it works from anywhere.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

API_PORT="${API_PORT:-8000}"
WEB_PORT="${WEB_PORT:-5173}"

# ---- pretty logging --------------------------------------------------------
c_reset=$'\033[0m'; c_green=$'\033[32m'; c_blue=$'\033[34m'; c_yellow=$'\033[33m'; c_red=$'\033[31m'
say()  { printf '%s▸ %s%s\n' "$c_blue" "$1" "$c_reset"; }
ok()   { printf '%s✓ %s%s\n' "$c_green" "$1" "$c_reset"; }
warn() { printf '%s! %s%s\n' "$c_yellow" "$1" "$c_reset"; }
die()  { printf '%s✗ %s%s\n' "$c_red" "$1" "$c_reset" >&2; exit 1; }

# ---- prerequisites ---------------------------------------------------------
command -v php >/dev/null      || die "PHP not found. Install PHP 8.2+."
command -v composer >/dev/null || die "Composer not found."
command -v npm >/dev/null      || die "npm not found. Install Node 18+."

# ---- parse flags -----------------------------------------------------------
SEED=0; FRESH=0
for arg in "$@"; do
  case "$arg" in
    --seed)  SEED=1 ;;
    --fresh) FRESH=1 ;;
    -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Unknown option: $arg (try --help)" ;;
  esac
done

# ---- backend bootstrap -----------------------------------------------------
if [ ! -d vendor ]; then
  say "Installing PHP dependencies (composer install)…"
  composer install --no-interaction
fi

if [ ! -f .env ]; then
  say "Creating .env from .env.example…"
  cp .env.example .env
fi

# Generate an app key if one isn't set.
if ! grep -qE '^APP_KEY=base64:' .env; then
  say "Generating application key…"
  php artisan key:generate --ansi >/dev/null
fi

# SQLite is the default connection — make sure the file exists.
FRESH_DB=0
if grep -qE '^DB_CONNECTION=sqlite' .env && [ ! -f database/database.sqlite ]; then
  say "Creating SQLite database…"
  touch database/database.sqlite
  FRESH_DB=1
fi

if [ "$FRESH" -eq 1 ]; then
  say "Refreshing database (migrate:fresh --seed)…"
  php artisan migrate:fresh --seed --force
elif [ "$SEED" -eq 1 ]; then
  say "Migrating and seeding…"
  php artisan migrate --force
  php artisan db:seed --force
else
  say "Running migrations…"
  php artisan migrate --force
  # Seed automatically the first time the DB is created so demo logins exist.
  if [ "$FRESH_DB" -eq 1 ]; then
    say "Seeding demo data…"
    php artisan db:seed --force
  fi
fi
ok "Backend ready."

# ---- frontend bootstrap ----------------------------------------------------
if [ ! -d frontend/node_modules ]; then
  say "Installing frontend dependencies (npm install)…"
  (cd frontend && npm install)
fi
ok "Frontend ready."

# ---- run both, clean up on exit -------------------------------------------
PIDS=()
cleanup() {
  printf '\n'
  warn "Shutting down…"
  for pid in "${PIDS[@]:-}"; do
    kill "$pid" 2>/dev/null || true
  done
  # Vite/artisan spawn children; sweep any stragglers on our ports.
  wait 2>/dev/null || true
  ok "Stopped."
}
trap cleanup INT TERM EXIT

say "Starting Laravel API on http://localhost:${API_PORT}"
php artisan serve --port="$API_PORT" &
PIDS+=($!)

say "Starting queue worker (notifications, cache jobs)"
php artisan queue:work --tries=1 --quiet &
PIDS+=($!)

say "Starting frontend SPA on http://localhost:${WEB_PORT}"
( cd frontend && VITE_API_PROXY="http://localhost:${API_PORT}" npm run dev ) &
PIDS+=($!)

printf '\n'
ok "Nexus is running:"
printf '   %s→%s  App (SPA):  %shttp://localhost:%s%s\n' "$c_green" "$c_reset" "$c_blue" "$WEB_PORT" "$c_reset"
printf '   %s→%s  API:        http://localhost:%s\n' "$c_green" "$c_reset" "$API_PORT"
printf '   %sDemo logins (password: "password"): admin@nexus.com · landlord1@example.com · tenant1@example.com%s\n' "$c_yellow" "$c_reset"
printf '   Press Ctrl+C to stop.\n\n'

# Wait on the servers; if any exits, the trap tears the rest down.
wait
