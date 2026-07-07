/**
 * Domain types — mirror the Laravel backend exactly.
 *
 * Money note (see docs/API_REFERENCE.md):
 *  - Contract.rent_amount and LedgerEntry.amount_cents are INTEGER CENTS.
 *  - Unit.rent_amount / Unit.security_deposit are DECIMAL DOLLAR STRINGS ("1500.00").
 * Use the helpers in lib/format.ts to render either correctly.
 */

/* ---- Enums (string unions matching backend Enum values) ------------------ */
export type UserType = 'tenant' | 'landlord';

export type PropertyType =
  | 'single_family'
  | 'multi_family'
  | 'apartment'
  | 'condo'
  | 'townhouse'
  | 'commercial'
  | 'duplex'
  | 'studio_block'
  | 'compound_house'
  | 'mixed_use'
  | 'other';

export type AddressVisibility = 'area_only' | 'full_after_approval' | 'public';

/** Fixed catalogue of building-level amenities (mirrors App\Enums\PropertyAmenity). */
export type PropertyAmenity =
  | 'gated'
  | 'security_guard'
  | 'cctv'
  | 'fire_extinguisher'
  | 'smoke_detector'
  | 'water'
  | 'electricity'
  | 'backup_generator'
  | 'internet_ready'
  | 'waste_collection'
  | 'air_conditioning'
  | 'furnished'
  | 'balcony'
  | 'laundry'
  | 'elevator'
  | 'street_parking'
  | 'private_parking'
  | 'covered_parking'
  | 'compound'
  | 'garden'
  | 'pool'
  | 'gym'
  | 'shared_courtyard';

export type ResponsibilityParty = 'landlord' | 'tenant' | 'shared';

export interface PropertyRules {
  pets_allowed?: boolean;
  smoking_allowed?: boolean;
  guests_allowed?: boolean;
  max_occupants?: number | null;
  min_lease_months?: number | null;
  quiet_hours?: string | null;
  utility_responsibility?: ResponsibilityParty | null;
  maintenance_responsibility?: ResponsibilityParty | null;
}

export type UnitAvailabilityStatus =
  | 'available'
  | 'occupied'
  | 'pending'
  | 'maintenance'
  | 'unlisted';

export type ListingStatus =
  | 'draft'
  | 'pending_review'
  | 'active'
  | 'inactive'
  | 'rejected'
  | 'archived';

export type ContractStatus =
  | 'draft'
  | 'pending_tenant'
  | 'active'
  | 'terminated'
  | 'expired';

export type BillingCycle = 'monthly';

export type LedgerType = 'rent' | 'late_fee' | 'payment' | 'refund';

export type LedgerStatus = 'pending' | 'paid' | 'overdue' | 'waived';

/** Offline/manual payment methods a landlord can record (never Stripe-originated). */
export type PaymentMethod = 'mobile_money_mtn' | 'mobile_money_vodafone' | 'bank_transfer' | 'cash';

export type NotificationType =
  // payments / rent
  | 'rent_generated'
  | 'rent_due_soon'
  | 'rent_overdue'
  | 'payment_succeeded'
  | 'payment_failed'
  | 'late_fee_added'
  // lease
  | 'contract_sent'
  | 'contract_signed'
  | 'contract_terminated'
  | 'contract_renewed'
  // messaging
  | 'message_received'
  // listings
  | 'listing_approved'
  | 'listing_rejected'
  | 'listing_changes_requested'
  // applications
  | 'application_submitted'
  | 'application_approved'
  | 'application_rejected'
  | 'application_needs_action'
  | 'application_updated'
  // maintenance
  | 'maintenance_request_submitted'
  | 'maintenance_logged_by_landlord'
  | 'maintenance_status_updated'
  // reviews
  | 'review_submitted'
  | 'review_approved'
  | 'review_response'
  // verification
  | 'verification_submitted'
  | 'verification_approved'
  | 'verification_rejected'
  | 'verification_needs_info'
  // account governance
  | 'account_suspended'
  | 'account_reactivated'
  | 'account_blocked'
  | 'account_archived'
  // security
  | 'password_changed';

export type TerminatedBy = 'landlord' | 'tenant' | 'admin';

export type Role = UserType | 'admin';

/* ---- Identity ------------------------------------------------------------ */
export interface User {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  full_name: string;
  phone: string | null;
  avatar_url?: string | null;
  user_type: UserType;
  is_active: boolean;
  /** ISO timestamp when the email address was verified, or null if unverified. */
  email_verified_at?: string | null;
  identity_verified: boolean;
  verification_status?: string;
  account_status?: string;
  created_at: string;
}

/** Granular admin capability keys (mirror of App\Enums\AdminCapability). */
export type AdminCapability =
  | 'manage_access'
  | 'manage_users'
  | 'review_verifications'
  | 'moderate_listings'
  | 'moderate_reviews'
  | 'manage_features'
  | 'view_audit'
  | 'manage_contracts'
  | 'manage_ledger'
  | 'view_analytics'
  | 'manage_settings'
  | 'manage_maintenance';

export interface Admin {
  id: number;
  email: string;
  name: string;
  is_super_admin: boolean;
  is_active: boolean;
  /** Effective capabilities; a super admin implicitly holds all of them. */
  capabilities?: AdminCapability[];
  avatar_url?: string | null;
  last_login_at: string | null;
}

/** Discriminated union of who is logged in. `role` is derived client-side. */
export type AuthUser =
  | (User & { role: UserType })
  | (Admin & { role: 'admin' });

export interface AuthResponse {
  user: User | Admin;
  token: string;
}

/* ---- Catalogue ----------------------------------------------------------- */
export interface Property {
  id: number;
  landlord_id: number;
  name: string;
  property_type: PropertyType;
  street_address: string;
  street_address_2: string | null;
  city: string;
  state: string;
  zip_code: string;
  country: string;
  year_built: number | null;
  lot_size: string | null;
  description: string | null;
  /** Free-text policy prose surfaced on the Property page Overview. */
  parking: string | null;
  pet_policy: string | null;
  smoking_policy: string | null;
  amenities: PropertyAmenity[] | null;
  rules: PropertyRules | null;
  address_visibility: AddressVisibility;
  is_active: boolean;
  units_count?: number;
  units?: Unit[];
  /** Real per-property aggregates (present on GET /landlord/properties). */
  occupied_units?: number;
  vacant_units?: number;
  listed_units?: number;
  pending_units?: number;
  rejected_units?: number;
  occupancy_rate?: number;
  collected_this_month_cents?: number;
  /** First property-gallery photo URL, or null when there's no cover yet. */
  cover_url?: string | null;
  /** Server-computed "needs attention" warnings (present on GET /landlord/properties). */
  attention?: PropertyAttention[];
  /** Gallery media (present on GET /landlord/properties/{id}), ordered by sort_order. */
  media_assets?: MediaAsset[];
  /** Real aggregate over approved reviews (present on GET /listings/{id}). Null/0 when there are none — never invent a rating. */
  average_rating?: number | null;
  review_count?: number;
  /** Up to 5 most recent approved reviews (present on GET /listings/{id}). Reviewer is column-limited server-side — no email/phone. */
  approved_reviews?: Array<Review & { reviewer?: Pick<User, 'id' | 'first_name' | 'last_name' | 'avatar_url'> }>;
  created_at: string;
  updated_at: string;
}

/* ---- Property detail page (GET /landlord/properties/{id}/detail) --------- */

/** One server-computed "needs attention" warning. */
export interface PropertyAttention {
  level: 'red' | 'warn';
  message: string;
}

export interface PropertyDetailSummary {
  units_total: number;
  occupied: number;
  vacant: number;
  listed: number;
  pending_review: number;
  expected_rent_cents: number;
  collected_cents: number;
  outstanding_cents: number;
  overdue_cents: number;
}

export interface PropertyDetailUnit {
  id: number;
  unit_number: string;
  internal_name: string | null;
  bedrooms: string;
  bathrooms: string;
  square_feet: number | null;
  rent_amount: string; // dollars/cedis, e.g. "2800.00"
  availability_status: UnitAvailabilityStatus;
  /** Derived single status for the unit's listing: active|pending_review|rejected|draft|none. */
  listing_status: string;
  tenant_name: string | null;
  has_blocking_listing: boolean;
}

export interface PropertyDetailListing {
  id: number;
  title: string;
  unit_id: number;
  unit_number: string;
  rent_amount: string | null;
  status: ListingStatus;
  rejection_reason: string | null;
  changes_requested_reason: string | null;
  published_at: string | null;
  applications_count: number;
  view_count: number;
}

export interface PropertyDetailContract {
  id: string;
  tenant_name: string | null;
  unit_number: string;
  status: ContractStatus;
  start_date: string | null;
  end_date: string | null;
  rent_amount_cents: number;
  balance_cents: number;
  outstanding_cents: number;
  payment_status: string;
}

export interface PropertyDetailLedgerEntry {
  id: string;
  display_label: string;
  display_amount_cents: number;
  direction: LedgerDirection;
  financial_category: LedgerFinancialCategory;
  status: string;
  reference: string;
  occurred_at: string | null;
  due_date: string | null;
  running_balance_cents: number | null;
  unit_number: string;
  tenant_name: string | null;
}

export interface PropertyDetailMaintenance {
  id: number;
  title: string;
  unit_number: string;
  tenant_name: string | null;
  category: string | null;
  priority: string;
  status: string;
  submitted_at: string | null;
}

export interface PropertyDetailDocument {
  id: number;
  original_filename: string;
  document_type: string | null;
  uploader_name: string | null;
  is_verified: boolean;
  created_at: string | null;
}

export interface PropertyDetailPhoto {
  id: string;
  url: string | null;
  scope: string;
  alt_text: string | null;
  caption: string | null;
  is_cover: boolean;
}

export interface PropertyDetailActivity {
  id: number;
  action: string;
  description: string | null;
  actor_name: string | null;
  actor_role: string;
  created_at: string | null;
}

