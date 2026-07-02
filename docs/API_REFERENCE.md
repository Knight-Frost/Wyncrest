# API Reference

A plain-language map of what the Wyncrest API does, organized by who uses each part.

Who this is for: developers building against the API, and reviewers who want to understand its shape without reading every route.

This is not a raw route dump. For exact request and response shapes, read the relevant controller and FormRequest in `app/Http/Controllers` and `app/Http/Requests`. This document explains what each group of endpoints is for, who can use it, and what to expect back.

## How authentication works

Every request except registration, login, and public listing browsing requires a login token, sent as a bearer token in the request header. A token is issued when a user logs in and stays valid until it is revoked (by logging out, or by a password change, which revokes every other active session).

## Access levels used below

| Level | Meaning |
|---|---|
| Public | No login required |
| Any logged-in user | Any valid tenant, landlord, or admin token |
| Tenant | A logged-in tenant account |
| Landlord | A logged-in landlord account |
| Admin | A logged-in admin account with the listed capability, or a super admin |
| Super admin | Only an admin account with super admin status |

## Route groups

| Group | Who can use it | What it is for |
|---|---|---|
| Auth | Public, then any logged-in user | Registration, login, logout, password change, password reset, Google sign-in |
| Tenant | Tenant | Dashboard, profile, saved listings, applications, lease, ledger, payments, documents, maintenance, messaging, reviews |
| Landlord | Landlord | Dashboard, properties, units, listings, contracts, ledger, applications, maintenance, media, analytics |
| Admin | Admin, gated by capability | Dashboard, moderation, verification review, user management, ledger oversight, analytics |
| Super admin access controls | Super admin, or a granted admin | Manage Users & Permissions: inviting admins, granting capabilities, promoting or demoting super admins |
| Public listings | Public | Browsing and searching published listings |
| Applications | Tenant to apply, landlord to decide | A tenant's request to rent a listing, and the landlord's approval or rejection |
| Contracts | Landlord to send, tenant to accept, admin to oversee | The lease agreement itself, from draft to signed to terminated |
| Ledger and payments | Tenant to pay, landlord and admin to view | Rent charges, payments, late fees, and balances |
| Notifications | Any logged-in user | In-app alerts and notification preferences |
| Audit logs | Admin with the audit capability | The permanent record of privileged actions |
| Media | Landlord to upload, any logged-in user to view | Photos for properties, units, listings, and avatars |
| Reviews | Tenant to submit, landlord to respond, admin to moderate | A tenant's review of a property after their lease |
| Verification | Tenant and landlord to submit, admin to review | Identity document verification |
| Messaging | Tenant and landlord | Direct conversations between a tenant and their landlord |
| Weather | Any logged-in user | A small convenience widget on the tenant dashboard; gracefully reports unavailable if not configured |

## Auth

Registration creates a tenant or landlord account and immediately returns a login token. Login checks admin accounts first, then regular accounts, and enforces a rate limit (five attempts per minute per account and network address) to slow down guessing. Password changes require the current password, revoke every other active session, and are written to the audit log for both tenant and landlord accounts.

## Tenant

Covers everything a tenant needs to manage their own rental: a dashboard summarizing their lease and next payment, their profile and avatar, saved listings, their applications, their lease and ledger, initiating a rent payment, uploading documents, filing a maintenance request, messaging their landlord, and submitting a review after their lease. Every one of these is scoped to the logged-in tenant's own data; a tenant can never view another tenant's records through this API, even by guessing an identifier.

## Landlord

Covers property and unit management, drafting and publishing listings, reviewing applicants, sending and terminating contracts, viewing their own ledger, uploading media, and viewing their own analytics. Like the tenant group, everything here is scoped to the logged-in landlord's own properties and tenants.

## Admin

Every admin route requires the matching capability, except for a small set (like the dashboard) available to any logged-in admin. An admin with no granted capabilities can log in but cannot reach any of the protected admin routes. See [`docs/AUTHORIZATION.md`](AUTHORIZATION.md) for the full capability list.

