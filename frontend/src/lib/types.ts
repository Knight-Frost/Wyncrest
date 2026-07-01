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
  | 'other';

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

export type NotificationType =
  | 'rent_generated'
  | 'rent_due_soon'
  | 'rent_overdue'
  | 'payment_succeeded'
  | 'payment_failed'
  | 'late_fee_added'
  | 'contract_signed'
  | 'contract_terminated';

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
  user_type: UserType;
  is_active: boolean;
  identity_verified: boolean;
  verification_status?: string;
  account_status?: string;
  created_at: string;
}

export interface Admin {
  id: number;
  email: string;
  name: string;
  is_super_admin: boolean;
  is_active: boolean;
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
  is_active: boolean;
  units_count?: number;
  units?: Unit[];
  /** Real per-property aggregates (present on GET /landlord/properties). */
  occupied_units?: number;
  vacant_units?: number;
  occupancy_rate?: number;
  collected_this_month_cents?: number;
  /** Gallery media (present on GET /landlord/properties/{id}), ordered by sort_order. */
  media_assets?: MediaAsset[];
  created_at: string;
  updated_at: string;
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
  published_at: string | null;
  expires_at: string | null;
  featured: boolean;
  view_count: number;
  pets_allowed: boolean;
  pet_policy: string | null;
  lease_duration_months: number | null;
  move_in_date: string | null;
  unit?: Unit;
  landlord?: User;
  photos?: ListingPhoto[];
  primary_photo?: ListingPhoto | null;
  /** Gallery media (present on GET /landlord/listings/{id}), ordered by sort_order. */
  media_assets?: MediaAsset[];
  created_at: string;
  updated_at: string;
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
  end_date: string;
  status: ContractStatus;
  terminated_by: TerminatedBy | null;
  termination_reason: string | null;
  landlord?: User;
  tenant?: User;
  listing?: Listing;
  created_at: string;
}

export interface LedgerEntry {
  id: string; // UUID
  contract_id: string;
  tenant_id: number;
  landlord_id: number;
  type: LedgerType;
  amount_cents: number; // cents
  currency: string;
  billing_period_start: string | null;
  billing_period_end: string | null;
  due_date: string | null;
  status: LedgerStatus;
  related_rent_entry_id: string | null;
  stripe_payment_intent_id: string | null;
  contract?: Contract;
  /** Present on landlord/tenant-scoped responses (eager-loaded). */
  tenant?: User;
  /** Derived display reference (e.g. INV-20250528-A1B2C3); not stored. */
  reference?: string;
  /** Running outstanding balance for the contract after this entry (cents). */
  balance_after_cents?: number;
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

/** Additional fields returned by GET /admin/audit-logs/{id}. */
export interface AuditLogDetail extends AuditLog {
  user_agent: string | null;
  device: string | null;
  actor_type: string | null;
  subject: { type: string; id: number; label: string } | null;
  metadata: Record<string, unknown> | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  /** The prior row's hash this entry commits to (GENESIS for the first row). */
  previous_hash: string;
  why_it_matters: string;
  recommended_steps: { label: string; to: string | null }[];
}

/** Result of GET /admin/audit-logs/verify — real SHA-256 chain recomputation. */
export interface AuditVerify {
  intact: boolean;
  verified: number;
  total: number;
  head: string | null;
  broken_at: number | null;
  checked_at: string;
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

export interface Feature {
  id: number;
  key: string;
  name: string;
  description: string | null;
  is_available: boolean;
  enabled?: boolean;
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

/* ---- Applications -------------------------------------------------------- */
export type ApplicationStatus =
  | 'submitted'
  | 'in_review'
  | 'landlord_review'
  | 'approved'
  | 'rejected'
  | 'withdrawn';

export interface Application {
  id: number;
  tenant_id: number;
  listing_id: number;
  landlord_id: number;
  status: ApplicationStatus;
  cover_note: string | null;
  /** Visible only on the landlord-scoped endpoints. */
  landlord_notes?: string | null;
  decision_reason: string | null;
  submitted_at: string | null;
  reviewed_at: string | null;
  decided_at: string | null;
  withdrawn_at: string | null;
  listing?: Listing;
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
  | 'in_progress'
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
  | 'general';

export interface MaintenanceRequest {
  id: number;
  tenant_id: number;
  contract_id: string;
  property_id: number;
  unit_id: number;
  landlord_id: number;
  title: string;
  description: string;
  category: MaintenanceCategory;
  priority: MaintenancePriority;
  status: MaintenanceStatus;
  resolution_notes: string | null;
  submitted_at: string | null;
  acknowledged_at: string | null;
  resolved_at: string | null;
  closed_at: string | null;
  property?: Property;
  unit?: Unit;
  contract?: Contract;
  created_at: string;
}

/* ---- Documents ----------------------------------------------------------- */
export type DocumentType =
  | 'identity_document'
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
  landlord: { id: number; name: string };
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
  other_participant: { id: number; name: string; initials?: string; role?: string | null } | null;
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
  sender: { id: number; name: string | null; is_me: boolean };
}

export interface ConversationDetail {
  conversation: {
    id: number;
    title: string | null;
    status: string;
    last_message_at: string | null;
    thumbnail_url?: string | null;
    other_participant: { id: number; name: string; role?: string | null } | null;
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
  created_at: string | null;
}
export interface TenantProfileResponse {
  user: TenantProfile;
  readiness: Readiness;
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
  properties_count: number;
  listings_count: number;
  applications_count: number;
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
  };
  recent_contracts: Contract[];
  recent_applications: Application[];
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
  | 'manage_settings';

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
export interface AdminDashboard {
  statistics: {
    landlords: number;
    tenants: number;
    properties: number;
    units: number;
    pending_listings: number;
    active_listings: number;
    total_listings: number;
    active_contracts: number;
  };
  contracts: {
    draft: number;
    pending_tenant: number;
    active: number;
    terminated: number;
    expired: number;
  };
  ledger: {
    outstanding_cents: number;
    overdue_cents: number;
    collected_this_month_cents: number;
  };
  listings_by_status: {
    draft: number;
    pending_review: number;
    active: number;
    rejected: number;
    inactive: number;
    archived: number;
  };
  recent_listings: Listing[];
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
export type VerificationStatus =
  | 'unverified'
  | 'pending'
  | 'under_review'
  | 'verified'
  | 'rejected'
  | 'needs_more_information';

export interface VerificationRequest {
  id: string; // UUID
  user_id: number;
  status: VerificationStatus;
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
  reviewer?: Pick<User, 'id' | 'first_name' | 'last_name' | 'full_name'>;
}

/* ---- Admin verification types -------------------------------------------- */

/** Admin-facing verification request with populated relations */
export interface AdminVerificationRequest extends VerificationRequest {
  user?: User;
  reviewer?: Admin | null;
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

/** Admin verification request detail with documents */
export interface AdminVerificationDetail extends AdminVerificationRequest {
  documents?: VerificationDocument[];
}

/** Admin-facing review with populated relations */
export interface AdminReview extends Review {
  reviewer?: User;
  moderator?: Admin | null;
}

/* ---- Standardized client error ------------------------------------------- */
export interface ApiError {
  status: number;
  message: string;
  /** Laravel 422 field errors, if present. */
  errors?: Record<string, string[]>;
}