export interface PropertyDetailPayload {
  property: Property;
  summary: PropertyDetailSummary;
  attention: PropertyAttention[];
  units: PropertyDetailUnit[];
  listings: PropertyDetailListing[];
  contracts: PropertyDetailContract[];
  ledger: PropertyDetailLedgerEntry[];
  maintenance: PropertyDetailMaintenance[];
  documents: PropertyDetailDocument[];
  photos: PropertyDetailPhoto[];
  activity: PropertyDetailActivity[];
}

export interface Unit {
  id: number;
  property_id: number;
  unit_number: string;
  internal_name: string | null;
  bedrooms: string;
  bathrooms: string;
  square_feet: number | null;
  rent_amount: string; // dollars, e.g. "1500.00"
  security_deposit: string | null; // dollars
  availability_status: UnitAvailabilityStatus;
  available_from: string | null;
  amenities: string[] | null;
  is_active: boolean;
  property?: Property;
  /** Gallery media (present on GET /landlord/units/{id}), ordered by sort_order. */
  media_assets?: MediaAsset[];
  created_at: string;
  updated_at: string;
}

export interface ListingPhoto {
  id: number;
  listing_id: number;
  path: string;
  is_primary: boolean;
  sort_order: number;
  alt_text: string | null;
}

export interface Listing {
  id: number;
  unit_id: number;
  landlord_id: number;
  title: string;
  description: string;
  status: ListingStatus;
  rejection_reason: string | null;
  changes_requested_reason: string | null;
  changes_requested_at: string | null;
  reviewed_by: number | null;
  reviewed_at: string | null;
  published_at: string | null;
  expires_at: string | null;
  featured: boolean;
  view_count: number;
  pets_allowed: boolean;
  pet_policy: string | null;
  lease_duration_months: number | null;
  move_in_date: string | null;
  unit?: Unit;
  /** Full User shape on landlord-scoped endpoints; on GET /listings/{id} (public) this is column-limited to id/first_name/last_name/avatar_url/identity_verified — no email/phone. */
  landlord?: User;
  /** Admin who reviewed this listing (present on GET /landlord/listings/{id}). */
  reviewer?: { id: number; name: string; email?: string } | null;
  photos?: ListingPhoto[];
  primary_photo?: ListingPhoto | null;
  /** Gallery media — present on GET /landlord/listings/{id} AND on the public GET /listings/{id}, ordered by sort_order. */
  media_assets?: MediaAsset[];
  /** Real applications received against this listing (present on landlord index/show). */
  applications_count?: number;
  /** Applications not yet opened by the landlord (status=submitted). */
  new_applications_count?: number;
  /** What still blocks submission — empty once a draft/rejected listing is ready. Empty for all other statuses. */
  missing_requirements?: string[];
  created_at: string;
  updated_at: string;
}

export interface ListingHistoryEntry {
  id: number;
  created_at: string;
  area: string;
  action: string;
  action_label: string;
  severity: string;
  status: string;
  actor: { id: number | null; role: string; name: string; email: string | null };
  summary: string;
  subject_label: string | null;
  ip_address: string | null;
  hash: string;
}

/* ---- Agreements & money -------------------------------------------------- */
export interface Contract {
  id: string; // UUID
  listing_id: number;
  landlord_id: number;
  tenant_id: number;
  rent_amount: number; // cents
  currency: string;
  billing_cycle: BillingCycle;
  payment_day: number;
  start_date: string;
  /** Null = open-ended (no fixed end date). */
  end_date: string | null;
  status: ContractStatus;
  terminated_by: TerminatedBy | null;
  termination_reason: string | null;
  landlord?: User;
  tenant?: User;
  listing?: Listing;
  /** Renewal history rows (present on landlord GET /landlord/contracts/{id}). */
  renewals?: ContractRenewalRecord[];
  created_at: string;
}

/**
 * A single lease-renewal history row (`contract_renewals` table). Written by
 * `LandlordContractController::renew()` every time a landlord renews an
 * ACTIVE contract in-place — the real before/after, never re-derived.
 */
export interface ContractRenewalRecord {
  id: number;
  contract_id: string;
  landlord_id: number;
  previous_end_date: string | null;
  previous_rent_amount: number; // cents
  new_end_date: string;
  new_rent_amount: number; // cents
  note: string | null;
  created_at: string;
}

/**
 * A landlord-authored, landlord-visible note on a tenancy (`contract_landlord_notes`
 * table). Distinct from the admin-only `ContractNote`.
 */
export interface ContractLandlordNote {
  id: number;
  contract_id: string;
  landlord_id: number;
  body: string;
  created_at: string;
  landlord?: Pick<User, 'id' | 'full_name'>;
}

/** Direction of money movement for a ledger entry — safe to render directly. */
export type LedgerDirection = 'charge' | 'payment' | 'refund';

/** Financial grouping category, computed by the backend (never inferred from a sign). */
export type LedgerFinancialCategory = 'rent' | 'late_fee' | 'payment' | 'refund';

/**
 * Every financial number here is computed server-side by
 * LedgerComputationEngine — the frontend must never re-derive these from
 * raw ledger entries. `collected_cents` in particular can never be
 * negative; it is the sum of PAYMENT entries' absolute value.
 */
export interface LedgerFinancialSummary {
  rent_charged_cents: number;
  fees_charged_cents: number;
  collected_cents: number;
  outstanding_cents: number;
  overdue_cents: number;
  due_soon_cents: number;
  entry_count: number;
}

export interface LedgerEntry {
  id: string; // UUID
  contract_id: string;
  tenant_id: number;
  landlord_id: number;
  type: LedgerType;
  /**
   * Raw signed value as stored (rent/late_fee/refund positive, payment
   * negative). Do NOT render this directly — use display_amount_cents for
   * UI amounts and balance_impact_cents when explicitly showing signed
   * balance impact.
   */
  amount_cents: number;
  currency: string;
  billing_period_start: string | null;
  billing_period_end: string | null;
  due_date: string | null;
  status: LedgerStatus;
  related_rent_entry_id: string | null;
  stripe_payment_intent_id: string | null;
  /** Set only by a landlord's manual/offline payment recording; null for Stripe-originated rows. */
  payment_method?: PaymentMethod | null;
  payment_reference?: string | null;
  contract?: Contract;
  /** Present on landlord/tenant-scoped responses (eager-loaded). */
  tenant?: User;
  /** Present on admin-scoped responses (eager-loaded). */
  landlord?: User;
  /** Derived display reference (e.g. INV-20250528-A1B2C3); not stored. */
  reference?: string;

  // ── Display-safe fields computed by LedgerComputationEngine ──────────
  /** Same as amount_cents — the canonical signed accounting value. */
  signed_amount_cents: number;
  /** Always positive. Render this in the primary "Amount" column. */
  display_amount_cents: number;
  /** Signed effect on balance. Only show when explicitly labelled "Balance impact". */
  balance_impact_cents: number;
  direction: LedgerDirection;
  financial_category: LedgerFinancialCategory;
  /** Human-readable label, e.g. "Payment received" — safe to render as-is. */
  display_label: string;
  /** When this ledger event was recorded (created_at, ISO 8601). */
  occurred_at: string;
  /** Running balance for the contract after this entry, chronologically. Null if not computed for this response. */
  running_balance_cents: number | null;

  created_at: string;
}

/* ---- Engagement ---------------------------------------------------------- */
export interface AppNotification {
  id: string; // UUID
  user_id: number;
  type: NotificationType;
  title: string;
  message: string;
  data: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
}

/* ---- Audit logs ----------------------------------------------------------- */
export interface AuditActor {
  id: number | null;
  role: 'admin' | 'landlord' | 'tenant' | 'user' | 'system';
  name: string;
  email: string | null;
}

export interface AuditStatus {
  key: string;
  label: string;
}

export interface AuditTrend {
  direction: 'up' | 'down' | 'flat';
  pct: number | null;
  label: string;
}

/** Shape returned by GET /admin/audit-logs (list row). */
export interface AuditLog {
  id: number;
  created_at: string;
  area: string;
  action: string;
  action_label: string;
  severity: 'info' | 'warning' | 'critical';
  status: AuditStatus;
  actor: AuditActor;
  summary: string;
  subject_label: string | null;
  ip_address: string | null;
  /** This row's SHA-256 chain hash (64 hex chars). */
  hash: string;
}

/** A single label/value fact in the case-file summary. Money facts carry `value_cents`; everything else carries `value`. */
export interface AuditKeyFact {
  label: string;
  kind: 'text' | 'money';
  value?: string | null;
  value_cents?: number;
}

/** A resolved related record card (tenant, contract, ledger entry, etc.) — never a bare UUID. */
export interface AuditRelatedRecord {
  type: string;
  label: string;
  sublabel: string | null;
  href: string | null;
}

/** Created-record summary shown for create-type events instead of an empty field-changes card. */
export interface AuditCreatedRecordSummary {
  type: string;
  fields: AuditKeyFact[];
}

/** Financial context — only present for ledger/payment events. Amounts are always cents; format client-side. */
export interface AuditFinancialContext {
  display_amount_cents: number;
  balance_impact_cents: number;
  direction: 'charge' | 'payment' | 'refund';
  display_label: string;
  reference: string;
  running_balance_cents: number | null;
}

export interface AuditClassification {
  category: string;
  severity: 'info' | 'warning' | 'critical';
  label: string;
  sensitivity: string | null;
}

export interface AuditSource {
  label: string;
  description: string;
}

/** Additional fields returned by GET /admin/audit-logs/{id}. */
export interface AuditLogDetail extends AuditLog {
  user_agent: string | null;
  device: string | null;
  actor_type: string | null;
  subject: { type: string; id: number | string; label: string } | null;
  metadata: Record<string, unknown> | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  /** The prior row's hash this entry commits to (GENESIS for the first row). */
  previous_hash: string;
  why_it_matters: string;
  recommended_steps: { label: string; to: string | null }[];

  // Case-file presentation fields (AuditEventPresenter) — all additive, all
  // truthfully derived server-side. Never re-interpret action/subject on the
  // frontend; render what these fields say.
  event_title: string;
  classification: AuditClassification;
  source: AuditSource;
  integrity_statement: string;
  plain_summary: string;
  key_facts: AuditKeyFact[];
  related_records: AuditRelatedRecord[];
  created_record_summary: AuditCreatedRecordSummary | null;
  financial_context: AuditFinancialContext | null;
  /** Honest note when this event type isn't deeply modeled, or a specific gap (e.g. reviewing admin not captured). Null when there's nothing to flag. */
  data_gap_note: string | null;
}

