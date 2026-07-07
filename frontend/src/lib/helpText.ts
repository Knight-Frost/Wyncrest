/**
 * helpText — the single source of truth for contextual tooltip copy.
 *
 * Every string here is verified against real backend behaviour (services,
 * enums, controllers). Rules for editing:
 *   - State the actual product truth. Never explain a feature the backend
 *     doesn't have, and never contradict how the system really behaves.
 *   - Keep each entry to one or two plain sentences. No essays, no "click here".
 *   - If a figure is as-of-now (stock) vs period-scoped, say which.
 *
 * Centralising the copy keeps wording consistent across all four portals and
 * makes it reviewable in one place.
 */
export const help = {
  // ── Financial / ledger ────────────────────────────────────────────────
  // Verified: LedgerComputationEngine, LedgerStatus (only pending/overdue/
  // paid/waived exist), GenerateLateFeeRequest, BillingPeriodCalculator.
  outstandingBalance:
    'The current unpaid amount across rent and late fees. It is an as-of-now total — changing the date range does not make it disappear.',
  overdue:
    'A charge whose due date has passed while it is still unpaid.',
  collected:
    'Payments that were successfully recorded. Charges that are still unpaid are not counted here.',
  charged:
    'Total rent and late fees billed for this period.',
  waived:
    'A charge an admin cancelled. It is written off, so it no longer adds to the balance.',
  pending:
    'A charge that has been billed but is not due yet and has not been paid.',
  lateFee:
    'A one-off amount an admin adds to an overdue rent charge. It is a set amount, not a percentage, and can be added only once per charge.',
  billingPeriod:
    'One calendar month of rent, running from the start date to the day before the next month.',
  ledgerEntry:
    'A single financial record — a rent charge, a late fee, or a payment. Entries cannot be edited or deleted; corrections are added as new entries.',
  balance:
    'The running total of charges minus payments.',
  collectionRate:
    'For the selected period: rent billed minus what is still outstanding, divided by rent billed. It shows how much of that period’s rent has come in.',
  settled:
    'Fully resolved — the charge was either paid or waived.',
  exportChecksum:
    'A SHA-256 fingerprint of the exported file, so you can confirm it was not altered.',
  exportMatchesPage:
    'The export contains exactly the rows shown here, with the same filters applied.',

  // ── Analytics ─────────────────────────────────────────────────────────
  // Verified: SuperAdminAnalyticsService / LandlordAnalyticsService — period
  // figures use the date window; outstanding/overdue are always as-of-now.
  dateRange:
    'Sets the window for period figures like rent billed, collected, and fees. As-of-now figures such as outstanding and overdue always reflect today, whatever range you pick.',
  occupancy:
    'The share of your units that currently have an active lease.',
  vacantUnits:
    'Units with no active lease right now.',
  expectedRent:
    'The rent you would bill if every listed or occupied unit were paying — a target, not money received.',
  maintenanceResponseTime:
    'Average time from a request being submitted to its first response, over the selected period.',
  listingFunnel:
    'How many of your listings go on to become signed contracts.',
  verificationQueue:
    'Identity checks waiting for an admin decision right now.',
  collectionRateNet:
    'Rent billed in the selected window minus what is still outstanding from it, divided by rent billed.',
  // Verified: SuperAdminAnalyticsService::VERIFICATION_OVERDUE_HOURS = 72;
  // verifications.timing.average_review_time_hours (submission → decision).
  verifOverdue:
    'A verification request that has waited more than 72 hours for an admin decision.',
  verifReviewTime:
    'Average time from a verification request being submitted to an admin deciding it.',
  // Verified: SuperAdminAnalyticsService::ledgerIntegritySection() → reconciliation issue_count.
  ledgerIntegrity:
    'Ledger records that failed the reconciliation check. A non-zero count means figures do not balance and need investigating.',
  // Verified: ListingReview.tsx — "Ready to publish = pending minus anything flagged".
  listingReadyToPublish:
    'Submitted listings with no outstanding issues, ready for an admin to approve.',

  // ── Maintenance ───────────────────────────────────────────────────────
  // Verified: MaintenanceStatus, MaintenanceSafetyFlag, MaintenanceService,
  // MaintenanceOverviewService. NOTE: there is NO combined "triage score" —
  // these are independent signals, each explained on its own.
  maintenancePriority:
    'How urgent the request is, from low to urgent. It is set when the request is reported.',
  safetyFlag:
    'The tenant flagged a safety issue — such as a leak, no power, a security problem, or injury risk.',
  maintenanceOverdue:
    'Still open past the completion date the landlord set for it.',
  maintenanceAge:
    'How long ago the request was submitted.',
  escalated:
    'An admin has flagged this request for extra attention.',
  assigned:
    'Someone has been assigned to carry out the repair.',
  unassigned:
    'No one has been assigned to the repair yet.',
  acknowledged:
    'The landlord has seen the request but has not started work.',
  maintenanceResolved:
    'The work is marked done. It can still be reopened if the issue comes back.',
  reopened:
    'A resolved request that was opened again because the issue was not fixed.',
  overrideClose:
    'An admin closing a request directly, outside the landlord’s normal flow. Requires the manage-maintenance capability.',
  internalNote:
    'Visible to admins only. Tenants and landlords never see these notes.',
  tenantVisibleActivity:
    'This activity timeline is visible to the tenant.',
  maintenanceExport:
    'Exports the requests currently listed, with a SHA-256 checksum so you can confirm the file is intact.',
  repairCost:
    'Labour plus parts, as recorded by the landlord for this repair.',

  // ── Verification ──────────────────────────────────────────────────────
  // Verified: VerificationStatus, VerificationService (requestMoreInfo exists),
  // hard 403 gates in LandlordListingController / tenant ApplicationController.
  verifPending:
    'Submitted and waiting for an admin to review.',
  verifUnderReview:
    'An admin is reviewing the documents now.',
  verifApproved:
    'Identity confirmed. Features that need verification are unlocked.',
  verifRejected:
    'The submission was declined. Check the reason given and resubmit.',
  verifNeedsInfo:
    'An admin needs something more before they can decide.',
  verifWhyLandlord:
    'Verification is required before you can submit a listing for review.',
  verifWhyTenant:
    'Verification is required before you can start or submit an application.',
  documentUpload:
    'Upload a clear photo or scan. Admins review it privately — it is never shown publicly.',

  // ── Admin permissions / RBAC ──────────────────────────────────────────
  // Verified: AdminCapability enum, EnsureAdminCan middleware (super bypasses).
  superAdmin:
    'Has every capability and can manage other admins.',
  scopedAdmin:
    'Can use only the capabilities they have been granted. Other actions and pages stay hidden.',
  capability:
    'A specific permission, such as moderating listings or reviewing verifications. Super admins have all of them.',
  manageAccess:
    'Grant or remove admin capabilities. Requires the manage-access capability.',
  actionRequiresCapability:
    'Only available to admins with the required capability.',
  pageUnavailableScoped:
    'Hidden because your admin role does not include this capability.',
  restrictedAttempts:
    'Times a scoped admin tried an action their role does not allow. Each attempt is recorded in the audit log.',

  // ── Listings lifecycle ────────────────────────────────────────────────
  // Verified: ListingStatus enum (isPublic/isEditable/requiresReview).
  listingDraft:
    'Not submitted yet. Only you can see it.',
  listingPending:
    'Submitted and waiting for an admin to approve or reject it.',
  listingActive:
    'Approved and visible to the public.',
  listingRejected:
    'An admin declined it. Fix the issues and resubmit.',
  listingInactive:
    'Taken down from public view. You can reactivate it.',
  listingArchived:
    'Retired from your active lists. You can restore it.',

  // ── Applications lifecycle ────────────────────────────────────────────
  // Verified: ApplicationStatus enum.
  appDraft:
    'Not sent yet. Only you can see it.',
  appSubmitted:
    'Sent to the landlord and awaiting review.',
  appNeedsAction:
    'The landlord asked for more information before deciding.',
  appApproved:
    'The landlord approved this application.',
  appRejected:
    'The landlord declined this application.',
  appWithdrawn:
    'You withdrew this application.',

  // ── Contracts lifecycle ───────────────────────────────────────────────
  // Verified: ContractStatus enum (canBeAccepted/canBeTerminated).
  contractDraft:
    'Being prepared by the landlord; not sent yet.',
  contractPendingTenant:
    'Sent to the tenant, waiting for them to accept.',
  contractActive:
    'Accepted and in effect. Rent charges are generated from it.',
  contractTerminated:
    'Ended early by a tenant, landlord, or admin.',
  contractExpired:
    'Reached its end date.',

  // ── Tenant dashboard / payments ───────────────────────────────────────
  nextPayment:
    'Your next rent charge and the date it is due.',
  paymentStatusSummary:
    'Your current rent standing — whether you are paid up, due soon, or overdue.',
  paymentStanding:
    'Your record of paying on or before the due date.',
  lifetimePaid:
    'Everything you have successfully paid to date.',

  // ── Landlord dashboard ────────────────────────────────────────────────
  activeLeases:
    'Contracts that have been accepted and are currently in effect.',
  leasesExpiring:
    'Active leases that reach their end date soon.',
  needsAttention:
    'Properties or units with something to act on, such as a rejected listing or a unit with no listing.',
  onTimeRate:
    'Share of rent charges paid on or before their due date, across your tenants.',

  // ── Audit ─────────────────────────────────────────────────────────────
  // Verified: audit_logs append-only + SHA-256 hash chain (project memory).
  chainIntegrity:
    'Each audit event is cryptographically linked to the one before it, so any tampering with old records is detectable.',
  auditLog:
    'An append-only record of every privileged action. Entries can be added but never edited or removed.',

  // ── Public / listing detail ───────────────────────────────────────────
  verifiedRentals:
    'Listings from landlords whose identity has been confirmed by an admin.',
  securityDeposit:
    'A refundable amount held for the duration of the lease.',
} as const;

export type HelpKey = keyof typeof help;
