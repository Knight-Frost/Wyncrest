# Nexus — Project Completion Execution Plan

Supervisor: Opus (architect / reviewer / security auditor / final decision-maker).
Workers: Sonnet agents (exploration, implementation, tests, docs, UI, validation).
Baseline at plan time: backend **258/258 tests green**; frontend an empty shell;
whole codebase fails Pint; `app/Legacy/` PSR-4 violation; `backups/` committed.

Phases run in priority order (health → security → RBAC → backend → frontend →
docs). Each phase ends green (tests pass, no regressions) before the next starts.

---

## Phase 0 — Understanding & Forensic Audit  ✅ (complete)

- **Objective:** Full map of backend, frontend, DB, tests, security, RBAC.
- **Why:** No safe change without ground truth. Avoid assumptions.
- **Deliverables:** `CLAUDE.md` (project memory) + this plan.
- **Done when:** Architecture documented; env bootstraps; baseline tests run.
- **Result:** Backend mature & green; frontend empty; tech-debt catalogued.

---

## Phase 1 — Project Health: install / run / build / test

- **Objective:** Clean, reproducible setup; consistent formatting; remove
  structural debt without changing behavior.
- **Why it matters:** A project that can't be cleanly set up or formatted can't
  be trusted or maintained. This unblocks everything else.
- **Files/modules affected:** `composer.json` (autoload), `app/Legacy/*` →
  `app/Models|Events|Listeners/*`, `.gitignore`, `backups/`, whole tree (Pint),
  `frontend/` (deps install).
- **Tasks:**
  1. `.gitignore` `backups/`; remove `backups/` from version control.
  2. Refactor `app/Legacy/*` (multi-class files) into PSR-4 one-class-per-file
     under proper namespaces; delete the composer `files` autoload override.
  3. Run **Pint** across the codebase (isolated style-only change).
  4. Install frontend deps; confirm scripts/config sane.
- **Security requirements:** No secrets enter git; verify `.gitignore` covers
  `.env`, `*.sqlite`, `vendor`, `node_modules`, build output, `backups/`.
- **Testing requirements:** `php artisan test` stays **258 green** after the
  refactor and after Pint. `composer dump-autoload` succeeds.
- **Completion criteria:** Clean `composer install`, `migrate`, `test`; Pint
  reports no diffs; no `app/Legacy/`; `backups/` untracked.
- **Risks:** Refactor could break autoload references → mitigate by keeping
  namespaces identical and running the full suite.
- **Validation:** `pint --test`, `php artisan test`, `composer dump-autoload`.

---

## Phase 2 — Security Audit & Hardening (OWASP)

- **Objective:** Verify and harden against the OWASP Top 10 in this codebase.
- **Why:** Real money + PII. Security is non-negotiable for launch/review.
- **Files/modules:** `SecurityHeaders`, `config/cors.php`, `StripeWebhookController`,
  `AuthController`, `RateLimitByRole`, FormRequests, models (`$fillable`),
  exception rendering in `bootstrap/app.php`.
- **Tasks:** Audit input validation, mass assignment, SQLi (bindings only),
  webhook signature + idempotency, token/session handling, rate limiting/lockout,
  CORS origins, error leakage (`APP_DEBUG`), security headers/CSP, IDOR, secret
  handling, audit logging. Fix gaps found.
- **Security requirements:** All findings remediated or explicitly documented as
  accepted risk in `docs/SECURITY.md`.
- **Testing requirements:** Add regression tests for any fix (e.g. webhook
  rejects bad signature, debug off hides traces, headers present).
- **Completion criteria:** No high/critical findings open; `docs/SECURITY.md`
  written; security tests pass.
- **Risks:** Tightening CORS/headers could break the SPA → validate against the
  dev origin.
- **Validation:** Targeted feature tests + manual request smoke checks.

---

## Phase 3 — Auth, Authorization & RBAC Completeness

- **Objective:** Prove RBAC is complete and enforced; close negative-path gaps.
- **Why:** Privilege escalation / IDOR are the highest-impact bugs here.
- **Files/modules:** middleware, policies, `tests/Feature/*Authorization*`,
  new RBAC/IDOR tests, `AuditService` usage.
- **Tasks:** Verify role→permission→route mapping; add tests proving (a) cross-
  tenant/landlord resource access is denied, (b) tenants can't reach
  landlord/admin routes and vice-versa, (c) no privilege escalation, (d) admin
  actions are audit-logged.
