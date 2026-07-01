#!/usr/bin/env bash
#
# Wyncrest dev runner — boots the Laravel API + React SPA together, with a fully
# reset, truthful local database every run.
#
# Usage:
#   ./dev.sh             Development world: reset DB + development seed, then run
#                        the API, queue worker and Vite dev server (HMR on :5173).
#   ./dev.sh --prod      Production preview: reset DB + production-safe seed (only
#                        a bootstrap admin), build the SPA and serve the build with
#                        `vite preview` on :3000. No demo tenants/landlords exist.
#   ./dev.sh --no-reset  Skip the database reset/seed (keep existing local data)
#                        and just run pending migrations before starting.
#   ./dev.sh --help      Show this help.
#
# Both modes RESET the database every run by default (migrate:fresh) so there is
# never stale or mystery local data. Reset is guarded: it refuses to run unless the
# environment is local/dev and the database is SQLite (override with
# WYNCREST_ALLOW_NONLOCAL_RESET=1 if you know what you are doing).
#
# Seed mode is driven by WYNCREST_SEED_MODE (development | production). The legacy
# NEXUS_SEED_MODE is still honoured as a fallback by config/seed.php.
#
# It is idempotent: missing dependencies, .env, app key, and the SQLite database
# are set up automatically on first run. Press Ctrl+C to stop everything cleanly.

set -euo pipefail

# Resolve the project root (directory of this script) so it works from anywhere.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

API_PORT="${API_PORT:-8000}"

# Local demo admin credentials. In development these match the seeded admin; in
# production preview they are passed to the bootstrap-admin env so exactly one
# admin exists and can log in. Local only — never real secrets.
ADMIN_EMAIL="${WYNCREST_ADMIN_EMAIL:-admin@wyncrest.test}"
ADMIN_NAME="${WYNCREST_ADMIN_NAME:-Wyncrest Admin}"
ADMIN_PASSWORD="${WYNCREST_ADMIN_PASSWORD:-password}"
DEMO_PASSWORD="password"

# ---- pretty logging --------------------------------------------------------
c_reset=$'\033[0m'; c_green=$'\033[32m'; c_blue=$'\033[34m'; c_yellow=$'\033[33m'; c_red=$'\033[31m'; c_dim=$'\033[2m'
say()  { printf '%s▸ %s%s\n' "$c_blue" "$1" "$c_reset"; }
ok()   { printf '%s✓ %s%s\n' "$c_green" "$1" "$c_reset"; }
warn() { printf '%s! %s%s\n' "$c_yellow" "$1" "$c_reset"; }
die()  { printf '%s✗ %s%s\n' "$c_red" "$1" "$c_reset" >&2; exit 1; }

# ---- prerequisites ---------------------------------------------------------
command -v php >/dev/null      || die "PHP not found. Install PHP 8.2+."
command -v composer >/dev/null || die "Composer not found."
command -v npm >/dev/null      || die "npm not found. Install Node 18+."

# ---- parse flags -----------------------------------------------------------
PROD=0; NO_RESET=0
for arg in "$@"; do
  case "$arg" in
    --prod)     PROD=1 ;;
    --no-reset) NO_RESET=1 ;;
    -h|--help)  grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Unknown option: $arg (try --help)" ;;
  esac
done

if [ "$PROD" -eq 1 ]; then
  MODE_LABEL="production preview"
  SEED_MODE="production"
  WEB_PORT="${WEB_PORT:-3000}"   # vite preview origin allowed by config/cors.php
else
  MODE_LABEL="development"
  SEED_MODE="development"
  WEB_PORT="${WEB_PORT:-5173}"   # vite dev server with HMR
fi

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

# Read the local env/connection straight from .env for the reset guardrail.
env_value() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" | xargs || true; }
APP_ENV="$(env_value APP_ENV)"
DB_CONNECTION="$(env_value DB_CONNECTION)"
DB_CONNECTION="${DB_CONNECTION:-sqlite}"

# SQLite is the default connection — make sure the file exists.
if [ "$DB_CONNECTION" = "sqlite" ] && [ ! -f database/database.sqlite ]; then
  say "Creating SQLite database…"
  touch database/database.sqlite
fi

# ---- reset guardrail -------------------------------------------------------
# Resetting wipes the database. Only ever allow it against a clearly local target.
guard_reset() {
  case "$APP_ENV" in
    local|development|testing|"") ;;
    *) die "Refusing to reset the database: APP_ENV='$APP_ENV' is not a local environment." ;;
  esac
  if [ "$DB_CONNECTION" != "sqlite" ] && [ "${WYNCREST_ALLOW_NONLOCAL_RESET:-0}" != "1" ]; then
    die "Refusing to reset a '$DB_CONNECTION' database (only sqlite is auto-reset). Set WYNCREST_ALLOW_NONLOCAL_RESET=1 to override."
  fi
}

# Clear cached config/routes/views so a fresh seed + env vars take effect reliably.
say "Clearing caches…"
php artisan optimize:clear >/dev/null 2>&1 || true

# ---- database: reset + seed (or migrate only) ------------------------------
if [ "$NO_RESET" -eq 1 ]; then
  warn "--no-reset: keeping existing data, running pending migrations only."
  php artisan migrate --force
else
  guard_reset
  say "Resetting database and seeding ($SEED_MODE world)…"
  if [ "$PROD" -eq 1 ]; then
    WYNCREST_SEED_MODE=production \
    WYNCREST_BOOTSTRAP_ADMIN_EMAIL="$ADMIN_EMAIL" \
    WYNCREST_BOOTSTRAP_ADMIN_NAME="$ADMIN_NAME" \
    WYNCREST_BOOTSTRAP_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
      php artisan migrate:fresh --seed --force
  else
    WYNCREST_SEED_MODE=development php artisan migrate:fresh --seed --force
  fi
