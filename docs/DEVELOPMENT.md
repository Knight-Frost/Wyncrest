# Development

How to set up and run Wyncrest on your own machine.

Who this is for: developers setting up the project for the first time, or coming back to it after a while.

## Prerequisites

| You need | Why |
|---|---|
| PHP 8.2 or newer, with Composer | Runs the backend |
| Node 18 or newer, with npm | Runs the frontend |
| SQLite | The default local database, bundled with PHP, no separate install needed |

MySQL or PostgreSQL can be used instead of SQLite if you prefer, but nothing extra is required to get started.

## The fastest way to start: the dev runner

Wyncrest includes a single script, `dev.sh`, that resets the local database, fills it with realistic demo data, and starts the backend and frontend together.

| Command | What it does |
|---|---|
| `./dev.sh` | Full development world: demo data, backend API, frontend with live reload |
| `./dev.sh --prod` | A clean production-style preview: no demo data, just one admin account |
| `./dev.sh --no-reset` | Starts everything without wiping existing local data |
| `./dev.sh --help` | Shows all available options |

The reset step refuses to run against anything other than a local SQLite database, so it cannot accidentally wipe a real database.

## Setting up manually

If you would rather run the backend and frontend yourself instead of using the dev runner:

**Backend**

| Step | Command |
|---|---|
| Install dependencies | `composer install` |
| Copy the example environment file | `cp .env.example .env` |
| Generate an application key | `php artisan key:generate` |
| Create the local database file | `touch database/database.sqlite` |
| Build the schema and seed demo data | `php artisan migrate:fresh --seed` |
| Start the backend | `php artisan serve` |

The backend runs at `http://localhost:8000` by default.

**Frontend**

| Step | Command |
|---|---|
| Move into the frontend folder | `cd frontend` |
| Install dependencies | `npm install` |
| Start the frontend | `npm run dev` |

The frontend runs at `http://localhost:5173` by default, and forwards API requests to the backend automatically, so no extra configuration is needed for local development.

## Resetting local data

Running `php artisan migrate:fresh --seed` (or simply re-running `./dev.sh`) wipes the local database and rebuilds it with fresh demo data. This is always safe locally: the reset is guarded so it refuses to touch anything but a local SQLite database. Full detail on what the demo data looks like: [`docs/SEEDING.md`](SEEDING.md).

## Verifying the demo data is correct

Wyncrest ships a verification command, `php artisan wyncrest:seed:verify`, that checks the demo world was built correctly: the right number of accounts exist, the ledger balances are mathematically consistent, and nothing was skipped. It fails loudly if anything looks wrong.

## Common local problems

See [`docs/TROUBLESHOOTING.md`](TROUBLESHOOTING.md) for a full table of problems and fixes, including port conflicts, login failures, and build errors.

## What not to commit

| Never commit | Why |
|---|---|
| `.env` | Contains local secrets and configuration |
| `database/database.sqlite` | Local data only, not shared |
| `vendor/` and `node_modules/` | Installed automatically, not source code |
| Build output (`frontend/dist`) | Generated automatically |
| Log files | Local noise, not project history |

These are already excluded by the project's `.gitignore`.

## Where to find important files

| Looking for | Location |
|---|---|
| Backend application code | `app/` |
| API route definitions | `routes/` |
| Database migrations and seeders | `database/` |
| Backend tests | `tests/` |
| Frontend application code | `frontend/src/` |
| Project documentation | `docs/` |
| Environment variable reference | `.env.example` |
