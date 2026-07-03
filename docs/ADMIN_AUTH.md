# Admin Authentication Architecture

> The admin console uses a **first-party, HttpOnly cookie session**. Tenants and
> landlords keep their **Sanctum bearer tokens**. The two are deliberately
> isolated. This document explains why, how it is wired, and how to work with it.

---

## 1. Why cookies for admins (the bug this fixes)

The admin SPA previously stored a Sanctum bearer token in `localStorage` under a
key scoped to the **portal**, not the **account**: `nexus.auth.admin.token`.

That single slot created an identity/credential divergence:

1. `localStorage` is shared across all tabs of a browser profile.
2. Logging in as a second admin overwrote the first admin's token.
3. Existing tabs kept their **cached React identity** ("Wyncrest Admin").
4. The API client read the **latest** token from storage on every request.
5. So a tab could show one admin while transmitting another admin's token.
6. The backend correctly returned `403`; the UI looked like the super admin was
   "locked out" of Users, Contracts, Ledger, and Manage Access.

The root cause was **trusting a client-stored value as proof of identity**. The
fix removes that value entirely: the credential is now an HttpOnly cookie the
browser manages and JavaScript cannot read, and the *only* source of admin
identity is the backend (`GET /api/admin/me`).

## 2. The two isolated auth models

| | Tenant / Landlord | Admin console |
|---|---|---|
| Credential | Sanctum **bearer token** (`Authorization: Bearer …`) | **HttpOnly session cookie** |
| Stored in JS | `localStorage`/`sessionStorage` | **nothing** (cookie is HttpOnly) |
| Guard | `auth:sanctum` | `auth:admin` (native session guard) |
| Login | `POST /api/login` | `POST /api/admin/login` (+ CSRF) |
| Identity | `GET /api/user` | `GET /api/admin/me` |
| Logout | `POST /api/logout` | `POST /api/admin/logout` |
| CSRF | n/a (no ambient cookie) | required (`/sanctum/csrf-cookie`) |

**Why not one shared mechanism?** Sanctum's guard resolves a *session* before a
*bearer token* (`vendor/laravel/sanctum/src/Guard.php`). If admin sessions rode
the shared `auth:sanctum` pipeline, a browser holding an admin cookie would be
resolved as the admin on tenant/landlord requests too — recreating the exact
divergence, but worse. So admin cookie auth is scoped to the admin routes only,
and the `admin` guard is intentionally **absent** from `config('sanctum.guard')`.

## 3. Request lifecycle

```
SPA boot / admin login
  → GET  /sanctum/csrf-cookie         (sets XSRF-TOKEN cookie)
  → POST /api/admin/login             (X-XSRF-TOKEN header; sets session cookie)
      Auth::guard('admin')->login(); session()->regenerate()
      returns { user: {...} }         ← NO token in the body
  → GET  /api/admin/me                (cookie only) → the authenticated admin
  → GET/POST/PATCH /api/admin/*       (cookie; mutations send X-XSRF-TOKEN)
  → POST /api/admin/logout            → session()->invalidate()
```

The SPA's admin axios instance sets `withCredentials: true` and `withXSRFToken:
true`; it never attaches an `Authorization` header. See
`frontend/src/lib/api.ts` (`makeCookieClient`, `ensureAdminCsrf`).

## 4. Backend wiring

- **Guard:** `config/auth.php` → `guards.admin` (session driver, `admins` provider).
- **Sanctum:** `config/sanctum.php` → `guard => ['web']` (admin removed on purpose).
- **Routes:** `routes/api.php` — the admin group runs on
  `['web', 'auth:admin', 'auth.session', 'admin', 'rate.limit.role']`. `web`
  supplies session + CSRF; `auth.session` (`AuthenticateSession`) binds the
  session to the password hash so a password change ends other sessions.
- **Controller:** `App\Http\Controllers\Auth\AdminAuthController` (login / me /
  logout / password). Rate-limited; audit-logged; no user enumeration.
- **Named `login` route:** `routes/web.php` defines a `login` route returning
  `401` JSON. Laravel's auth middleware computes a redirect to `route('login')`
  for non-JSON unauthenticated requests (e.g. a direct file-download link);
  without it that call throws. It exists solely so those resolve cleanly.

## 5. `401` vs `403` (kept distinct)

- **`401 Unauthenticated`** — no valid admin session. A tenant/landlord bearer
  identity hitting an admin route is `401` (they are not authenticated *as an
  admin*), as is a guest.
- **`403 Forbidden`** — an authenticated admin who lacks the required capability
  (`EnsureAdminCan`) or whose account is deactivated (`EnsureAdmin`).

A bearer-authenticated user hitting *another portal's* route (e.g. a tenant on a
landlord route) stays `403` — they are authenticated, just the wrong role.

## 6. View vs manage permissions

Capability gating is unchanged by this work and enforced server-side:

- Reading the user roster, contracts, and ledger is available to any active
  admin. **Mutations** are capability-gated (`manage_users`, `manage_contracts`,
  `manage_ledger`, …).
- **Manage Users & Permissions** requires `manage_access` (super admins bypass
  all capabilities). The SPA hides actions it can't perform, but the API is the
  real boundary.

## 7. Testing multiple admins safely

A cookie session is **one identity per browser profile** — this is correct,
production-grade behavior, not a limitation to work around. To exercise two
admins at once, use **separate browser profiles, private/incognito windows, or
different browsers**. Do not re-introduce multi-account token storage to fake
concurrent admin sessions.

In PHPUnit, authenticate an admin with the native guard:

```php
$this->actingAs($admin, 'admin')->getJson('/api/admin/...');
```

(`Sanctum::actingAs($admin, [], 'sanctum')` will **not** authenticate admin
routes anymore — they use the session guard.)

## 8. Deployment notes

- **HTTPS is required in production.** Session cookies are `Secure` when
  `APP_ENV=production` (`config/session.php`), and browsers drop `Secure`
  cookies over plain HTTP. Set `SESSION_SECURE_COOKIE=true`,
  `SESSION_SAME_SITE=lax`, and point `SANCTUM_STATEFUL_DOMAINS` /
  `CORS_ALLOWED_ORIGINS` at the SPA origin.
- **Same-origin is simplest.** Serving the SPA and API on one origin (the nginx
  setup) needs no CORS and no cross-site cookie concerns. In dev, Vite proxies
  `/api`, `/sanctum`, and `/storage` to the backend so the browser stays
  same-origin.
- **The HTTP-only EC2 demo cannot use cookie auth** until it has a domain + TLS
  (Caddy/Let's Encrypt or an HTTPS load balancer). Over plain `http://<ip>` the
  `Secure` session cookie is dropped and admin login will not persist. This is a
  deliberate, documented blocker — do **not** disable `Secure` cookies to work
  around it.

## 9. Migration notes (from the localStorage design)

- On boot the SPA deletes any legacy `nexus.auth.admin.token`
  (`clearDeprecatedAdminToken`). A stale token can never authenticate an admin
  request again — the admin routes only accept the session cookie.
- Admins now sign in at **`/admin/login`** (not `/login`). An admin email entered
  on the tenant/landlord `/login` falls through to the user lookup and returns
  the generic "no account" response (no enumeration, no token issued).