fi

# Public storage symlink (public/storage -> storage/app/public) so uploaded media
# such as avatars are actually served. Idempotent: skips cleanly if it exists.
if [ ! -e public/storage ]; then
  say "Linking public storage (avatars, media)…"
  php artisan storage:link >/dev/null 2>&1 || warn "storage:link failed; uploaded media may not serve."
fi
ok "Backend ready ($SEED_MODE seed)."

# ---- frontend bootstrap ----------------------------------------------------
if [ ! -d frontend/node_modules ]; then
  say "Installing frontend dependencies (npm install)…"
  (cd frontend && npm install)
fi

# In --prod, build the SPA each run with the API URL baked in (no dev proxy).
if [ "$PROD" -eq 1 ]; then
  say "Building production SPA (VITE_API_BASE_URL=http://localhost:${API_PORT}/api)…"
  ( cd frontend && VITE_API_BASE_URL="http://localhost:${API_PORT}/api" npm run build )
fi
ok "Frontend ready."

# ---- run everything, clean up on exit --------------------------------------
PIDS=()
cleanup() {
  printf '\n'
  warn "Shutting down…"
  for pid in "${PIDS[@]:-}"; do
    # Kill the whole process group of each child so Vite/artisan children also die.
    kill -- "-$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
  ok "Stopped."
}
trap cleanup INT TERM EXIT

# Start each long-running process in its own process group (set -m) so cleanup can
# tear down its children too.
set -m

say "Starting Laravel API on http://localhost:${API_PORT}"
php artisan serve --port="$API_PORT" &
PIDS+=($!)

say "Starting queue worker (notifications, cache jobs)"
php artisan queue:work --tries=1 --quiet &
PIDS+=($!)

if [ "$PROD" -eq 1 ]; then
  say "Serving production SPA build on http://localhost:${WEB_PORT}"
  ( cd frontend && npm run preview -- --port "$WEB_PORT" --strictPort ) &
else
  say "Starting frontend SPA (HMR) on http://localhost:${WEB_PORT}"
  ( cd frontend && VITE_API_PROXY="http://localhost:${API_PORT}" npm run dev ) &
fi
PIDS+=($!)

set +m

# ---- startup summary -------------------------------------------------------
print_summary() {
  printf '\n'
  ok "Wyncrest $MODE_LABEL started"
  printf '   %sFrontend:%s  http://localhost:%s\n' "$c_blue" "$c_reset" "$WEB_PORT"
  printf '   %sAPI:%s       http://localhost:%s   (http://localhost:%s/api)\n' "$c_blue" "$c_reset" "$API_PORT" "$API_PORT"
  printf '   %sSeed mode:%s %s\n' "$c_blue" "$c_reset" "$SEED_MODE"
  if [ "$NO_RESET" -eq 1 ]; then
    printf '   %sDatabase:%s  preserved (--no-reset)\n' "$c_blue" "$c_reset"
  else
    printf '   %sDatabase:%s  reset\n' "$c_blue" "$c_reset"
  fi
  printf '\n'

  if [ "$PROD" -eq 1 ]; then
    printf '   %sAccounts%s\n' "$c_yellow" "$c_reset"
    printf '     Admin:  %s  /  %s\n' "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
    printf '\n'
    printf '   %sExpected counts%s\n' "$c_yellow" "$c_reset"
    printf '     Admins: 1   Landlords: 0   Tenants: 0   Properties: 0\n'
    printf '     Listings: 0   Contracts: 0   Ledger entries: 0\n'
    printf '   %s(Brand-new product — empty states are expected and intentional.)%s\n' "$c_dim" "$c_reset"
  else
    printf '   %sAccounts%s (password: %s)\n' "$c_yellow" "$c_reset" "$DEMO_PASSWORD"
    printf '     %sAdmin%s\n' "$c_green" "$c_reset"
    printf '       admin@wyncrest.test          system administrator\n'
    printf '     %sLandlords%s\n' "$c_green" "$c_reset"
    printf '       landlord.1@wyncrest.test     established — 1 property, 2 tenants, an available listing\n'
    printf '       landlord.2@wyncrest.test     landlord of the owing tenant — listing in review\n'
    printf '       landlord.3@wyncrest.test     smaller — 1 property, 1 tenant, an available listing\n'
    printf '       landlord.4@wyncrest.test     listings-only (limited features), no tenants yet\n'
    printf '       landlord.empty@wyncrest.test EMPTY-STATE — verified, no properties (tests empty dashboards)\n'
    printf '     %sTenants%s\n' "$c_green" "$c_reset"
    printf '       tenant.good1@wyncrest.test   good standing (paid up)\n'
    printf '       tenant.good2@wyncrest.test   good standing (paid up)\n'
    printf '       tenant.good3@wyncrest.test   good standing (paid up)\n'
    printf '       tenant.good4@wyncrest.test   good standing (paid up)\n'
    printf '       tenant.owing@wyncrest.test   owes EXACTLY one month (GH₵2,500)\n'
    printf '\n'
    printf '   %sExpected counts%s\n' "$c_yellow" "$c_reset"
    printf '     Admins: 1   Landlords: 5   Tenants: 5   Good-standing: 4   Owing: 1\n'
    printf '     Properties: 4   Units: 10   Listings: 10   Contracts: 5\n'
    printf '   %sVerify the world + ledger:%s php artisan wyncrest:seed:verify\n' "$c_dim" "$c_reset"
  fi
  printf '   Press Ctrl+C to stop.\n\n'
}
print_summary

# Wait on the servers; if any exits, the trap tears the rest down.
wait