- **Security requirements:** Least privilege everywhere; sensitive admin actions
  audited.
- **Testing requirements:** New negative-authorization + IDOR + escalation tests.
- **Completion criteria:** RBAC matrix in `CLAUDE.md` matches enforced behavior;
  all new tests green.
- **Risks:** Hidden gaps in policies → mitigate with exhaustive negative tests.
- **Validation:** `php artisan test`.

---

## Phase 4 — Backend Completeness & API Consistency

- **Objective:** Close functional gaps; make API responses consistent; harden
  payment/webhook paths.
- **Why:** Consistent, complete contracts make the frontend and integrators sane.
- **Files/modules:** controllers, `PaymentService`, `StripeWebhookController`,
  notification listeners, `database/migrations` (indexes if needed), tests.
- **Tasks:** Standardize JSON envelope where inconsistent; expand payment/webhook
  edge-case coverage (failed/duplicate/refund); wire remaining notification types
  if low-risk; confirm indexes/constraints on hot paths.
- **Security requirements:** Idempotency preserved; no new mass-assignment.
- **Testing requirements:** Payment/webhook negative tests; envelope tests.
- **Completion criteria:** Core flows covered; suite green; no inconsistent
  contracts on touched endpoints.
- **Risks:** Changing response shapes could break consumers → only the SPA
  consumes it (greenfield), so coordinate with Phase 5.
- **Validation:** `php artisan test`.

---

## Phase 5 — Frontend Completion & UI/UX/A11y (the big build)

- **Objective:** A real, premium, accessible React SPA integrated with the API.
- **Why:** The user-facing product currently does not exist.
- **Files/modules:** entire `frontend/src/` (new): `api/`, `context/`, `hooks/`,
  `components/`, `pages/`, `routes/`, `lib/`, `types/`; `tsconfig*.json`,
  Tailwind theme, design tokens.
- **Tasks:** TS/Vite config; Axios client with token interceptor + 401 handling;
  auth context + protected/role routes; login/register; role dashboards (tenant,
  landlord, admin); public listing browse + detail; landlord property/unit/listing
  management + submit; tenant saved listings, contracts, ledger, payment;
  admin moderation queue. Loading/empty/error/success states everywhere;
  responsive; accessible (labels, focus, contrast, keyboard); tasteful motion.
- **Security requirements:** Tokens stored deliberately; UI gating mirrors but
  never replaces server authz; no secrets in the bundle.
- **Testing requirements:** `tsc` typecheck + ESLint clean + `npm run build`
  succeeds; manual smoke of core flows against the running API.
- **Completion criteria:** Build passes; core flows work end-to-end; premium,
  consistent, accessible UI; no placeholder/mock in production paths.
- **Risks:** Scope is large → prioritize core flows, keep components reusable,
  avoid half-built screens (ship complete vertical slices).
- **Validation:** `npm run build`, lint, typecheck, manual smoke.

---

## Phase 6 — Documentation, Final Validation & Report

- **Objective:** Professional docs + full validation + evidence-backed report.
- **Why:** Reviewers and maintainers need an accurate, runnable picture.
- **Files/modules:** `README.md`, `.env.example`, `docs/*`, `CLAUDE.md`.
- **Tasks:** Update README (real run/test/build/deploy steps), API docs, RBAC &
  security notes, known limitations, future work. Run the full validation matrix.
  Produce the final completion report with evidence.
- **Testing requirements:** Backend tests, frontend build+lint+typecheck,
  migrations, Pint — all green.
- **Completion criteria:** Everything installs/runs/builds/tests; docs accurate;
  report delivered with command output.
- **Risks:** Doc drift → generate docs from verified behavior, not assumptions.
- **Validation:** Full matrix re-run captured in the report.

---

### Validation Matrix (run each phase / at the end)

| Check | Command |
|-------|---------|
| PHP deps | `composer install` |
| Format | `./vendor/bin/pint --test` |
| Backend tests | `php artisan test` |
| Migrations | `php artisan migrate:fresh --seed` (sqlite) |
| Frontend deps | `cd frontend && npm install` |
| Frontend lint | `cd frontend && npm run lint` |
| Frontend types | `cd frontend && tsc -b` |
| Frontend build | `cd frontend && npm run build` |
</content>