/** Real hash-chain recomputation status, one of these four truths. */
export type AuditChainStatus = 'healthy' | 'warning' | 'broken' | 'empty';

/** Result of GET /admin/audit-logs/verify — real SHA-256 chain recomputation. */
export interface AuditVerify {
  status: AuditChainStatus;
  is_valid: boolean;
  message: string;
  checked_count: number;
  total_count: number;
  failed_count: number;
  broken_at: number | null;
  latest_event_id: number | null;
  latest_hash_prefix: string | null;
  verified_at: string;
  algorithm: string;
}

export interface AuditInsight {
  tone: 'danger' | 'warning' | 'success' | 'info';
  title: string;
  detail: string;
  action: { label: string; to: string | null } | null;
}

export interface AuditSummaryMetric {
  value: number;
  label: string;
  trend?: AuditTrend;
}

/** A headline stat-strip figure (value + short descriptor). */
export interface AuditStat {
  value: number;
  sub: string;
}

export interface AuditSummary {
  metrics: {
    critical_today: AuditSummaryMetric;
    failed_signins: AuditSummaryMetric;
    policy_changes: AuditSummaryMetric;
    user_activity: AuditSummaryMetric;
    needs_review: Omit<AuditSummaryMetric, 'trend'>;
  };
  insights: AuditInsight[];
  stats: {
    events_today: AuditStat;
    total_on_record: AuditStat;
    actors_active_24h: AuditStat;
  };
}

/* ---- Weather (tenant dashboard chip) ------------------------------------- */
export type WeatherData =
  | { available: false; message: string }
  | {
      available: true;
      location: string;
      country: string;
      temperature: number;
      feels_like?: number;
      unit: 'C';
      condition: string;
      humidity?: number;
      updated_at: string;
    };

/* ---- Pagination (Laravel paginator) -------------------------------------- */
export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

/** Landlord/tenant ledger responses: unpaginated entries + a server-computed summary. */
export interface LedgerListResponse {
  entries: LedgerEntry[];
  summary: LedgerFinancialSummary;
}

/* ---- Landlord Rent Ledger console --------------------------------------- */

/** Per-contract payment status derived by LedgerComputationEngine::deriveContractPaymentStatus. */
export type ContractPaymentStatus = 'paid' | 'overdue' | 'open' | 'no_history';

/** KPI cards for the landlord ledger header (all money server-computed). */
export interface LandlordLedgerSummary {
  outstanding_cents: number;
  overdue_cents: number;
  collected_month_cents: number;
  charged_month_cents: number;
  tenants_overdue: number;
  month_label: string;
}

/** One row of the landlord "Balances" tab — a contract's money position. */
export interface LedgerBalanceRow {
  contract_id: string;
  contract_status: string;
  tenant: { id: number; full_name: string; email: string } | null;
  property: { id: number; name: string; city: string | null; state: string | null } | null;
  unit_number: string | null;
  rent_cents: number;
  payment_day: number;
  start_date: string | null;
  end_date: string | null;
  balance_cents: number;
  outstanding_cents: number;
  overdue_cents: number;
  status: ContractPaymentStatus;
  next_due: string | null;
  last_payment_at: string | null;
}

/** GET /landlord/ledger — entries (Transactions) + per-contract balances + KPI summary. */
export interface LandlordLedgerResponse {
  entries: LedgerEntry[];
  balances: LedgerBalanceRow[];
  summary: LandlordLedgerSummary;
}

/** Landlord single-entry case file (audit trail + linked entries; no tenant notifications). */
export interface LandlordLedgerCaseFile extends LedgerEntry {
  audit_trail: LedgerAuditEvent[];
  linked_entries: LedgerEntry[];
}

/** GET /landlord/ledger/statement/contract/{contract} — one contract's monthly movement. */
export interface LedgerContractStatement {
  contract: {
    id: string;
    status: string;
    rent_cents: number;
    payment_day: number;
    start_date: string | null;
    end_date: string | null;
    currency: string;
  };
  tenant: { id: number; full_name: string; email: string } | null;
  property: { id: number; name: string; city: string | null } | null;
  unit_number: string | null;
  period: { year: number; month: number; label: string; start: string; end: string };
  opening_cents: number;
  charges_cents: number;
  fees_cents: number;
  payments_cents: number;
  adjustments_cents: number;
  ending_cents: number;
  entries: LedgerEntry[];
}

export interface LedgerPropertyStatementUnit {
  contract_id: string;
  unit_number: string | null;
  tenant: { id: number; full_name: string } | null;
  rent_cents: number;
  paid_month_cents: number;
  balance_cents: number;
  status: ContractPaymentStatus;
}

/** GET /landlord/ledger/statement/property/{property} — money by property, per unit. */
export interface LedgerPropertyStatement {
  property: { id: number; name: string; city: string | null; state: string | null };
  period: { year: number; month: number; label: string };
  unit_count: number;
  charged_month_cents: number;
  collected_month_cents: number;
  outstanding_cents: number;
  overdue_cents: number;
  units: LedgerPropertyStatementUnit[];
}

/** GET /tenant/payments/balance — server-computed outstanding balance + gateway availability. */
export interface TenantBalance {
  balance_cents: number;
  balance_dollars: number;
  owes_money: boolean;
  /** True only when a Stripe gateway is configured on the API. */
  online_payments_enabled: boolean;
}

/** POST /tenant/payments/initiate/{entry} — Stripe PaymentIntent handles for in-app confirmation. */
export interface InitiatePaymentResponse {
  message: string;
  client_secret: string;
  payment_intent_id: string;
}

/** Admin ledger response: a paginated entry set + a summary over the FULL filtered set (not just the page). */
export type PaginatedLedger = Paginated<LedgerEntry> & { summary: LedgerFinancialSummary };

/** Query params accepted by GET /admin/ledger and /admin/ledger/export — kept
 * in one place so the list request and the export request can never drift. */
export interface LedgerQueryParams {
  page?: number;
  type?: LedgerType;
  status?: LedgerStatus;
  search?: string;
  date_from?: string;
  date_to?: string;
  overdue_only?: boolean;
  charges_only?: boolean;
}

/* ---- Ledger reconciliation (admin integrity check) ------------------------ */
export interface LedgerReconciliationIssue {
  severity: 'fail' | 'warning';
  code: string;
  message: string;
  entry_ids: string[];
  contract_ids: string[];
  expected: string | null;
  actual: string | null;
  metadata: Record<string, unknown>;
}

export interface LedgerReconciliationReport {
  status: 'pass' | 'warning' | 'fail';
  issues: LedgerReconciliationIssue[];
  summary: LedgerFinancialSummary;
}

/* ---- Ledger case file (admin single-entry detail) -------------------------
   Everything here is real: this entry's own audit-log history, ledger
   entries actually linked to it via related_rent_entry_id, and notifications
   actually sent about it. There is no dispute/payout/processor section —
   Wyncrest's ledger schema does not model those concepts. */
export interface LedgerAuditEvent {
  id: number;
  action: string;
  description: string | null;
  severity: 'info' | 'warning' | 'critical';
  actor: string;
  created_at: string | null;
}

export interface LedgerNotificationRow {
  id: string;
  type: NotificationType;
  title: string;
  message: string;
  delivered_at: string | null;
  sms_delivered_at: string | null;
  created_at: string | null;
}

export interface LedgerEntryCaseFile extends LedgerEntry {
  audit_trail: LedgerAuditEvent[];
  linked_entries: LedgerEntry[];
  notifications: LedgerNotificationRow[];
}

/* ---- Applications -------------------------------------------------------- */
export type ApplicationStatus =
  | 'draft'
  | 'submitted'
  | 'in_review'
  | 'landlord_review'
  | 'needs_action'
  | 'approved'
  | 'rejected'
  | 'withdrawn';

/** Structured multi-step application form (a draft's saved snapshot). */
export interface ApplicationFormData {
  personal?: {
    first?: string;
    last?: string;
    preferred?: string;
    email?: string;
    phone?: string;
    dob?: string;
  };
  contact?: { pref?: string; mailing?: string };
  employment?: {
    status?: string;
    employer?: string;
    title?: string;
    income?: string;
    start?: string;
    other?: string;
  };
  rental?: {
    curType?: string;
    curLandlord?: string;
    curContact?: string;
    curRent?: string;
    moveIn?: string;
    reason?: string;
  };
  household?: {
    adults?: string;
    children?: string;
    pets?: string;
    petDetail?: string;
    vehicles?: string;
  };
}

/** A landlord/admin request for more info or a document replacement. */
export interface ApplicationRequestItem {
  id: number;
  requester_role: 'landlord' | 'admin' | 'platform';
  type: 'document_replacement' | 'more_info' | 'general';
  document_type: DocumentType | null;
  message: string;
  reason: string | null;
  due_at: string | null;
  resolved_at: string | null;
  is_resolved: boolean;
  created_at: string;
}

/** An append-only, tenant-visible timeline event. */
export interface ApplicationEventItem {
  id: number;
  event: string;
  description: string;
  meta: Record<string, unknown> | null;
  created_at: string;
}

export interface Application {
  id: number;
  tenant_id: number;
  listing_id: number;
  landlord_id: number;
  status: ApplicationStatus;
  cover_note: string | null;
  form_data: ApplicationFormData | null;
  /** Visible only on the landlord-scoped endpoints. */
  landlord_notes?: string | null;
  decision_reason: string | null;
  submitted_at: string | null;
  reviewed_at: string | null;
  decided_at: string | null;
  withdrawn_at: string | null;
  /** Landlord-only organisational flag — independent of lifecycle status. */
  shortlisted_at?: string | null;
  is_shortlisted?: boolean;
  listing?: Listing;
  /** Documents attached to THIS application (on detail responses). */
  documents?: TenantDocument[];
  /** Landlord/admin requests for more info (on detail responses). */
  requests?: ApplicationRequestItem[];
  /** Append-only timeline (on detail responses). */
  events?: ApplicationEventItem[];
  /** Most recent event (on the list response). */
  latest_event?: ApplicationEventItem | null;
  /** Counts on the list response. */
  open_requests_count?: number;
  documents_count?: number;
  /** Present on landlord/admin-scoped responses. */
  tenant?: User;
  /** Real readiness (computed from tenant profile/docs) on landlord endpoints. */
  readiness?: Readiness;
  created_at: string;
}

