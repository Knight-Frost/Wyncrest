# Wyncrest Engine Architecture

> The central "courthouse of truth" for Wyncrest's most sensitive domains.
> Read this before touching money, contracts, verification, or audit logic.

Wyncrest manages rent, payments, contracts, applications, verification,
maintenance, notifications, analytics, and audit history. Because these areas
create legal, financial, and trust consequences, the business rules for each
must live in **one place** — not scattered across controllers, commands, jobs,
or (worst of all) the frontend.

This document defines what an "engine" is in Wyncrest, which engines exist,
what each one owns and must **not** own, and how to add or test one safely.

---

## 1. What "engine" means here

Wyncrest distinguishes two kinds of backend class. The distinction is a real
rule, not decoration:

| Kind | Responsibility | Side effects | Example |
|------|----------------|--------------|---------|
| **Engine** | Deterministic **rules & computation** over real DB records | None / minimal | `LedgerComputationEngine`, `BillingPeriodCalculator` |
| **Service** | **Orchestrates** a business action: mutate state + audit + notify, through one entry point | Yes, named by the method | `ContractLifecycleService`, `ApplicationService` |

The word **"engine domain"** (used below) is broader than the `*Engine` suffix.
An engine domain is *the single place that owns a truth*; it may be realized by
a pure `*Engine` class, an orchestrating `*Service`, or a small cluster of both.
The `*Engine` class-name suffix is reserved for the pure-computation classes.

**We deliberately did NOT rename the 45 existing services to `*Engine`.** Most
were already single-entry-point centralizers; renaming them would have churned
hundreds of imports and a 1,000+ test suite for zero behavior gain. Identity as
an engine is defined by this document and enforced by tests, not by a filename.

---

## 2. Why engines exist

- **Financial/legal correctness.** Anything affecting rent, balances, payments,
  late fees, contracts, verification decisions, admin decisions, or audit
  history must be backend-driven, traceable, and testable.
- **One source of truth.** Every portal (tenant / landlord / admin / super
  admin) reads the same numbers and states. A dashboard can never disagree with
  the ledger page because both call the same engine.
- **The frontend displays truth; it never computes it.** No React page invents a
  balance, contract state, verification decision, permission, application status,
  maintenance urgency, or analytics figure. The API is the source of truth; the
  SPA renders pre-decorated backend data.
- **Idempotency & explicit transitions.** Duplicate-dangerous operations
  (payments, rent generation, late fees, notifications) are idempotent, and
  status changes are explicit guarded transitions rather than magic boolean
  flips.

---

## 3. The nine engine domains

Priority tiers reflect blast radius: financial/legal core first.

### Tier 1 — Financial & legal core

#### 3.1 Ledger Engine
The official ledger computation + write layer. **The single source of financial
truth.** See also `docs/LEDGER.md` for the sign convention and the incident that
motivated it.

- **Owns:** every money figure derived from the ledger — balances (tenant /
  contract), running balance, rent charged, collected, outstanding, overdue,
  due-soon, per-entry display semantics, payment-status derivation, billing
  period + due-date math, rent generation, late fees, waiving, manual/offline
  payment recording, reconciliation/integrity checks.