## Super admin access controls

This is the "Manage Users & Permissions" area: inviting a new admin, resending or revoking an invite, granting or removing specific capabilities, and promoting or demoting super admin status. It is restricted to super admins by default, and can only be opened by a scoped admin if a super admin explicitly grants that access.

## Public listings

Anyone, logged in or not, can browse and search published listings. This is the only part of the API that requires no login at all.

## Applications

A tenant submits an application to a specific listing. The landlord who owns that listing can view it and approve or reject it. A tenant can withdraw their own application. Neither side can see or act on another tenant's application.

## Contracts

A landlord drafts a contract and sends it to a tenant. The tenant then accepts it, at which point it becomes active and starts generating ledger charges. Either the tenant or the landlord can terminate an active contract, and an admin can force-terminate any contract if needed. A contract always belongs to exactly one landlord and one tenant, and only those two, plus an authorized admin, can view it.

## Ledger and payments

The ledger for a specific lease is visible to the tenant, the landlord, and admins with the ledger capability, always scoped to that lease. A tenant initiates a payment for a specific rent charge; the payment is only marked successful after the payment processor confirms it actually happened, never based on what the browser reports. An admin with the ledger capability can apply a late fee to an overdue charge. See [`docs/LEDGER.md`](LEDGER.md) for how the underlying data model works.

## Notifications

Any logged-in user can view their own notifications, mark one or all as read, and set their delivery preferences (in-app, email, SMS) for each notification type. Admin accounts do not have an in-app notification channel, since they live in a separate account table; their equivalent record is the audit log.

## Audit logs

Only admins with the audit capability can read the audit log. Entries cannot be created, edited, or deleted through the API; they are written automatically as a side effect of privileged actions elsewhere in the system. A verification endpoint confirms the log's hash chain has not been tampered with.

## Media

Landlords upload photos for their properties, units, and listings, and any user can upload their own avatar. Any logged-in user can view media that they are allowed to see (for example, photos on a listing they can already view). Landlords can reorder or remove their own uploaded photos.

## Reviews

A tenant can review a property after their lease is active or completed, once per contract. A landlord can respond to a review of their property. Reviews only affect public averages once an admin with the review capability has approved them; a pending or rejected review never influences what the public sees.

## Verification

Tenants and landlords submit identity documents for review. An admin with the verification capability can approve, reject, or request more information. Verification is required before a landlord can publish a listing, and before a tenant can apply to one.

## Messaging

Tenants and landlords can message each other directly. A conversation is only visible to its two participants. Message attachments follow the same visibility rule as the conversation they belong to.

## Weather

A small convenience feature on the tenant dashboard. If no weather provider is configured, it reports itself as unavailable rather than failing the request.

## Domain values used across the API

These are the fixed sets of values (enums) used throughout the API. A field using one of these will only ever contain a value from its list.

| Field | Possible values |
|---|---|
| Account type | Tenant, landlord |
| Property type | Single family, multi-family, apartment, condo, townhouse, commercial, other |
| Unit availability | Available, occupied, pending, maintenance, unlisted |
| Listing status | Draft, pending review, active, inactive, rejected, archived |
| Contract status | Draft, pending tenant, active, terminated, expired |
| Ledger entry type | Rent, late fee, payment, refund |
| Ledger entry status | Pending, paid, overdue, waived |
| Verification status | Unverified, pending, under review, verified, rejected, needs more information |
| Account status | Active, suspended, blocked, archived |
| Terminated by | Landlord, tenant, admin |

### Notification types

| Category | Types |
|---|---|
| Payments and rent | Rent generated, rent due soon, rent overdue, payment succeeded, payment failed, late fee added |
| Lease | Contract signed, contract terminated |
| Listings | Listing approved, listing rejected |
| Applications | Application submitted, application approved, application rejected |
| Reviews | Review submitted, review approved, review response |
| Verification | Verification submitted, verification approved, verification rejected, verification needs more information |
| Account governance | Account suspended, account reactivated, account blocked, account archived |
| Security | Password changed |
