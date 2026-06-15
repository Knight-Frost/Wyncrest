# Nexus — Security Notes & Audit

OWASP-aligned review performed during project completion. This documents the
controls in place, the audit findings, and operational guidance. Pair with the
production checklist at the bottom of `.env.example`.

## Summary

The backend security posture is **strong**. The audit found **no high or
critical vulnerabilities** — only the hardening additions noted below. Key
structural protections:

- **Financial records use UUID primary keys** (`contracts`, `ledger_entries`),
  defeating IDOR enumeration on the most sensitive resources.
- **Immutable ledger + append-only audit log** give tamper-evidence.
- **Authorization is enforced server-side at three layers**: route middleware
  (role gate) → FormRequest/Policy (ownership) → service checks (sensitive ops).
- **Payments are idempotent in both directions** (intent creation and webhook
  recording dedupe on `stripe_payment_intent_id`).

## Controls In Place (verified)

| OWASP area | Control | Where |
|------------|---------|-------|
| Broken access control / IDOR | Role middleware + per-model Policies + service ownership checks; UUID PKs on financial rows | `app/Http/Middleware`, `app/Policies`, `app/Services/PaymentService.php:74` |
| Authentication | Sanctum bearer tokens; bcrypt (`BCRYPT_ROUNDS=12`); password policy (min 8, mixed case, numbers) | `app/Http/Controllers/AuthController.php` |
| Brute force | Login throttle 5/min per email+IP with lockout + audit log | `AuthController::login` |
| Rate limiting | Role-aware limits (tenant 60 / landlord 120 / admin 300 / public 30 per min) | `app/Http/Middleware/RateLimitByRole.php` |
| Mass assignment | Explicit `$fillable`; registration writes an explicit field array (never spreads request input); privileged fields never mass-assigned | `app/Models/User.php`, `AuthController::register` |
| Injection (SQLi) | Eloquent / query bindings only; no raw string interpolation | throughout |
| XSS | API returns JSON only; SPA escapes by default (React); locked-down CSP on API | `SecurityHeaders` |
| CSRF | Token auth (no ambient cookies) for the API; Sanctum stateful config available for SPA-cookie mode | `bootstrap/app.php:31-35` |
| Webhooks | Stripe signature verification; rejects missing signature and placeholder/empty secret | `app/Http/Controllers/StripeWebhookController.php` |
| Idempotency | Payment intent + webhook recording dedupe on intent id | `app/Services/PaymentService.php` |
| Sensitive data exposure | `$hidden` on password/token; output whitelisted in `formatUser`/`formatAdmin` | `User`, `AuthController` |
| Security headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`, conditional HSTS; strips `Server`/`X-Powered-By` | `app/Http/Middleware/SecurityHeaders.php` |
| Error handling | JSON errors for API; `APP_DEBUG=false` in prod hides stack traces | `bootstrap/app.php`, `.env.example` |
| CORS | Origins restricted via `CORS_ALLOWED_ORIGINS` env (no wildcard) | `config/cors.php` |
| Audit logging | All privileged/sensitive actions logged to immutable `audit_logs` | `app/Services/AuditService.php` |

## Hardening Added During Completion

1. **Content-Security-Policy** `default-src 'none'; frame-ancestors 'none'` on all
   API responses. Safe because the API never serves HTML/JS.
2. **Strict-Transport-Security** (1 year, includeSubDomains) emitted only when the
   request is over HTTPS, so local HTTP development is unaffected.
3. **Regression tests** (`tests/Feature/SecurityHardeningTest.php`) covering the
   headers, registration mass-assignment protection, and webhook signature
   enforcement.

## Accepted Design Decisions / Notes

- **Webhook inner-processing errors return HTTP 200** (after logging) to stop
  Stripe from retrying. Because recording is idempotent, this is safe against
  duplicate delivery; the trade-off is that a *transient* processing failure is
  not retried by Stripe. If stricter delivery guarantees are needed, return 5xx
  on transient errors so Stripe retries (idempotency makes that safe too).
- **Login distinguishes unknown-email from wrong-password** (a product/UX choice
  for clearer feedback). This is a minor user-enumeration trade-off (OWASP A07);
  it is accepted and mitigated by the per-email+IP login throttle (5/min) and
  audit logging. Reverting to a single generic "credentials are incorrect"
  message in `AuthController::login` closes it if enumeration becomes a concern.
- **All admins are super-admins** in the current phase. Granular admin RBAC is
  future work; until then, admin accounts must be tightly controlled.
- **PII is not field-encrypted at rest.** Acceptable for the current data set;
  revisit if storing government IDs / bank details.

## Operational Requirements (production)

- `APP_ENV=production`, `APP_DEBUG=false`, real `APP_KEY`, HTTPS enforced.
- `SESSION_SECURE_COOKIE=true`, real Stripe/Twilio secrets, secure DB creds.
- Set `CORS_ALLOWED_ORIGINS` / `SANCTUM_STATEFUL_DOMAINS` to the deployed SPA only.
- Never commit `.env`, keys, `auth.json`, or `*.sqlite` (all git-ignored).
</content>