- **Must NOT own:** Stripe gateway specifics (that's the Payment Engine),
  authorization, or any frontend-side arithmetic.
- **Classes:**
  - `Services\Ledger\LedgerComputationEngine` — **pure** read model / calculator.
    Canonical sign convention: obligations (rent/late_fee) positive, payments
    negative; a balance is the signed sum. Every user-facing money figure flows
    through here.
  - `Services\Ledger\BillingPeriodCalculator` — pure period + due-date math.
    Keeps the two historical due-date anchors (start-anchored for the first /
    sequential generators, end-anchored for the automated generator) as distinct
    documented methods; see §7 known limitations.
  - `Services\Ledger\PaymentEntryFactory` — the single PAYMENT-entry attribute
    shape shared by Stripe + manual settlement (see Payment Engine).
  - `Services\Ledger\LedgerReconciliationService` — read-only 10-point integrity
    audit; cross-checks the engine against itself.
  - `Services\LedgerService` — ledger **writes** as business actions: first-rent,
    late fee, manual/offline payment, waive.
  - `Services\LedgerAutomationService` — time-based automation: current-period
    rent generation, overdue marking (idempotent).
  - `Services\LandlordLedgerService` — landlord-console read projections; fully
    delegates money math to the engine.
  - `Models\LedgerEntry` — immutable (update/delete throw; `UPDATED_AT = null`);
    the only mutation path is `transitionStatus()`, a compare-and-swap.
- **Called by:** tenant/landlord/admin ledger + dashboard controllers, the
  `ledger:generate-rent`, `ledger:mark-overdue`, `ledger:reconcile`,
  `ledger:summary` commands, and the analytics engine.
- **Idempotency:** rent unique per `(contract, billing_period_start, end)`; late
  fee unique per `(related_rent_entry_id, late_fee)`; payments unique per
  `stripe_payment_intent_id` + status CAS.

#### 3.2 Payment Engine
The Stripe/online payment behavior layer. **Payment Engine says a payment
happened; the Ledger Engine decides how the balance is affected.**

- **Owns:** Stripe PaymentIntent creation, webhook-driven success/failure
  recording, amount/currency validation against the obligation, triple-layer
  idempotency (pre-check + locked re-check + status CAS), tenant-balance
  passthrough (delegated to the ledger engine).
- **Must NOT own:** the definition of a balance (delegates to
  `LedgerComputationEngine::computeTenantBalance`), or the PAYMENT-entry shape
  (shared via `PaymentEntryFactory`).
- **Classes:** `Services\PaymentService`, `Services\Ledger\PaymentEntryFactory`
  (shared shape), `Http\Controllers\StripeWebhookController` (signature-verified,
  no financial logic). Manual/offline payments live in
  `LedgerService::recordManualPayment` and use the same `PaymentEntryFactory`.
- **Called by:** `StripeWebhookController`, `TenantPaymentController`.
- **Critical invariant:** the settlement transaction (row lock + status CAS +
  duplicate handling) is the site of a previously-fixed double-charge bug and
  must be changed with extreme care. `PaymentEntryFactory` owns only the entry
  *shape*, never the locking/idempotency logic.

#### 3.3 Contract Engine
The contract lifecycle authority. Answers: is this contract active? can it be
activated / terminated / expired?

- **Owns:** every contract status transition — draft → pending_tenant → active →
  terminated | expired — with an explicit source-status **guard** (throws
  `InvalidContractTransitionException` on an illegal transition), the status
  mutation, the audit entry, and recipient notifications.
- **Must NOT own:**
  - **Authorization** — controllers keep their `ContractPolicy` checks
    (ownership + state) and the admin 422 pre-check. The engine guard is a
    defense-in-depth invariant that matches the policies exactly, not the gate.
  - **Rent generation** — `ContractObserver` generates first rent when a
    contract becomes ACTIVE; `accept()` only sets ACTIVE and the observer reacts.
  - **Creation & renewal** — a draft is created directly, and renewal mutates
    dates not status; neither is a status transition.
- **Classes:** `Services\Contracts\ContractLifecycleService` (methods: `send`,
  `accept`, `terminateByTenant`, `terminateByLandlord`, `forceTerminateByAdmin`,
  `expire`), `Services\Contracts\InvalidContractTransitionException`,
  `Models\Contract` (state helpers `canBeAccepted`/`canBeTerminated`),
  `Observers\ContractObserver` (rent trigger + cache invalidation). Read-side
  case-file assembly is `Services\ContractCaseFileService`.
- **Called by:** `Tenant\TenantContractController`,
  `Landlord\LandlordContractController`, `Admin\AdminContractController`, and the
  `contracts:mark-expired` command.

#### 3.4 Audit Engine
Standardized, tamper-evident audit logging. Do **not** weaken its immutability.

- **Owns:** append-only audit event creation with a SHA-256 **hash chain**
  (every row commits to the previous row's hash), actor/subject tracking,
  before/after metadata, and chain verification/export.
- **Must NOT own:** business decisions (it records them; it does not make them).
- **Classes:**
  - `Services\AuditService` — the **writer** (canonical entrypoint; a generic
    `log()` plus typed `log*()` helpers). This is what other engines call.
  - `Services\AuditLogService` — the **reader/verifier/exporter** (paginate,
    summary, `verifyChain`, export). Distinct responsibility — **do not merge the
    two.**
  - `Services\Audit\AuditEventPresenter` — human-readable presentation.
  - `Models\AuditLog` — append-only (`UPDATED_AT = null`); the hash link is
    computed on every create path.
- **Called by:** essentially every other engine and privileged controller
  (~40 call sites) via `AuditService`.

### Tier 2 — Workflow & decision engines

#### 3.5 Application Engine
Rental application workflow. Already a clean centralizer (an engine in all but
name — not renamed, per §1).

- **Owns:** every application status transition (draft → submitted → needs_action
  → approved | rejected | withdrawn), the append-only `application_events`
  timeline, request-more-info loop, document attachment, and the audit +
  notification side effects — all through one service.
- **Must NOT own:** the contract that results from an approval (that is the
  Contract Engine's domain), or missing-requirements logic duplicated in the
  frontend (the backend computes it).
- **Classes:** `Services\ApplicationService` (`createDraft`, `saveDraft`,
  `submit`, `withdraw`, `deleteDraft`, `attachDocument`, `requestInfo`,
  `markOpenedByLandlord`, `recordDecision`, `recordEvent`),
  `Models\Application`, `ApplicationEvent`, `ApplicationRequest`.

#### 3.6 Verification Engine
Identity/document verification. Clean write/read split.

- **Owns:** verification decisions (submit / approve / reject / request-more-info),
  the decision trail + notifications, and the hard server-side gate ("is this
  user verified?") that blocks landlords from submitting listings and tenants
  from applying until verified.
- **Classes:** `Services\VerificationService` (**decisions**: `submit`,
  `approve`, `reject`, `requestMoreInfo`), `Services\VerificationCaseService`
  (**read**: `summary`, `reviewTimingMetrics`, `paginate`, `caseDetail`,
  `addNote`), `Models\VerificationRequest`, `VerificationNote`.

#### 3.7 Maintenance Engine
Maintenance request lifecycle across tenant/landlord/admin. Already a mature
transition centralizer.

- **Owns:** maintenance request creation (tenant/landlord), status lifecycle
  (acknowledge → assign → in-progress → waiting → resolve → close/reopen, plus
  admin overrides), the `maintenance_events` timeline, and audit + notification
  side effects.
- **Classes:** `Services\MaintenanceService` (the transition centralizer),
  `Services\Admin\MaintenanceOverviewService` (admin read projections),
  `Models\MaintenanceRequest`, `MaintenanceEvent`, and the maintenance enums.
- **Note:** this domain is under active development (admin-oversight features).
  New maintenance behavior should continue to route through `MaintenanceService`
  rather than re-implementing transitions in controllers.

### Tier 3 — Communication & reporting engines

#### 3.8 Notification Engine
In-app / email / SMS notifications and their delivery.

- **Owns:** in-app notification **creation** with event-id **deduplication**
  (`NotificationService::exists()` guards dangerous repeats), preference
  resolution, and multi-channel **delivery** with failed-delivery tracking +
  retry.
- **Must NOT own:** deciding *when* a domain event happened — engines/events call
  it; controllers should not hand-roll notification logic.
- **Classes:** `Services\NotificationService` (create/read in-app +
  `exists()` dedup), `Services\NotificationDeliveryService` (deliver / retry /
  failed counts), `Services\PreferenceResolver` (per-user channel preferences),
  `Services\NotificationDigestService`, `Services\SmsDeliveryService`,
  `Services\Sms\*`, `Observers\NotificationObserver`,
  `Listeners\CreateNotificationListener`.
- **Delivery** runs via the `notifications:deliver`, `notifications:deliver-sms`,
  and daily/weekly digest commands.

#### 3.9 Analytics Engine
Read-only analytics for every portal. Reads backend truth; never invents numbers
to satisfy a mockup.

- **Owns:** scoped financial / contract / application / notification / platform /
  super-admin analytics, computed from real records and cached with scoped keys +
  selective invalidation.
- **Must NOT own:** its own financial math. Financial figures delegate to
  `LedgerComputationEngine` (as of the engine consolidation,
  `FinancialAnalyticsService` was fixed to stop reimplementing — and
  disagreeing with — the ledger's sign convention).
- **Classes:** `Services\Analytics\{Financial,Contract,Application,Notification,
  Platform,SuperAdmin}AnalyticsService`, `Services\Admin\AdminAnalyticsService`,
  `Services\LandlordAnalyticsService`, cache in `Support\Cache\*` +
  `Jobs\InvalidateAnalyticsCacheJob`.

---

## 4. How the frontend consumes engine truth

- Pages call the API and **render** what comes back. They do not recompute
  balances, statuses, permissions, or aggregates client-side.
- Money is delivered pre-decorated (e.g. `display_amount_cents`,
  `balance_impact_cents`, `running_balance_cents`) by the Ledger Engine; the SPA
  formats, it does not derive.
- UI authorization is cosmetic. The API (policies + engine guards) is the real
  gate; hiding a button never substitutes for a server-side check.

---

## 5. How to add a new engine safely

1. **Check what exists first.** Most domains already have a service that is an
   engine in all but name. Prefer organizing/extending it over creating a new
   class. Do not create a wrapper that only forwards to existing methods.
2. **Pick the kind.** Pure computation/rules → an `*Engine` class. Orchestration
   with side effects → a `*Service`.
3. **One entry point per transition.** Guard the source state, mutate, audit via
   `AuditService`, notify via `NotificationService` (with an `event_id` for
   dedup). Fail loudly on invalid states (throw, don't silently no-op).
4. **Do not move authorization into the engine.** Keep policy/`authorize()` in
   controllers/FormRequests. An engine guard is defense-in-depth and must match
   the policy, not replace it.
5. **Respect idempotency** where duplicates are dangerous (payments, rent, late
   fees, notifications) — a unique key or a compare-and-swap, never a blind
   insert.
6. **Preserve immutability contracts** (ledger entries, audit log). Never add an
   update/delete path around them.
7. **Document the engine here** with its owns / must-not-own boundaries.

---

## 6. How to test an engine

- **Pure engines** (`LedgerComputationEngine`, `BillingPeriodCalculator`) get
  fast unit tests that pin exact outputs, including edge cases (month-end due
  dates, sign convention). See `tests/Unit/Ledger/BillingPeriodCalculatorTest`.
- **Orchestrating services** get feature tests that assert the full effect:
  status change + audit row + notification + any triggered side effect. See
  `tests/Feature/ContractLifecycleServiceTest` (guards) and
  `LifecycleNotificationsTest` (happy-path notifications).
- **Financial correctness** is cross-checked by
  `LedgerReconciliationService` and `LedgerComputationEngineTest`.
- Reuse existing factories/seeders. Avoid brittle wall-clock assumptions — freeze
  time with `Carbon::setTestNow` for any due-date/overdue assertion (the suite
  runs in UTC; "due today" tests flake after midnight UTC otherwise).
- Run `composer test` (or scope to the relevant slice). Keep the suite green per
  commit.

---

## 7. Known limitations (honest)

- **Two due-date anchors coexist.** `BillingPeriodCalculator` preserves both the
  start-anchored (first/sequential) and end-anchored (automated) due-date rules
  because unifying them would move real, legally-meaningful rent due dates.
  Reconciling them is a deliberate future decision, not a silent refactor.
- **`FinancialAnalyticsService.total_overdue_amount`** is a status-based
  (confirmed-`OVERDUE`) figure — a subset of the engine's overdue definition
  (which also treats pending-past-due as overdue), kept for determinism.
  `total_outstanding_balance` and the integrity checks now go through the engine.
- **Ledger `refund` type** is defined in the schema/enum but no writer creates
  refunds today; it is intentionally dead, not wired.
- **Revenue analytics are accrual-style** (`total_payments_received` is
  paid-rent recognized, not cash-basis PAYMENT entries). This is intentional and
  separate from the ledger's cash-basis `computeCollected`.
- **Analytics facades** were intentionally not created — controllers assemble the
  focused analytics services directly; a pass-through facade would add no value.
- **The `Engine` suffix was not applied** to the mature workflow/communication
  services (Application, Verification, Maintenance, Notification). They are engines
  by responsibility (this document); renaming them was rejected as pure churn.

---

## 8. Cross-references

- `docs/LEDGER.md` — ledger sign convention, immutability, the collected/overdue
  incident writeup.
- `docs/ARCHITECTURE.md` — the layered request lifecycle (route → FormRequest →
  controller → service → model → observer/event/listener).
- `docs/AUTHORIZATION.md` — the RBAC model the engines must respect (never weaken).