/* ---- Maintenance --------------------------------------------------------- */
export type MaintenanceStatus =
  | 'open'
  | 'acknowledged'
  | 'assigned'
  | 'in_progress'
  | 'waiting'
  | 'resolved'
  | 'closed'
  | 'cancelled';
export type MaintenancePriority = 'low' | 'medium' | 'high' | 'urgent';
export type MaintenanceCategory =
  | 'plumbing'
  | 'electrical'
  | 'appliance'
  | 'hvac'
  | 'structural'
  | 'pest'
  | 'security'
  | 'locks'
  | 'windows'
  | 'flooring'
  | 'water_damage'
  | 'shared_area'
  | 'general';
export type MaintenanceReporter = 'tenant' | 'landlord';
export type MaintenanceAssigneeType = 'vendor' | 'staff';

/* Intake ("repair report") value sets — mirror App\Enums\Maintenance* exactly. */
export type MaintenanceArea =
  | 'kitchen'
  | 'bathroom'
  | 'bedroom'
  | 'living_room'
  | 'balcony'
  | 'exterior'
  | 'shared_area'
  | 'garage'
  | 'hallway'
  | 'other';
export type MaintenanceOnset =
  | 'today'
  | 'yesterday'
  | 'this_week'
  | 'over_a_week'
  | 'not_sure';
export type MaintenanceAccess = 'yes' | 'no' | 'contact_first';
export type MaintenanceVisitWindow =
  | 'morning'
  | 'afternoon'
  | 'evening'
  | 'weekend'
  | 'any';
export type MaintenanceContactMethod = 'message' | 'phone' | 'email';
export type MaintenanceSafetyFlag =
  | 'water_leak'
  | 'no_power'
  | 'security'
  | 'mold'
  | 'pest'
  | 'injury_risk'
  | 'property_damage';

export interface MaintenanceEvent {
  id: number;
  maintenance_request_id: number;
  actor_type: string | null;
  actor_id: number | null;
  actor?: Pick<User, 'id' | 'full_name'> | null;
  event: string;
  description: string;
  meta: Record<string, unknown> | null;
  created_at: string;
}

export interface MaintenanceRequest {
  id: number;
  tenant_id: number;
  contract_id: string;
  property_id: number;
  unit_id: number;
  landlord_id: number;
  reported_by: MaintenanceReporter;
  title: string;
  description: string;
  category: MaintenanceCategory;
  priority: MaintenancePriority;
  status: MaintenanceStatus;
  // Intake ("repair report") fields. Nullable: landlord-logged/legacy rows omit them.
  area: MaintenanceArea | null;
  specific_location: string | null;
  onset: MaintenanceOnset | null;
  safety_flags: MaintenanceSafetyFlag[] | null;
  access_permission: MaintenanceAccess | null;
  preferred_visit_window: MaintenanceVisitWindow | null;
  preferred_contact_method: MaintenanceContactMethod | null;
  access_instructions: string | null;
  has_severe_safety_flag: boolean;
  resolution_notes: string | null;
  assignee_name: string | null;
  assignee_phone: string | null;
  assignee_type: MaintenanceAssigneeType | null;
  waiting_reason: string | null;
  labor_cost_cents: number | null;
  parts_cost_cents: number | null;
  total_cost_cents: number | null;
  invoice_reference: string | null;
  cost_notes: string | null;
  cost_paid: boolean;
  submitted_at: string | null;
  acknowledged_at: string | null;
  assigned_at: string | null;
  appointment_at: string | null;
  expected_completion_date: string | null;
  resolved_at: string | null;
  closed_at: string | null;
  tenant?: Pick<User, 'id' | 'full_name' | 'email' | 'phone'>;
  landlord?: Pick<User, 'id' | 'full_name'>;
  property?: Property;
  unit?: Unit;
  contract?: Contract;
  events?: MaintenanceEvent[];
  media?: MediaAsset[];
  created_at: string;
}

export interface MaintenanceMessage {
  id: number;
  body: string;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
  sender: { id: number; name: string | null; is_me: boolean };
}

export interface MaintenanceMessageThread {
  conversation_id: number | null;
  messages: MaintenanceMessage[];
}

/** Payload the tenant intake form posts to POST /tenant/maintenance. */
export interface CreateMaintenancePayload {
  contract_id: string;
  title: string;
  description: string;
  category: MaintenanceCategory;
  priority: MaintenancePriority;
  area: MaintenanceArea;
  specific_location?: string | null;
  onset: MaintenanceOnset;
  safety_flags?: MaintenanceSafetyFlag[];
  access_permission: MaintenanceAccess;
  preferred_visit_window?: MaintenanceVisitWindow | null;
  preferred_contact_method?: MaintenanceContactMethod | null;
  access_instructions?: string | null;
}

/* ---- Documents ----------------------------------------------------------- */
export type DocumentType =
  | 'identity_document'
  | 'proof_of_address'
  | 'proof_of_income'
  | 'lease_document'
  | 'application_attachment'
  | 'maintenance_attachment'
  | 'other';

export interface TenantDocument {
  id: number;
  owner_user_id: number;
  uploaded_by_id: number;
  document_type: DocumentType;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  is_verified: boolean;
  verified_at: string | null;
  created_at: string;
}

/* ---- Messaging ----------------------------------------------------------- */
export interface MessageableRecipient {
  listing_id: number;
  listing_title: string;
  landlord: { id: number; name: string; avatar_url?: string | null };
  location: string;
  thumbnail_url: string | null;
  existing_conversation_id: number | null;
}

export interface ConversationSummary {
  id: number;
  title: string | null;
  status: string;
  last_message_at: string | null;
  unread_count: number;
  other_participant: { id: number; name: string; initials?: string; role?: string | null; avatar_url?: string | null } | null;
  /** index endpoint key */
  last_message_preview?: string | null;
  /** dashboard endpoint key */
  preview?: string | null;
  thumbnail_url?: string | null;
}

export interface MessageAttachment {
  id: number;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  attachment_type: 'image' | 'file';
}

export interface ConversationMessage {
  id: number;
  body: string;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
  has_attachments?: boolean;
  attachments?: MessageAttachment[];
  sender: { id: number; name: string | null; avatar_url?: string | null; is_me: boolean };
}

/** GET/POST /landlord/applications/{id}/messages */
export interface ApplicationMessageThread {
  conversation_id: number | null;
  messages: ConversationMessage[];
}

/** A contract-scoped message — same shape as ConversationMessage (reuses the
 * same Conversation/Message models, keyed on Contract instead of Listing). */
export type ContractMessage = ConversationMessage;

/** GET/POST /landlord/contracts/{id}/messages */
export interface ContractMessageThread {
  conversation_id: number | null;
  messages: ContractMessage[];
}

export interface ConversationDetail {
  conversation: {
    id: number;
    title: string | null;
    status: string;
    last_message_at: string | null;
    thumbnail_url?: string | null;
    other_participant: { id: number; name: string; role?: string | null; avatar_url?: string | null } | null;
  };
  messages: ConversationMessage[];
}

/* ---- Tenant profile + readiness ------------------------------------------ */
export interface ReadinessItem {
  key: string;
  label: string;
  complete: boolean;
}
export interface Readiness {
  percentage: number;
  completed: number;
  total: number;
  items: ReadinessItem[];
}
export interface TenantProfile {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  initials: string;
  email: string;
  phone: string | null;
  city: string | null;
  date_of_birth: string | null;
  next_of_kin_name: string | null;
  next_of_kin_phone: string | null;
  next_of_kin_relationship: string | null;
  user_type: UserType;
  identity_verified: boolean;
  avatar_url: string | null;
  created_at: string | null;
}
export interface TenantProfileResponse {
  user: TenantProfile;
  readiness: Readiness;
}

export interface LandlordProfile {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  initials: string;
  email: string;
  phone: string | null;
  user_type: UserType;
  identity_verified: boolean;
  avatar_url: string | null;
  created_at: string | null;
}
export interface LandlordProfileResponse {
  user: LandlordProfile;
}

/* ---- Tenant dashboard (single source of dashboard truth) ----------------- */
export interface TenantDashboard {
  user: {
    id: number;
    name: string;
    first_name: string;
    email: string;
    initials: string;
    city: string | null;
    user_type: UserType;
    identity_verified: boolean;
  };
  readiness: Readiness;
  stats: {
    applications_count: number;
    saved_listings_count: number;
    verified_listings_count: number;
    unread_notifications_count: number;
  };
  active_contract: Contract | null;
  rent_summary: {
    balance_cents: number;
    currency: string;
    has_history: boolean;
    next_due: {
      id: string;
      amount_cents: number;
      due_date: string | null;
      status: LedgerStatus;
      type: LedgerType;
    } | null;
  } | null;
  applications: Application[];
  curated_listings: Listing[];
  saved_listings: Listing[];
  recent_conversations: ConversationSummary[];
  notifications: AppNotification[];
  feature_availability: {
    applications: boolean;
    maintenance: boolean;
    documents: boolean;
    messages: boolean;
    compare: boolean;
  };
}

/* ---- Landlord dashboard (single source of dashboard truth) --------------- */
export interface LandlordDashboard {
  portfolio: {
    total_properties: number;
    total_units: number;
    occupied_units: number;
    vacant_units: number;
    active_listings: number;
    draft_listings: number;
    pending_review_listings: number;
  };
  contracts: {
    active: number;
    pending_tenant: number;
    draft: number;
    expiring_soon: number;
  };
  applications: {
    awaiting_review: number;
  };
  maintenance: {
    open: number;
    in_progress: number;
  };
  ledger: {
    outstanding_cents: number;
    overdue_cents: number;
    collected_this_month_cents: number;
    next_due_date: string | null;
  };
  /** Last 6 calendar months, oldest → newest. Drives the stat-card sparklines. */
  rent_trend: { month: string; collected_cents: number; outstanding_cents: number }[];
  recent_applications: Application[];
  recent_maintenance: MaintenanceRequest[];
  /** The landlord's own listings (newest first) — the real portfolio gallery. */
  recent_listings: Listing[];
}

