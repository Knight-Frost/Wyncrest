# Wyncrest Seeded Scenario Guide

The complete map of the development seed world: who exists, why, and exactly what
each account lets you test. Every account earns its place — there is no filler.

Build (or rebuild) this world with:

```bash
php artisan migrate:fresh --seed          # local default = development mode
php artisan wyncrest:seed:verify          # prove the graph + ledger are consistent
```

Everything below is **fictional demo data** on the reserved, non-routable
`wyncrest.test` domain. None of it exists in production mode. See
[`SEEDING.md`](SEEDING.md) for how the two modes work.

> **Truth guarantee.** Nothing here is faked. Every count, balance, badge and
> status is backed by a real model/service/policy. Two requested scenarios are
> **not backend-supported** and were deliberately **not** seeded — see
> [Unsupported scenarios](#unsupported-scenarios).

## Shared local password

```
password
```

Used by **every** seeded account. Local development only — never a production
credential.

---

## Admin accounts (4)

Admins live in a separate `admins` table and sign in at **`/admin/login`** (cookie
session, not bearer token). Two scoped admins are mirror images of each other, so a
`403` is demonstrable in **both** directions (each is denied what the other allows).

| Email | Role | Capabilities | Purpose / what to test |
|---|---|---|---|
| `admin@wyncrest.test` | **Super admin** | Everything (bypasses all capability checks) | Full dashboard, manage access/users, verification & listing review, ledger, audit log, super-admin bypass |
| `reviewer@wyncrest.test` | Scoped — **content** | `review_verifications`, `moderate_listings`, `moderate_reviews`, `view_audit` | Allowed: verification queue, listing moderation, review moderation, audit. **Denied (403):** manage access, contracts, ledger |
| `finance@wyncrest.test` | Scoped — **finance** | `manage_contracts`, `manage_ledger`, `view_analytics`, `view_audit` | Allowed: contracts, ledger, analytics, audit. **Denied (403):** moderate listings/reviews, review verifications, manage access |
| `pending.admin@wyncrest.test` | **Invited, not accepted** | `manage_users` (not usable) | Invite lifecycle UI — created but never accepted, has **no usable password**, cannot log in |

---

## Landlord accounts (7 catalog)

All landlords are tenants/landlords in the `users` table (bearer-token auth, sign in
at `/login`).

| Email | Verification | Account | Features | Properties | Purpose / what to test |
|---|---|---|---|---|---|
| `landlord.1@wyncrest.test` | verified | active | full | Ridge Court (Cantonments) | Healthy landlord: active tenants, a former (expired) lease, an available listing, applications, reviews, messaging |
| `landlord.2@wyncrest.test` | verified | active | full | Harbour View (Osu) | Landlord of the **owing** + **late-fee** tenants; a listing **pending review**; the overdue-rent message thread |
| `landlord.3@wyncrest.test` | verified | active | full | Garden Villas (Kumasi) | 1 active tenant + 1 **terminated** lease; an available listing |
| `landlord.4@wyncrest.test` | verified | active | **limited** (listings only) | Tema Residences | Feature-gated dashboard: listings but no applications/leases/payments/maintenance |
| `landlord.empty@wyncrest.test` | verified | active | full | **none** | **Empty-state** landlord dashboard (verified, full features, zero inventory) |
| `landlord.pending@wyncrest.test` | **pending** | active | **none** | none | **Identity hard-gate:** cannot submit a listing until verified; appears in the admin verification queue |
| `landlord.suspended@wyncrest.test` | verified | **suspended** | none | none | **Account governance:** login is rejected by middleware; admin reactivate flow; `account_suspended` notification + audit |

### Standalone verification-queue accounts (5)

Created by `VerificationSeeder` (not the catalog) purely to keep the admin
**verification review queue** non-empty. They have no inventory and don't ripple
into the property/ledger graph. Same password.

| Email | Type | Verification request state | Tests |
|---|---|---|---|
| `verify.landlord.pending@wyncrest.test` | landlord | **pending** (ID only, proof of address to follow) | Pending review card |
| `verify.tenant.pending@wyncrest.test` | tenant | **pending** (ID attached) | Pending review card + doc preview |
| `verify.tenant.needsinfo@wyncrest.test` | tenant | **needs_more_information** (+ reviewer note) | Needs-info decision + internal notes |
| `verify.tenant.nodocs@wyncrest.test` | tenant | **pending, zero documents** | Missing-document warning state |
| `verify.tenant.resubmitted@wyncrest.test` | tenant | **rejected → resubmitted** (2 requests) | Previous-attempts / resubmission history |

---

## Tenant accounts (9 catalog)

| Email | Verification | Lease | Balance | Purpose / what to test |
|---|---|---|---|---|
| `tenant.good1@wyncrest.test` | verified | active (Ridge Court 2B-04) | **GH₵0** | Healthy tenant: dashboard, paid ledger history, a fully-read message thread, saved listing |
| `tenant.good2@wyncrest.test` | verified | active (Ridge Court 3B-02) | GH₵0 | Good tenant + a **live application** to an available unit + an unread-by-landlord message |
| `tenant.good3@wyncrest.test` | verified | active (Harbour View ST-01) | GH₵0 | Good tenant, **empty inbox** (empty-state messaging) |
| `tenant.good4@wyncrest.test` | verified | active (Garden Villas TH-A) | GH₵0 | Good tenant, **empty inbox**; a live application |
| `tenant.owing@wyncrest.test` | verified | active (Harbour View GA-05) | **GH₵2,500** | **Overdue rent:** exactly one clean overdue month (no late fee); overdue notification; a **failed-payment** trace (notification + audit, no ledger row); unread landlord message |
| `tenant.latefee@wyncrest.test` | verified | active (Harbour View GA-06) | **GH₵2,860** | **Overdue rent + a real late fee** (GH₵2,600 rent + GH₵260 = 10% fee, raised via `LedgerService::generateLateFee`); late-fee notification |
| `tenant.former@wyncrest.test` | verified | **terminated** (Garden Villas TH-C) | GH₵0 | Former tenant: settled ledger, `contract_terminated` notification + audit, and a **review from a former tenant** |
| `tenant.expired@wyncrest.test` | verified | **expired** (Ridge Court 1B-08) | GH₵0 | Former tenant whose 12-month lease ran its full term; settled ledger |
| `tenant.unverified@wyncrest.test` | **unverified** | none | GH₵0 | **Application hard-gate:** cannot apply until verified; empty tenant dashboard (no lease, no applications) |

---

## Financial scenarios

Every balance is derivable directly from the immutable ledger
(`Σ obligations + Σ payments`, payments stored negative). Late fees are raised only
through the real service (which enforces "must be overdue" + "no duplicate").

| Scenario | Tenant | Expected balance | Ledger entries |
|---|---|---|---|
| Paid to zero | `tenant.good1`–`good4` | GH₵0 | N rent (PAID) + N payment (negative, PAID) |
| Owing one month | `tenant.owing` | GH₵2,500 | prior months PAID; latest month **OVERDUE**, unpaid |
| Owing + late fee | `tenant.latefee` | GH₵2,860 | prior months PAID; latest **OVERDUE** + a **LATE_FEE** (PENDING, linked) |
| Settled former lease | `tenant.former`, `tenant.expired` | GH₵0 | every month of the (now-ended) lease PAID |

Verify all of this at once: `php artisan wyncrest:seed:verify` (40 checks,
exits non-zero on any failure — also a CI smoke test).

## Contract lifecycle

| Status | Count | Where |
|---|---|---|
| `active` | 6 | good1–4, owing, latefee |
| `terminated` | 1 | `tenant.former` (terminated_by tenant, with reason) |
| `expired` | 1 | `tenant.expired` (ran its full 12-month term) |

## Listing / moderation states

`active`, `pending_review`, `draft`, `inactive` are all present (browse surface,
moderation queue, drafts, and off-market units on occupied leases). Re-listed units
from former leases add extra `active` listings.

## Messaging (3 threads)

Unread is **derived** by the app (`is_read = false AND sender != viewer`), so the
underlying rows are seeded truthfully — never a fabricated count.

| Thread | Read state | Tests |
|---|---|---|
| `tenant.good1` ↔ `landlord.1` | all read | Normal thread + fully-read inbox |
| `tenant.owing` ↔ `landlord.2` | landlord's reminder **unread by tenant** | Unread badge on the **tenant** side (tied to real overdue rent) |
| `tenant.good2` ↔ `landlord.1` | applicant's question **unread by landlord** | Unread badge on the **landlord** side (tied to a real application) |

`tenant.good3` and `tenant.good4` have **no conversations** → empty-inbox state.

## Verification states

Represented across catalog + queue accounts: `verified`, `pending`, `rejected`,
`needs_more_information`, `unverified` (never submitted), plus a rejected→resubmitted
history and a zero-document case. Every reviewed request carries a real attached
document (a valid placeholder PDF/PNG written to the local disk) so the document
viewer has something genuine to preview.

## Notifications, email, audit

- **Notifications** (10, mixed read/unread) — each backed by a real event:
  overdue rent, payment succeeded, **payment failed**, **late fee added**,
  **contract terminated**, application submitted, review submitted, **account
  suspended**.
- **Email logs** — a handful across types/statuses (`EmailLog` table).
- **Audit log** — real privileged activity only: identity verifications, listing
  publications, feature grants, contract acceptances, **contract termination**,
  **account suspension**, **payment failed**, late-fee application (self-audited by
  the service), and an admin login. No fabricated history.

---

## Unsupported scenarios

These were requested but are **not currently supported by the backend**, so they
were **deliberately not seeded** rather than faked. *Do not remove this section* —
it documents the honest boundary of the seed world.

- **Partial payments.** The ledger requires every payment to settle the **full**
  obligation amount. There is no `PARTIALLY_PAID` status and no columns tracking a
  partial amount (`LedgerComputationEngine` states this explicitly). Seeding a
  partial payment would violate the immutable-ledger contract. *To support it, the
  backend would need a partial-payment representation (remaining-balance tracking on
  the obligation or a `PARTIALLY_PAID` status) before the UI could show it truthfully.*
- **Failed-payment ledger rows.** A failed payment writes **no** ledger entry — the
  backend records only an audit entry + a `PAYMENT_FAILED` notification, leaving the
  obligation untouched. We seed that exact truthful trace (see `tenant.owing`), but
  **not** a fabricated "failed" ledger row. *No backend change is needed unless you
  want failed attempts to become first-class, queryable ledger history.*

### Partially represented (backend columns exist, not seeded as demo state)

- **SMS delivery states.** Notifications carry SMS delivery columns
  (`sms_status`, etc.) and there is an `SmsDeliveryService`, but there is no separate
  `sms_logs` table and no real Twilio call happens during seeding. Only **email**
  delivery logs are seeded; SMS delivery is exercised through the app/tests, not the
  seed graph.
