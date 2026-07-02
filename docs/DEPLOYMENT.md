# Deployment

How to deploy Wyncrest, and a real example of a live deployment.

Who this is for: anyone deploying Wyncrest, or reviewing how the live demo box is set up.

## Environment configuration

Copy `.env.example` to `.env` and set at minimum:

| Variable | Purpose |
|---|---|
| `APP_ENV` | Set to `production` for any real deployment |
| `APP_DEBUG` | Must be `false` in production, so errors never show stack traces |
| `APP_KEY` | Generated once with `php artisan key:generate` |
| `APP_URL` | The backend's real address |
| `FRONTEND_URL` | The frontend's real address |
| `SANCTUM_STATEFUL_DOMAINS` | Must include the frontend's production address |
| `CORS_ALLOWED_ORIGINS` | Must include the frontend's production address |
| `DB_CONNECTION` | `sqlite` by default; `mysql` or `pgsql` for larger deployments |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | Required for real rent payments |
| `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` | Required for SMS notifications |

Brand strings (`BRAND_*`) let the product name be changed without touching source files. See `.env.example` for the full list.

## Production checklist

| Check | Why |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY` | Prevents debug information from leaking |
| HTTPS enforced, secure session cookies | Protects login sessions in transit |
| Real Stripe and Twilio credentials | Payments and SMS stay safely disabled without them |
| Config and route caches rebuilt after any change | `php artisan config:cache route:cache` |
| A real queue worker and scheduler running | Needed for rent generation, overdue marking, and digest emails |
| CORS and Sanctum settings point only at the real frontend address | Prevents other sites from making authenticated requests |
| Database credentials are least-privilege | Limits damage if credentials leak |
| Backups configured | Protects against data loss |
| Rate limiting confirmed active and not bypassed by a proxy | Protects against brute-force attempts |

This is a checklist, not something the framework enforces automatically.

## The live demo deployment

A real demo box is running at a public address. It is an honest, working deployment, but a deliberately minimal one, not a hardened production reference. It runs over plain HTTP with no domain name, so secure-cookie based flows and Stripe webhooks do not work there. Login still works because it uses token-based authentication, not cookies.

| Fact | Detail |
|---|---|
| Server | A small cloud instance running Amazon Linux |
| Web stack | PHP with php-fpm, and nginx as the web server |
| Serving model | nginx serves the built frontend directly, and forwards API requests to the backend, so there is one address and no cross-origin setup needed |
| Database | SQLite, a single file on the server |
| Background jobs | Run synchronously; no separate queue worker is needed for this small box |
| Demo data | Development-mode seed data, clearly fictional, running with an explicit safety flag that allows demo data outside a strictly local environment |

### Redeploying after code changes

| Step | What happens |
|---|---|
| 1 | Build the frontend locally |
| 2 | Copy the updated files to the server, excluding local-only files like `.env` and the database |
| 3 | On the server, install any new dependencies, run pending database migrations, and rebuild the config and route caches |
| 4 | Reload the web server so the new code takes effect |

### Known limitations of this demo box

No TLS or custom domain, no real Stripe, Twilio, or Google credentials (so those features stay safely disabled), no background queue worker or scheduler, and SQLite instead of a production-grade database. It exists to demonstrate the product, not to model a hardened production environment.

## Security hardening

The same hardening applies to any real deployment, not just the demo box:

- Rate limiting is role-aware and already implemented.
- CORS is restricted to known addresses, never left open to everything.
- All database access goes through safe, parameterized queries.
- The API only ever returns JSON, and the frontend escapes what it displays by default.
- The host should use a firewall and key-only SSH access.
- Database backups should be regular and tested.
- Confirm `APP_DEBUG=false` before anything goes live.

Full security model: [`../SECURITY.md`](../SECURITY.md).