/** Landlord onboarding/setup readiness (from GET /landlord/onboarding). */
export interface LandlordOnboarding {
  completion_percentage: number;
  steps: {
    key: string;
    title: string;
    description: string;
    completed: boolean;
    action: string | null;
    disabled?: boolean;
    help_text?: string | null;
  }[];
}

/* ---- Admin: user management --------------------------------------------- */
/** A row in the admin users list (User + withCount aggregates). */
export interface AdminUserSummary extends User {
  suspended_at: string | null;
  city?: string | null;
  initials?: string;
  properties_count: number;
  listings_count: number;
  applications_count: number;
}

/** Platform-wide segment counts driving the directory's filter tiles. */
export interface AdminUserCounts {
  all: number;
  landlords: number;
  tenants: number;
  unverified: number;
}

/** Paginated users list + the global segment counts. */
export type AdminUsersResponse = Paginated<AdminUserSummary> & {
  counts: AdminUserCounts;
};

/** Latest verification request attached to a user (for the review link). */
export interface AdminUserVerification {
  identity_verified: boolean;
  email_verified: boolean;
  latest_request: { id: string; status: string } | null;
}

export interface AdminUserDetail {
  user: User & {
    initials: string;
    suspended_at: string | null;
    city?: string | null;
    phone: string | null;
  };
  stats: {
    properties: number;
    listings: number;
    active_contracts: number;
    applications: number;
    /** Landlord-only: mean of approved reviews; null for tenants / no reviews. */
    rating: number | null;
    review_count: number;
  };
  verification: AdminUserVerification;
  recent_contracts: Contract[];
  recent_applications: Application[];
}

/* ---- Access control (Manage Users & Permissions) ------------------------- */

export interface AccessSummary {
  members_total: number;
  tenants: number;
  landlords: number;
  admins: number;
  super_admins: number;
  scoped_admins: number;
  pending_invites: number;
  deactivated_admins: number;
  suspended_users: number;
  blocked_users: number;
  archived_users: number;
}

export type AccessCellState = 'granted' | 'denied' | 'assignable';

export interface AccessMatrixCell {
  state: AccessCellState;
  locked: boolean;
  reason: string | null;
}

export interface AccessMatrixCapability {
  key: string;
  label: string;
  description: string;
  enforced: boolean;
  cells: Record<'tenant' | 'landlord' | 'admin' | 'super_admin', AccessMatrixCell>;
}

export interface AccessMatrixGroup {
  group: string;
  readonly: boolean;
  capabilities: AccessMatrixCapability[];
}

export interface AccessMatrixRole {
  id: 'tenant' | 'landlord' | 'admin' | 'super_admin';
  label: string;
  member_count: number;
  locked: boolean;
  note: string;
}

export interface AccessRolesMatrix {
  roles: AccessMatrixRole[];
  groups: AccessMatrixGroup[];
  note: string;
}

export type AdminTeamStatus = 'active' | 'invited' | 'deactivated';

export interface AdminTeamMember {
  id: number;
  name: string;
  email: string;
  is_super_admin: boolean;
  is_active: boolean;
  status: AdminTeamStatus;
  is_pending_invite: boolean;
  capabilities: AdminCapability[];
  capability_count: number;
  last_login_at: string | null;
  invited_at: string | null;
  created_at: string | null;
}

/* ---- Admin dashboard (platform command center, all real aggregates) ------ */
export interface DashboardOldestVerification {
  user_name: string;
  role: string | null;
  submitted_at: string | null;
  waiting_days: number;
}

export interface DashboardOldestListing {
  title: string | null;
  landlord_name: string | null;
  location: string | null;
  age_days: number;
}

export interface DashboardRentCase {
  ledger_entry_id: string;
  tenant: string | null;
  landlord: string | null;
  property: string | null;
  amount_cents: number;
  due_date: string | null;
  days_late: number;
  status: string;
  contract_id: string | null;
}

export interface DashboardMaintenanceSummary {
  open: number;
  urgent: number;
  overdue: number;
  waiting: number;
  oldest: {
    id: string;
    title: string;
    tenant: { id: number; name: string } | null;
    landlord: { id: number; name: string } | null;
    property: string | null;
    waiting_reason: string | null;
    age_days: number;
  } | null;
}

export interface DashboardNotificationFailureSummary {
  failed_total: number;
  critical_failed: number;
  latest: {
    recipient_name: string | null;
    type: string | null;
    error: string | null;
    occurred_at: string | null;
  } | null;
}

export interface DashboardFinanceIssues {
  count: number;
  window_days: number;
  latest: {
    recipient_name: string | null;
    amount_cents: number | null;
    error: string | null;
    occurred_at: string | null;
  } | null;
}

export interface DashboardPriorityCase {
  priority: 'high' | 'medium' | 'low';
  case_type: 'rent' | 'verification' | 'listing' | 'maintenance' | 'notification';
  person: string;
  role: string | null;
  related_property: string | null;
  issue_summary: string;
  age_days: number;
  action_label: string;
  action_route: string;
}

export interface AdminDashboard {
  properties: number;
  units: number;
  attention_queue: {
    verification: {
      pending: number;
      pending_by_role: { tenant: number; landlord: number };
      oldest: DashboardOldestVerification | null;
      action_route: string;
    };
    listings: {
      pending: number;
      oldest: DashboardOldestListing | null;
      action_route: string;
    };
    rent_risk: {
      overdue_count: number;
      overdue_total_cents: number;
      affected_tenants: number;
      oldest: DashboardRentCase | null;
      highest_risk: DashboardRentCase | null;
      action_route: string;
    };
    finance_issues: DashboardFinanceIssues & { action_route: string };
    maintenance: DashboardMaintenanceSummary & { action_route: string };
    notifications: DashboardNotificationFailureSummary & { action_route: string };
  };
  priority_cases: DashboardPriorityCase[];
  platform_snapshot: {
    users: {
      tenants: number;
      landlords: number;
      active: number;
      suspended: number;
      pending_verifications: number;
      new_this_week: number;
    };
    listings: {
      total: number;
      active: number;
      pending: number;
      draft: number;
      rejected: number;
      recently_submitted: number;
    };
    contracts: {
      active: number;
      ending_soon: number;
      awaiting_action: number;
      with_overdue_rent: number;
    };
    rent_ledger: {
      expected_this_month_cents: number;
      collected_this_month_cents: number;
      outstanding_cents: number;
      overdue_cents: number;
    };
    maintenance: DashboardMaintenanceSummary;
    notifications: DashboardNotificationFailureSummary;
  };
  rent_risk_monitor: {
    summary: {
      outstanding_cents: number;
      overdue_cents: number;
      affected_tenants: number;
      oldest_days_late: number;
      highest_overdue_cents: number;
    };
    cases: DashboardRentCase[];
  };
  review_queues: {
    verification: {
      id: string;
      user_name: string | null;
      role: string | null;
      submitted_at: string | null;
      document_count: number;
    }[];
    listings: Record<string, unknown>[];
  };
  system_health: {
    failed_jobs: number;
    failed_notifications: number;
    payment_failures_24h: number;
    scheduler: {
      rent_generation: { status: 'approximate' | 'not_tracked'; last_activity_at: string | null };
      overdue_marking: { status: 'approximate' | 'not_tracked'; last_activity_at: string | null };
    };
  };
  recent_activity: {
    id: string;
    title: string;
    action: string;
    severity: string;
    created_at: string | null;
    actor: string;
    detail_route: string;
  }[];
}

/* ---- Super Admin Platform Analytics --------------------------------------- */

export interface PlatformAnalyticsRentCase {
  id: string;
  tenant: string | null;
  landlord: string | null;
  property: string | null;
  display_amount_cents: number;
  due_date: string | null;
  days_late: number;
  status: string;
  contract_id: string | null;
}

export interface PlatformAnalyticsLedgerIssue {
  severity: 'fail' | 'warning';
  code: string;
  message: string;
  entry_ids: string[];
  contract_ids: string[];
  expected: string | null;
  actual: string | null;
  metadata: Record<string, unknown>;
}

export interface PlatformAnalyticsRiskItem {
  title: string;
  severity: 'critical' | 'high' | 'medium' | 'low';
  subject: string;
  area: string;
  route: string;
}

export interface PlatformAnalyticsByLandlord {
  landlord_id: number;
  name: string;
  outstanding_cents: number;
  overdue_cents: number;
}

