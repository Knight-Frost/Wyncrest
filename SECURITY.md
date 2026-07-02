# Security Policy

This document explains how Wyncrest protects accounts, data, and money, and how to report a security concern.

Who this is for: anyone evaluating Wyncrest's security posture, and anyone who finds a vulnerability and wants to report it responsibly.

## Overview

Wyncrest treats security as a backend responsibility, not a frontend one. Every protected action, whether it is viewing someone else's lease or approving a listing, is checked on the server. The interface can hide a button, but the button being hidden is never what stops the action. The server decides.

Three ideas run through the whole system:

- **Least privilege.** A new admin starts with no special access. A super admin has to explicitly grant each capability.
- **Tamper evidence.** Rent and payment history cannot be silently edited. Privileged actions are written to a permanent log.
- **Server-side enforcement.** Every rule that matters is checked again on the backend, even if the frontend already checked it.

## Supported versions

Wyncrest currently has one active line of development: the `main` branch. There are no older maintained versions.

## Reporting a security issue

If you find a security vulnerability, please do not open a public GitHub issue.

There is no dedicated security email published yet. Until one exists, report security issues privately to the repository owner through GitHub (for example, by contacting the maintainer directly or opening a private security advisory on the repository). Please include enough detail to reproduce the issue.

## Authentication

- Wyncrest uses token-based authentication (Laravel Sanctum). A user logs in once and receives a token, which is sent with every request afterward.
- Tenants and landlords share one account table; admins live in a completely separate table with its own login path. A tenant token can never be mistaken for an admin token.
- Passwords are hashed, never stored in plain text, and must meet a minimum strength (length, mixed case, numbers).
- Repeated failed logins are rate-limited per account and per network address, and lockouts are logged.

## Authorization

Wyncrest has four practical access levels:

| Role | Summary |
|---|---|
| Tenant | Manages their own applications, lease, payments, and messages |
| Landlord | Manages their own properties, listings, contracts, and tenants |
| Admin | Moderates the platform, but only in the areas they have been granted |
| Super admin | Has every capability by default, and controls what other admins can do |

Admin access is **granular**, not all-or-nothing. A super admin decides which specific capabilities each admin holds, such as reviewing verifications, moderating listings, or viewing the audit log. An admin with no granted capabilities can log in but cannot act on anything protected.

The feature that controls this, **Manage Users & Permissions**, is itself locked down: only super admins can open it by default. A super admin can choose to grant a specific admin access to that page as well, but it does not happen automatically.

Full detail, including a role-by-role capability table and real examples: [`docs/AUTHORIZATION.md`](docs/AUTHORIZATION.md).

## Backend enforcement

This is the most important rule in the whole security model: **hiding something in the interface is not the same as protecting it.**

Every sensitive action is checked at least twice on the server:

1. A route-level check confirms the caller is logged in and holds the right role.
2. A resource-level check confirms the caller owns, or is otherwise allowed to touch, the specific record involved.

If a check fails, the server refuses the request, regardless of what the frontend shows. This means a tenant cannot view another tenant's lease by guessing a URL, and a scoped admin cannot moderate listings just because they know the moderation page exists.

## Admin access controls

- **Super admins** have every capability by default and do not need anything granted to them.
- **Scoped admins** start with nothing. A super admin grants specific capabilities one at a time (for example: review verifications, moderate listings, view the audit log).
- A capability that has not been granted is enforced as denied on the backend, not just hidden in the admin's menu.
- There is always a safeguard against ending up with zero super admins: the last super admin cannot be demoted or deactivated.

## Audit logs

Every privileged action, such as suspending a user, approving a listing, or changing an admin's permissions, is written to a permanent audit log.

- Audit log entries cannot be updated or deleted through the application. They are append-only by design.
- Each entry is chained to the one before it using a hash, so if an entry were ever altered outside the application, the chain would visibly break. Admins with the right capability can verify the chain at any time.

## Ledger safety

Rent, payments, and balances live in an append-only ledger, described fully in [`docs/LEDGER.md`](docs/LEDGER.md). The short version:

- A ledger entry is never edited or deleted once created.
- A correction is made by adding a new entry, never by changing an old one.
- A tenant's balance is always calculated from the full history of entries, never stored as a separate number that could drift from reality.

## Environment safety

- Secrets (API keys, database credentials, application keys) live only in a local `.env` file, which is never committed to the repository.
- Local demo accounts and demo data only exist in development mode, and only on the reserved `wyncrest.test` email domain, which cannot receive real mail.
- Real payment and messaging credentials (Stripe, Twilio) are required for those features to work at all; without them, the features are safely disabled rather than faking success.

## Production seed safety

When Wyncrest is set up for production, the setup process is deliberately boring:

- No demo tenants, landlords, properties, contracts, or payments are ever created.
- The only thing production setup can add is a single optional administrator account, and only if it has been explicitly configured through environment variables.
- Production setup can be run more than once safely. It will not create duplicates.

Full detail: [`docs/SEEDING.md`](docs/SEEDING.md).

## Security checklist

| Check | Status |
|---|---|
| Every protected action enforced on the backend | Yes |
| Admin access is granular, not all-or-nothing | Yes |
| Manage Users & Permissions restricted to super admins by default | Yes |
| Audit log is append-only and hash-chained | Yes |
| Ledger entries are append-only | Yes |
| Passwords hashed, never stored in plain text | Yes |
| Login attempts rate-limited | Yes |
| Secrets kept out of version control | Yes |
| Production setup never creates demo data | Yes |
| Dedicated security contact email published | Not yet |