export interface PlatformAnalyticsOverview {
  generated_at: string;
  overview: {
    landlords: number;
    tenants: number;
    admins: number;
    properties: number;
    units: number;
    active_listings: number;
    pending_listings: number;
    active_contracts: number;
    open_applications: number;
    pending_verifications: number;
    open_maintenance: number;
    outstanding_cents: number;
    overdue_cents: number;
    affected_tenants_overdue: number;
    new_landlords_this_month: number;
    new_tenants_this_month: number;
    landlords_with_overdue_balance: number;
    tenants_with_outstanding_balance: number;
    properties_with_open_maintenance: number;
    contracts_starting_this_month: number;
    contracts_ending_within_30_days: number;
    listings_needing_changes: number;
    verifications_pending_by_role: { tenant: number; landlord: number };
    verifications_overdue: number;
    maintenance_emergency: number;
    maintenance_overdue: number;
  };
  financial: {
    rent_charged_cents: number;
    fees_charged_cents: number;
    collected_cents: number;
    outstanding_cents: number;
    overdue_cents: number;
    due_soon_cents: number;
    entry_count: number;
    collection_rate_percentage: number;
    revenue_by_month: Record<string, number>;
    billed_by_month: Record<string, number>;
    collected_by_month: Record<string, number>;
    outstanding_by_age: { label: string; amount_cents: number; entry_count: number; tenant_count: number }[];
    outstanding_by_landlord: PlatformAnalyticsByLandlord[];
    outstanding_trend_by_month: Record<string, number>;
  };
  ledger_integrity: {
    status: 'pass' | 'warning' | 'fail';
    issue_count: number;
    issues: PlatformAnalyticsLedgerIssue[];
  };
  rent_collection: {
    on_time_count: number;
    on_time_rate_percentage: number;
    late_count: number;
    late_rate_percentage: number;
    missed_count: number;
    missed_rate_percentage: number;
    waived_count: number;
    average_days_late: number;
    repeat_late_tenant_count: number;
    top_overdue_cases: PlatformAnalyticsRentCase[];
    top_landlords_by_overdue: PlatformAnalyticsByLandlord[];
  };
  users: {
    total_users: number;
    users_by_role: Record<string, number>;
    tenants: { total: number; active: number; new_this_period: number };
    landlords: { total: number; active: number; new_this_period: number };
    admins: { total: number; active: number; super_admins: number };
    signups_by_month: Record<string, { tenants: number; landlords: number }>;
  };
  listings: {
    by_status: Record<string, number>;
    average_approval_time_hours: number;
    total_listings: number;
    active_listings: number;
    listing_to_contract_conversion_rate: number;
    occupancy: {
      total_units: number;
      occupied_units: number;
      vacant_units: number;
      occupancy_rate_percentage: number;
      average_vacancy_duration_days: number;
    };
  };
  contracts: {
    total_contracts: number;
    active_contracts: number;
    terminated_contracts: number;
    expired_contracts: number;
    contracts_by_status: Record<string, number>;
    average_contract_duration_days: number;
    early_termination_rate: number;
    renewal_rate: number;
  };
  applications: {
    submitted_total: number;
    in_review: number;
    needs_action: number;
    approved: number;
    rejected: number;
    withdrawn: number;
    stale_count: number;
    stale_threshold_days: number;
    average_review_time_hours: number;
    approval_rate_percentage: number;
    submissions_by_month: Record<string, number>;
  };
  verifications: {
    pending: number;
    needs_more_information: number;
    verified: number;
    rejected: number;
    missing_documents: number;
    previously_rejected_now_active: number;
    pending_by_role: { tenant: number; landlord: number };
    oldest_pending: Record<string, unknown> | null;
    average_review_time_hours: number;
    overdue_count: number;
  };
  maintenance: {
    open: number;
    urgent: number;
    overdue: number;
    waiting: number;
    oldest: Record<string, unknown> | null;
    resolved_count: number;
    average_response_hours: number;
    average_resolution_days: number;
    repeat_issue_properties: number;
    by_priority: Record<string, number>;
    by_category: Record<string, number>;
    resolution_trend_by_month: Record<string, number>;
  };
  notifications: {
    volume: { total_notifications: number; by_type: Record<string, number>; by_day: Record<string, number>; by_user_role: Record<string, number>; per_user_avg: number };
    delivery: {
      email_delivered: number;
      sms_delivered: number;
      email_pending: number;
      sms_pending: number;
      email_failed: number;
      sms_failed: number;
      email_success_rate: number;
      sms_success_rate: number;
    };
    performance: { avg_delivery_latency_seconds: number; p50_latency_seconds: number; p95_latency_seconds: number; min_latency_seconds: number; max_latency_seconds: number };
    preferences: Record<string, number>;
    digests: Record<string, number>;
  };
  admin_activity: {
    logins_24h: number;
    sensitive_actions_period: number;
    permission_changes_period: number;
    failed_access_attempts_period: number;
    by_admin: {
      admin_id: number;
      actions: number;
      sensitive_actions: number;
      name: string;
      is_super_admin: boolean;
      capabilities: string[];
      last_active_at: string | null;
    }[];
    recent: {
      created_at: string | null;
      admin_name: string;
      action: string;
      title: string;
      description: string | null;
      area: string;
    }[];
  };
  risk: PlatformAnalyticsRiskItem[];
  system_health: {
    failed_jobs: number;
    failed_notifications: number;
    payment_failures_24h: number;
  };
  exports: {
    recent_exports: { id: string; action: string; by: string; description: string | null; sensitive: boolean; created_at: string | null }[];
  };
}

export interface PlatformAnalyticsResponse {
  range: { key: string; start_date: string | null; end_date: string | null };
  analytics: PlatformAnalyticsOverview;
}

/* ---- Admin Analytics (scoped to the signed-in admin) ---------------------- */

export interface AdminAnalyticsListingRow {
  id: string;
  title: string | null;
  landlord: string | null;
  location: string | null;
  route: string;
}

export interface AdminAnalyticsVerificationRow {
  id: string;
  name: string | null;
  role: string | null;
  submitted_at: string | null;
  route: string;
}

export interface AdminAnalyticsMaintenanceRow {
  id: string;
  title: string;
  property: string | null;
  priority: string;
  age_days: number;
  route: string;
}

export interface AdminAnalyticsLedgerRow {
  id: string;
  tenant: string | null;
  amount_cents: number;
  days_late: number;
  route: string;
}

export interface AdminAnalyticsNotificationRow {
  id: number;
  recipient: string | null;
  type: string | null;
  error: string | null;
  occurred_at: string | null;
  route: string;
}

export interface AdminAnalyticsListingsModule {
  counts: Record<string, number>;
  oldest_pending_age_hours: number | null;
  queue_preview: AdminAnalyticsListingRow[];
  my_decisions: { approved: number; rejected: number; sent_back: number };
  /** Platform-wide (every admin's decisions), not personal — why listings fail review across the whole team. */
  top_reasons: AdminAnalyticsReason[];
  route: string;
}

export interface AdminAnalyticsVerificationsModule {
  summary: {
    pending: number;
    needs_more_information: number;
    verified: number;
    rejected: number;
    missing_documents: number;
    previously_rejected_now_active: number;
    pending_by_role: { tenant: number; landlord: number };
    oldest_pending: { user_name: string; role: string | null; submitted_at: string | null; waiting_days: number } | null;
  };
  timing: { average_review_time_hours: number; overdue_count: number };
  queue_preview: AdminAnalyticsVerificationRow[];
  my_decisions: { approved: number; rejected: number; sent_back: number };
  route: string;
}

export interface AdminAnalyticsMaintenanceModule {
  summary: {
    open: number;
    urgent: number;
    overdue: number;
    waiting: number;
    oldest: Record<string, unknown> | null;
  };
  /** Real open-request counts keyed by MaintenanceStatus value (e.g. "in_progress", "waiting"). */
  by_status: Record<string, number>;
  queue_preview: AdminAnalyticsMaintenanceRow[];
  route: string;
}

/** An attention/risk-queue item, enriched with age + a suggested next action beyond the base PlatformAnalyticsRiskItem shape. */
export interface AdminAnalyticsAttentionItem extends PlatformAnalyticsRiskItem {
  age_hours: number | null;
  /** Human string like "2d 4h" or "<1h"; null when no comparable clock exists for this item. */
  age: string | null;
  action: string;
}

export interface AdminAnalyticsDecisionTrendPoint {
  week: string;
  approved: number;
  rejected: number;
  sent_back: number;
}

export interface AdminAnalyticsReason {
  reason: string;
  count: number;
}

export interface AdminAnalyticsLedgerModule {
  overdue_count: number;
  overdue_cents: number;
  outstanding_cents: number;
  affected_tenants: number;
  /** Period-scoped (respects the selected range), unlike the all-time overdue/outstanding figures above. */
  collected_cents: number;
  charged_cents: number;
  queue_preview: AdminAnalyticsLedgerRow[];
  route: string;
}

export interface AdminAnalyticsNotificationChannel {
  channel: string;
  sent: number;
  failed: number;
}

export interface AdminAnalyticsNotificationsModule {
  failed_total: number;
  email_failed: number;
  sms_failed: number;
  recent_failures: AdminAnalyticsNotificationRow[];
  channel: AdminAnalyticsNotificationChannel[];
  route: string;
}

export interface AdminAnalyticsActivityRow {
  id: number;
  title: string;
  area: string;
  severity: string;
  /** "Important" | "Needs review" | "Routine" | "Export" — mirrors AuditClassifier::classification(). */
  type: string;
  created_at: string | null;
  detail_route: string | null;
}

export interface AdminAnalyticsSummary {
  generated_at: string;
  admin: { name: string; is_super_admin: boolean };
  scope: { permitted_modules: string[]; restricted_modules: string[] };
  attention: AdminAnalyticsAttentionItem[];
  workload: { pending_total: number; by_module: Record<string, number> };
  modules: {
    listings?: AdminAnalyticsListingsModule;
    verifications?: AdminAnalyticsVerificationsModule;
    maintenance?: AdminAnalyticsMaintenanceModule;
    ledger?: AdminAnalyticsLedgerModule;
    notifications?: AdminAnalyticsNotificationsModule;
  };
  me: {
    actions_period: number;
    sensitive_actions_period: number;
    decisions_period: number;
    exports_period: number;
    recent_activity: AdminAnalyticsActivityRow[];
    /** Covers Listings + Verifications decisions only — see backend AdminAnalyticsService docblock for why Ledger/Maintenance are excluded. */
    decision_trend: AdminAnalyticsDecisionTrendPoint[];
    outcome_totals: { approved: number; rejected: number; sent_back: number };
    top_reasons: AdminAnalyticsReason[];
    avg_decision_hours: { listings: number | null; verifications: number | null };
  };
}

export interface AdminAnalyticsResponse {
  range: { key: string; start_date: string | null; end_date: string | null };
  analytics: AdminAnalyticsSummary;
}

export interface AdminMaintenanceCase {
  id: string;
  title: string;
  category: string | null;
  priority: string | null;
  status: string | null;
  tenant: { id: number; name: string } | null;
  landlord: { id: number; name: string } | null;
  property: string | null;
  waiting_reason: string | null;
  submitted_at: string | null;
  age_days: number;
  expected_completion_date: string | null;
  is_overdue: boolean;
  has_severe_safety_flag: boolean;
  handling_admin: { id: number; name: string } | null;
  escalated_at: string | null;
  escalation_reason: string | null;
}

export interface AdminMaintenanceEvent {
  id: number;
  event: string;
  description: string;
  actor_type: string | null;
  actor_name: string | null;
  created_at: string | null;
}

export interface AdminMaintenanceNote {
  id: number;
  body: string;
  admin_id: number;
  admin_name: string | null;
  created_at: string | null;
}

export interface AdminMaintenanceDetail extends AdminMaintenanceCase {
  description: string;
  assignee_name: string | null;
  assignee_phone: string | null;
  assignee_type: string | null;
  appointment_at: string | null;
  resolution_notes: string | null;
  labor_cost_cents: number | null;
  parts_cost_cents: number | null;
  total_cost_cents: number | null;
  invoice_reference: string | null;
  cost_notes: string | null;
  cost_paid: boolean | null;
  acknowledged_at: string | null;
  assigned_at: string | null;
  resolved_at: string | null;
  closed_at: string | null;
  safety_flags: string[] | null;
  events: AdminMaintenanceEvent[];
  media: { id: string; caption: string | null; created_at: string | null }[];
  admin_notes: AdminMaintenanceNote[];
}

export interface AdminMaintenanceAnalytics {
  resolved_count: number;
  average_response_hours: number;
  average_resolution_days: number;
  repeat_issue_properties: number;
  by_priority: Record<string, number>;
  by_category: Record<string, number>;
  resolution_trend_by_month: Record<string, number>;
}

export interface AdminMaintenanceOversight {
  open_platform_wide: number;
  unresolved_safety_flags: AdminMaintenanceCase[];
  landlords_with_repeat_overdue: { landlord_id: number; landlord_name: string | null; overdue_count: number }[];
  admin_caseload: { admin_id: number; admin_name: string | null; open_case_count: number }[];
  properties_with_recurring_issues: { property_id: number; property_name: string | null; open_case_count: number }[];
}

/* ---- Admin platform delivery monitor ------------------------------------- */
/**
 * Per-channel delivery outcome for a notification. `not_sent` covers queued,
 * user-disabled-channel, or digest-deferred — it is neither a failure nor a
 * success, so the UI must label it neutrally (never "pending"/"failed").
 */
export interface AdminNotificationDeliveryChannel {
  status: 'delivered' | 'failed' | 'not_sent';
  at: string | null;
  error: string | null;
}

export interface AdminNotificationDelivery {
  id: string;
  type: string;
  title: string;
  created_at: string;
  read_at: string | null;
  recipient: { id: number; name: string; email: string; user_type: string | null } | null;
  email: AdminNotificationDeliveryChannel;
  sms: AdminNotificationDeliveryChannel;
}

export interface AdminNotificationDeliveriesResponse {
  data: AdminNotificationDelivery[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  summary: {
    total: number;
    email: { delivered: number; failed: number };
    sms: { delivered: number; failed: number };
    failed_total: number;
  };
}

/* ---- Verification -------------------------------------------------------- */
/**
 * A *user's* identity state (`users.verification_status`). The terminal
 * approved state here is `verified`.
 */
export type VerificationStatus =
  | 'unverified'
  | 'pending'
  | 'under_review'
  | 'verified'
  | 'rejected'
  | 'needs_more_information';

/**
 * A verification *request record's* lifecycle (`verification_requests.status`).
 * Distinct vocabulary from {@link VerificationStatus}: the approved terminal
 * state is `approved` (never `verified`/`unverified`). The admin moderation
 * filters and badges speak this vocabulary.
 */
export type VerificationRequestStatus =
  | 'pending'
  | 'under_review'
  | 'approved'
  | 'rejected'
  | 'needs_more_information';

export interface VerificationRequest {
  id: string; // UUID
  user_id: number;
  status: VerificationRequestStatus;
  note: string | null;
  decision_reason: string | null;
  reviewed_by_admin_id: number | null;
  submitted_at: string | null;
  reviewed_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface VerificationStatusResponse {
  verification_status: VerificationStatus | null;
  identity_verified: boolean;
  latest_request: VerificationRequest | null;
}

export interface VerificationSubmitResponse {
  message: string;
  verification_request: VerificationRequest;
}

/* ---- Reviews ------------------------------------------------------------- */
export type ReviewStatus = 'pending' | 'approved' | 'rejected' | 'hidden' | 'flagged';

export interface Review {
  id: number;
  reviewer_user_id: number;
  property_id: number;
  unit_id: number;
  landlord_id: number;
  contract_id: string;
  rating: number; // 1-5
  title: string | null;
  body: string;
  status: ReviewStatus;
  moderation_reason: string | null;
  landlord_response: string | null;
  responded_at: string | null;
  property?: Property;
  contract?: Contract;
  created_at: string;
  updated_at: string;
}

export interface ReviewEligibility {
  eligible: boolean;
  contract_id: string | null;
}

/* ---- Media asset --------------------------------------------------------- */
export interface MediaAsset {
  /** UUID primary key (HasUuids on MediaAsset model). */
  id: string;
  owner_user_id: number;
  collection: string;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  alt_text: string | null;
  caption: string | null;
  sort_order: number;
  status: string;
  url: string | null;
  created_at: string;
  updated_at: string;
}

/* ---- Landlord reviews ---------------------------------------------------- */
export interface ReviewSummary {
  property_id: number;
  average_rating: number;
  review_count: number;
}

export interface LandlordReviewsResponse {
  reviews: Review[];
  summary: ReviewSummary[];
}

/** Reviewer info attached to a review when returned from the landlord endpoint. */
export interface ReviewWithReviewer extends Review {
  reviewer?: Pick<User, 'id' | 'first_name' | 'last_name' | 'full_name' | 'avatar_url'>;
}

/* ---- Admin verification types -------------------------------------------- */

/** Admin-facing verification request with populated relations */
export interface AdminVerificationRequest extends VerificationRequest {
  user?: User;
  reviewer?: Admin | null;
  documents_count?: number;
}

/** Verification document attached to a request */
export interface VerificationDocument {
  id: number;
  owner_user_id: number;
  uploaded_by_id: number;
  document_type: DocumentType;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  is_verified: boolean;
  verified_at: string | null;
  related_type: string;
  related_id: string;
  created_at: string;
}

/** Truthful counts for the verification queue's summary cards. */
export interface VerificationSummary {
  pending: number;
  needs_more_information: number;
  verified: number;
  rejected: number;
  missing_documents: number;
  previously_rejected_now_active: number;
}

export type ChecklistResult = 'passed' | 'warning' | 'failed' | 'not_applicable' | 'manual';

/** One computed identity-match checklist row — never all hardcoded to "passed". */
export interface VerificationChecklistItem {
  key: string;
  label: string;
  result: ChecklistResult;
  detail: string;
  required: boolean;
  role_scope: 'all' | 'tenant' | 'landlord';
}

/** A real audit-log event tied to this case (submission, decision, note, doc view). */
export interface VerificationHistoryEvent {
  id: number;
  action: string;
  description: string | null;
  severity: 'info' | 'warning' | 'critical';
  created_at: string;
  actor: { type: 'admin' | 'user'; name: string } | null;
  metadata: Record<string, unknown> | null;
}

/** Internal, admin-only note attached to a verification request. */
export interface VerificationNote {
  id: number;
  verification_request_id: string;
  admin_id: number;
  body: string;
  admin?: Admin;
  created_at: string;
}

/** Full case-file payload for the detail page. */
export interface AdminVerificationDetail extends AdminVerificationRequest {
  documents: VerificationDocument[];
  reviewable: boolean;
  checklist: VerificationChecklistItem[];
  warnings: string[];
  history: VerificationHistoryEvent[];
  previous_attempts: VerificationRequest[];
  notes: VerificationNote[];
  unlock_description: string;
}

/* ---- Admin Review Moderation ---------------------------------------------- */

/** A truthful, computed moderation signal — no toxicity/spam/PII scoring exists. */
export interface AdminReviewSignal {
  key: 'flagged' | 'low_rating' | 'long_pending' | 'first_review';
  label: string;
  severity: 'high' | 'medium' | 'info';
}

export interface AdminReviewQueueCounts {
  pending: number;
  flagged: number;
  awaiting: number;
  low_rated_awaiting: number;
  approved: number;
  approved_week: number;
  rejected: number;
  hidden: number;
  all: number;
}

/** Compact per-row summary for the moderation queue. */
export interface AdminReviewSummary {
  id: number;
  rating: number;
  title: string | null;
  body: string;
  status: ReviewStatus;
  moderation_reason: string | null;
  landlord_response: string | null;
  responded_at: string | null;
  created_at: string;
  updated_at: string;
  reviewer: { id: number; name: string } | null;
  property: { id: number; name: string; city: string | null } | null;
  landlord: { id: number; name: string } | null;
  moderator: { id: number; name: string } | null;
  /** The real contract status backing this review — every review requires one. */
  contract_status: string | null;
  signals: AdminReviewSignal[];
}

export interface AdminReviewTimelineEvent {
  key: string;
  label: string;
  at: string | null;
  actor: string | null;
  detail: string | null;
  severity: 'info' | 'success' | 'danger' | 'warning';
}

/** Full moderation detail: reviewer history plus the real audit-log timeline. */
export interface AdminReviewDetail extends AdminReviewSummary {
  reviewer_stats: { review_count: number; average_rating: number } | null;
  timeline: AdminReviewTimelineEvent[];
}

export interface AdminReviewQueueResponse {
  counts: AdminReviewQueueCounts;
  data: AdminReviewSummary[];
}

/* ---- Admin Listing Review ------------------------------------------------ */

export type ChecklistStatus = 'pass' | 'warn' | 'fail' | 'na';

/** One truthful compliance check computed from real listing data. */
export interface ReviewChecklistItem {
  key: string;
  label: string;
  status: ChecklistStatus;
  detail: string | null;
}

export interface ReviewWarning {
  key: string;
  label: string;
  severity: 'high' | 'medium' | 'low';
}

export interface ReviewCompleteness {
  passed: number;
  total: number;
  percent: number;
}

export interface ReviewPhoto {
  id: number | string;
  url: string | null;
  alt_text: string | null;
  caption: string | null;
  is_primary: boolean;
  created_at: string | null;
}

export interface ReviewTimelineEvent {
  key: string;
  label: string;
  at: string | null;
  actor: string | null;
  detail: string | null;
  severity: 'info' | 'success' | 'danger' | 'warning';
}

export interface ListingReviewNote {
  id: number;
  body: string;
  admin_id: number | null;
  admin_name: string | null;
  created_at: string | null;
}

export interface ListingReviewSummary {
  id: number;
  title: string;
  status: ListingStatus;
  status_label: string;
  submitted_at: string | null;
  reviewed_at: string | null;
  landlord: {
    id: number | null;
    name: string;
    identity_verified: boolean;
    verification_status: string | null;
  };
  unit: {
    unit_number: string;
    bedrooms: string;
    bathrooms: string;
    rent_amount: string;
  } | null;
  property_name: string | null;
  location: string | null;
  cover_photo: string | null;
  photo_count: number;
  warning_count: number;
  missing_count: number;
  completeness: ReviewCompleteness;
  /** Truthful signal flags derived from the checklist, for filter chips. */
  flags: ListingReviewFlags;
  rejection_reason: string | null;
}

/** Boolean signal flags for queue filter chips — each maps to a real check. */
export interface ListingReviewFlags {
  few_photos: boolean;
  duplicate: boolean;
  unverified_host: boolean;
  contact_info: boolean;
  policy: boolean;
}

export interface ListingReviewQueue {
  counts: {
    pending: number;
    approved: number;
    rejected: number;
    all: number;
    approved_today: number;
    needs_attention: number;
    missing_info: number;
  };
  data: ListingReviewSummary[];
}

export interface ListingReviewLandlord {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  avatar_url: string | null;
  account_status: string | null;
  verification_status: string | null;
  identity_verified: boolean;
  created_at: string | null;
  active_listings: number;
  rejected_listings: number;
  total_listings: number;
}

export interface ListingReviewVerification {
  property_active: boolean;
  unit_active: boolean;
  unit_availability: string | null;
  unit_availability_label: string | null;
  unit_can_be_listed: boolean;
  duplicate_active_listing: boolean;
  landlord_identity_verified: boolean;
}

/** Guarded price context: median comparison only when there are real comparables. */
export interface ReviewPricing {
  rent: number | null;
  deposit: number | null;
  deposit_months: number | null;
  area: string | null;
  has_comparison: boolean;
  median: number | null;
  comparable_count: number;
  percent_diff: number | null;
  is_outlier: boolean;
}

/** Matched spans in the description, surfaced so the UI can highlight them. */
export interface ReviewContentFlags {
  pii: string[];
  policy_phrases: string[];
}

/** What the tenant sees of the address, and the real rule behind it. */
export interface ReviewAddressVisibility {
  admin_full_address: string | null;
  street_address: string | null;
  tenant_area: string | null;
  street_public: boolean;
  rule: string;
}

export interface ListingReviewDetail {
  id: number;
  title: string;
  description: string | null;
  status: ListingStatus;
  status_label: string;
  rejection_reason: string | null;
  changes_requested_reason: string | null;
  changes_requested_at: string | null;
  featured: boolean;
  view_count: number;
  pets_allowed: boolean;
  pet_policy: string | null;
  lease_duration_months: number | null;
  move_in_date: string | null;
  published_at: string | null;
  expires_at: string | null;
  reviewed_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  unit: {
    id: number;
    unit_number: string;
    internal_name: string | null;
    bedrooms: string;
    bathrooms: string;
    square_feet: number | null;
    rent_amount: string;
    security_deposit: string | null;
    availability_status: string | null;
    availability_label: string | null;
    available_from: string | null;
    amenities: string[];
    is_active: boolean;
  } | null;
  property: {
    id: number;
    name: string;
    property_type: string | null;
    full_address: string | null;
    street_address: string | null;
    city: string | null;
    state: string | null;
    zip_code: string | null;
    country: string | null;
    year_built: number | null;
    description: string | null;
    is_active: boolean;
  } | null;
  landlord: ListingReviewLandlord | null;
  photos: ReviewPhoto[];
  photo_count: number;
  verification: ListingReviewVerification;
  checklist: ReviewChecklistItem[];
  warnings: ReviewWarning[];
  content_flags: ReviewContentFlags;
  completeness: ReviewCompleteness;
  ready_for_approval: boolean;
  pricing: ReviewPricing;
  address_visibility: ReviewAddressVisibility;
  timeline: ReviewTimelineEvent[];
  notes: ListingReviewNote[];
  reviewer: { id: number; name: string } | null;
  reviewable: boolean;
}

/** Tenant-safe preview: exactly what a tenant sees once published. */
export interface ListingPreview {
  id: number;
  title: string;
  description: string | null;
  status: ListingStatus;
  pets_allowed: boolean;
  pet_policy: string | null;
  lease_duration_months: number | null;
  move_in_date: string | null;
  photos: ReviewPhoto[];
  photo_count: number;
  unit: {
    unit_number: string;
    bedrooms: string;
    bathrooms: string;
    square_feet: number | null;
    rent_amount: string;
    security_deposit: string | null;
    available_from: string | null;
    amenities: string[];
  } | null;
  property: {
    name: string;
    property_type: string | null;
    city: string | null;
    state: string | null;
    full_address: string | null;
  } | null;
  landlord: { name: string; identity_verified: boolean } | null;
}

/* ---- Admin Contracts case-file command centre ---------------------------- */

export type ContractSegment = 'draft' | 'awaiting' | 'active' | 'expiring' | 'overdue' | 'ended';

export type ContractHealth =
  | 'draft'
  | 'awaiting_signatures'
  | 'good_standing'
  | 'ending_soon'
  | 'overdue'
  | 'closed';

export interface ContractSummary {
  id: string;
  reference: string;
  status: ContractStatus;
  status_label: string;
  segment: ContractSegment;
  property_name: string | null;
  unit_name: string | null;
  city: string | null;
  tenant_name: string;
  landlord_name: string;
  rent_amount: number;
  start_date: string | null;
  end_date: string | null;
  term_progress_percent: number;
  payment_status: string;
  warning_count: number;
}

export interface ContractQueueCounts {
  total: number;
  active: number;
  awaiting_signatures: number;
  expiring_soon: number;
  overdue: number;
  ended: number;
  draft: number;
}

export interface ContractQueue {
  counts: ContractQueueCounts;
  /** Count of the filtered result set (distinct from counts.total). */
  total: number;
  data: ContractSummary[];
}

export interface ContractChecklistItem {
  key: string;
  label: string;
  status: 'pass' | 'warn' | 'fail';
  detail: string | null;
}

export interface ContractWarning {
  key: string;
  label: string;
  severity: 'high' | 'medium';
}

export interface ContractFinancials {
  monthly_rent_cents: number;
  current_balance_cents: number;
  overdue_cents: number;
  due_soon_cents: number;
  total_paid_cents: number;
  security_deposit_cents: number | null;
  payment_status: string;
  lease_remaining_days: number | null;
  next_due_date: string | null;
}

export interface ContractParty {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  verification_status: string | null;
  identity_verified: boolean;
  account_status: string | null;
  contract_balance_cents: number;
}

export interface ContractTerms {
  start_date: string | null;
  end_date: string | null;
  duration_months: number | null;
  rent_amount_cents: number;
  billing_cycle: string;
  payment_day: number;
  security_deposit_cents: number | null;
  grace_period: string;
  late_fee_rule: string;
  utilities_responsibility: string;
  maintenance_responsibility: string;
  pets_policy: string;
  smoking_policy: string;
  occupancy_limit: string;
  renewal_type: string;
  termination_notice_period: string;
  early_termination_penalty: string;
  special_clauses: string;
}

export interface ContractRenewal {
  end_date: string | null;
  days_remaining: number | null;
  ending_soon: boolean;
  notice_period: string;
  renewal_request_status: string;
}

export interface ContractNote {
  id: number;
  body: string;
  admin_id: number | null;
  admin_name: string | null;
  created_at: string | null;
}

export interface ContractCaseFileDetail {
  id: string;
  status: ContractStatus;
  status_label: string;
  health: ContractHealth;
  rent_amount: number;
  currency: string;
  billing_cycle: string;
  payment_day: number;
  start_date: string | null;
  end_date: string | null;
  terminated_by: TerminatedBy | null;
  termination_reason: string | null;
  created_at: string | null;
  financials: ContractFinancials;
  checklist: ContractChecklistItem[];
  warnings: ContractWarning[];
  completeness: { passed: number; total: number; percent: number };
  parties: { tenant: ContractParty | null; landlord: ContractParty | null };
  property: {
    id: number;
    name: string;
    property_type: string | null;
    full_address: string | null;
    city: string | null;
    state: string | null;
    is_active: boolean;
  } | null;
  unit: {
    id: number;
    display_name: string;
    unit_number: string;
    bedrooms: string;
    bathrooms: string;
    square_feet: number | null;
    security_deposit: string | null;
    availability_status: string | null;
    availability_label: string | null;
  } | null;
  listing: { id: number; title: string; status: string } | null;
  terms: ContractTerms;
  renewal: ContractRenewal;
  notes: ContractNote[];
  admin: { id: number; name: string } | null;
}

export interface ContractLedgerResponse {
  entries: LedgerEntry[];
  summary: ContractFinancials;
}

export type ContractPayment = LedgerEntry & { method: string };

export interface ContractBillingPeriod {
  billing_period_start: string;
  billing_period_end: string;
  due_date: string;
  amount_cents: number;
  status: string;
  generated: boolean;
}

export interface ContractTimelineEvent {
  key: string;
  label: string;
  at: string | null;
  actor: string | null;
  detail: string | null;
  severity: 'info' | 'success' | 'danger';
}

export interface ContractDocument {
  id: string;
  collection: string;
  original_filename: string | null;
  visibility: string;
  url: string | null;
  created_at: string | null;
}

/* ---- Standardized client error ------------------------------------------- */
export interface ApiError {
  status: number;
  message: string;
  /** Laravel 422 field errors, if present. */
  errors?: Record<string, string[]>;
}
