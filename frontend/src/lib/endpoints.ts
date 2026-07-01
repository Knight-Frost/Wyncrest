/**
 * Typed API surface. Each function encodes one endpoint's request + response
 * shape (including the backend's inconsistent wrapping) so components stay clean.
 *
 * Client routing:
 *   http              — unauthenticated (login, register, public listings)
 *   portalHttp.tenant / .landlord / .admin — portal-scoped authenticated requests
 *   activePortalClient() — for cross-role endpoints (notifications) that always
 *                          run in the context of whichever portal is active in
 *                          this tab; resolved at call time via sessionStorage.
 */
import { http, portalHttp } from './api';
import { type Portal, getActivePortal } from './storage';
import type {
  AccessRolesMatrix,
  AccessSummary,
  Admin,
  AdminCapability,
  AdminDashboard,
  AdminNotificationDeliveriesResponse,
  AdminReview,
  AdminTeamMember,
  AdminUserDetail,
  AdminUserSummary,
  AdminVerificationDetail,
  AdminVerificationRequest,
  AppNotification,
  Application,
  AuditLog,
  AuditLogDetail,
  AuditSummary,
  AuditVerify,
  AuthResponse,
  Contract,
  ConversationDetail,
  ConversationMessage,
  ConversationSummary,
  Feature,
  LandlordDashboard,
  LandlordOnboarding,
  LandlordReviewsResponse,
  LedgerEntry,
  Listing,
  MaintenanceCategory,
  MaintenancePriority,
  MaintenanceRequest,
  MaintenanceStatus,
  MediaAsset,
  MessageableRecipient,
  Paginated,
  Property,
  Review,
  ReviewEligibility,
  ReviewStatus,
  TenantDashboard,
  TenantDocument,
  TenantProfileResponse,
  Unit,
  User,
  UserType,
  VerificationStatus,
  VerificationStatusResponse,
  VerificationSubmitResponse,
  WeatherData,
} from './types';

/** Shape returned by the scoped analytics endpoints. */
export interface AnalyticsResponse {
  analytics: Record<string, unknown>;
  scoped_to: string;
}

/** Returns the active portal's axios instance for endpoints usable by any role. */
function activePortalClient() {
  const p = getActivePortal();
  return p ? portalHttp[p] : http;
}

/**
 * The browser's IANA timezone (e.g. "America/New_York"), sent to endpoints that
 * filter by calendar day so the server resolves day boundaries on the user's
 * clock rather than UTC. Falls back to UTC if unavailable.
 */
function clientTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  } catch {
    return 'UTC';
  }
}

/** Response from GET /auth/providers */
export interface AuthProviders {
  google: boolean;
}

/* ============================ Auth ====================================== */
export const authApi = {
  async login(email: string, password: string): Promise<AuthResponse> {
    const { data } = await http.post<AuthResponse>('/login', { email, password });
    return data;
  },
  async register(payload: {
    email: string;
    password: string;
    password_confirmation: string;
    first_name: string;
    last_name: string;
    phone?: string;
    user_type: UserType;
  }): Promise<AuthResponse> {
    const { data } = await http.post<AuthResponse>('/register', payload);
    return data;
  },
  async me(portal: Portal): Promise<User | Admin> {
    const { data } = await portalHttp[portal].get<{ user: User | Admin }>('/user');
    return data.user;
  },
  async logout(portal: Portal): Promise<void> {
    await portalHttp[portal].post('/logout');
  },

  /** Returns which social providers are currently configured. */
  async authProviders(): Promise<AuthProviders> {
    const { data } = await http.get<AuthProviders>('/auth/providers');
    return data;
  },

  /**
   * Returns the Google OAuth URL; the caller should do:
   *   window.location.href = url
   */
  async googleRedirect(): Promise<string> {
    const { data } = await http.get<{ url: string }>('/auth/google/redirect');
    return data.url;
  },

  /** Request a password-reset link email. Always resolves (no enumeration). */
  async forgotPassword(email: string): Promise<{ message: string }> {
    const { data } = await http.post<{ message: string }>('/forgot-password', { email });
    return data;
  },

  /** Complete the password reset using the token from the email link. */
  async resetPassword(payload: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  }): Promise<{ message: string }> {
    const { data } = await http.post<{ message: string }>('/reset-password', payload);
    return data;
  },

  /** An invited admin sets their password and activates their account. */
  async acceptAdminInvite(payload: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  }): Promise<{ message: string }> {
    const { data } = await http.post<{ message: string }>('/admin/accept-invite', payload);
    return data;
  },

  /** (Authenticated) Send or resend the email verification link. */
  async sendEmailVerification(portal: Portal): Promise<{ message: string }> {
    const { data } = await portalHttp[portal].post<{ message: string }>(
      '/email/verification-notification',
    );
    return data;
  },

  /** Verify an email address using the params from the signed link. */
  async verifyEmail(payload: {
    id: string;
    hash: string;
    signature: string;
    expires: string;
  }): Promise<{ message: string }> {
    const { data } = await http.post<{ message: string }>('/email/verify', payload);
    return data;
  },

  /**
   * (Authenticated) Change the current account's password. Works for any role
   * (admin/landlord/tenant) — routed through the active portal's client.
   * Succeeding revokes all OTHER sessions (the current one is kept), reported
   * back as `revoked_other_sessions`. 422 returns field errors keyed
   * `current_password` and/or `password`.
   */
  async changePassword(
    portal: Portal,
    currentPassword: string,
    password: string,
    passwordConfirmation: string,
  ): Promise<{ message: string; revoked_other_sessions: number }> {
    const { data } = await portalHttp[portal].post<{
      message: string;
      revoked_other_sessions: number;
    }>('/user/password', {
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    });
    return data;
  },
};

/* ======================= Public listings ================================ */
export const publicApi = {
  async listings(params?: {
    city?: string;
    state?: string;
    min_price?: number;
    max_price?: number;
    bedrooms?: number;
    page?: number;
  }): Promise<Paginated<Listing>> {
    const { data } = await http.get<Paginated<Listing>>('/listings', { params });
    return data;
  },
  async featured(): Promise<Listing[]> {
    const { data } = await http.get<Listing[]>('/listings/featured');
    return data;
  },
  async show(id: number): Promise<Listing> {
    const { data } = await http.get<Listing>(`/listings/${id}`);
    return data;
  },
};

/* ============================ Tenant ==================================== */
export const tenantApi = {
  /** Single source of truth for the tenant dashboard (all real, owner-scoped). */
  async dashboard(): Promise<TenantDashboard> {
    const { data } = await portalHttp.tenant.get<TenantDashboard>('/tenant/dashboard');
    return data;
  },

  /* ---- Profile + readiness ---------------------------------------------- */
  async profile(): Promise<TenantProfileResponse> {
    const { data } = await portalHttp.tenant.get<TenantProfileResponse>('/tenant/profile');
    return data;
  },
  async updateProfile(payload: {
    first_name?: string;
    last_name?: string;
    phone?: string | null;
    date_of_birth?: string | null;
    city?: string | null;
    next_of_kin_name?: string | null;
    next_of_kin_phone?: string | null;
    next_of_kin_relationship?: string | null;
  }): Promise<TenantProfileResponse> {
    const { data } = await portalHttp.tenant.patch<TenantProfileResponse>(
      '/tenant/profile',
      payload,
    );
    return data;
  },

  /* ---- Applications ------------------------------------------------------ */
  async applications(): Promise<Application[]> {
    const { data } = await portalHttp.tenant.get<Application[]>('/tenant/applications');
    return data;
  },
  async application(id: number): Promise<Application> {
    const { data } = await portalHttp.tenant.get<Application>(`/tenant/applications/${id}`);
    return data;
  },
  async apply(listingId: number, coverNote?: string): Promise<Application> {
    const { data } = await portalHttp.tenant.post<Application>('/tenant/applications', {
      listing_id: listingId,
      cover_note: coverNote ?? null,
    });
    return data;
  },
  async withdrawApplication(id: number): Promise<Application> {
    const { data } = await portalHttp.tenant.post<Application>(
      `/tenant/applications/${id}/withdraw`,
    );
    return data;
  },

  /* ---- Maintenance ------------------------------------------------------- */
  async maintenance(): Promise<MaintenanceRequest[]> {
    const { data } = await portalHttp.tenant.get<MaintenanceRequest[]>('/tenant/maintenance');
    return data;
  },
  async maintenanceRequest(id: number): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.tenant.get<MaintenanceRequest>(`/tenant/maintenance/${id}`);
    return data;
  },
  async createMaintenance(payload: {
    contract_id: string;
    title: string;
    description: string;
    category: MaintenanceCategory;
    priority: MaintenancePriority;
  }): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.tenant.post<MaintenanceRequest>(
      '/tenant/maintenance',
      payload,
    );
    return data;
  },
  async cancelMaintenance(id: number): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.tenant.post<MaintenanceRequest>(
      `/tenant/maintenance/${id}/cancel`,
    );
    return data;
  },

  /* ---- Documents (private storage; authorized download) ------------------ */
  async documents(): Promise<TenantDocument[]> {
    const { data } = await portalHttp.tenant.get<TenantDocument[]>('/tenant/documents');
    return data;
  },
  async uploadDocument(file: File, documentType: string): Promise<TenantDocument> {
    const form = new FormData();
    form.append('file', file);
    form.append('document_type', documentType);
    const { data } = await portalHttp.tenant.post<TenantDocument>('/tenant/documents', form);
    return data;
  },
  /** Streams the file via an authorized endpoint (Bearer token), returns a Blob. */
  async downloadDocument(id: number): Promise<Blob> {
    const { data } = await portalHttp.tenant.get(`/tenant/documents/${id}/download`, {
      responseType: 'blob',
    });
    return data as Blob;
  },
  async deleteDocument(id: number): Promise<void> {
    await portalHttp.tenant.delete(`/tenant/documents/${id}`);
  },

  /* ---- Messaging --------------------------------------------------------- */
  async messageableRecipients(q?: string): Promise<MessageableRecipient[]> {
    const { data } = await portalHttp.tenant.get<MessageableRecipient[]>(
      '/tenant/messageable-recipients',
      { params: q ? { q } : undefined },
    );
    return data;
  },
  async conversations(): Promise<ConversationSummary[]> {
    const { data } = await portalHttp.tenant.get<ConversationSummary[]>('/tenant/conversations');
    return data;
  },
  async conversation(id: number): Promise<ConversationDetail> {
    const { data } = await portalHttp.tenant.get<ConversationDetail>(`/tenant/conversations/${id}`);
    return data;
  },
  async startConversation(
    listingId: number,
    body: string,
  ): Promise<{ conversation: ConversationDetail['conversation'] & { messages: ConversationMessage[] } }> {
    const { data } = await portalHttp.tenant.post('/tenant/conversations', {
      listing_id: listingId,
      body,
    });
    return data;
  },
  async sendMessage(
    conversationId: number,
    body: string,
    files?: File[],
  ): Promise<{ message: ConversationMessage }> {
    if (files && files.length > 0) {
      const form = new FormData();
      if (body) form.append('body', body);
      files.forEach((f) => form.append('attachments[]', f));
      const { data } = await portalHttp.tenant.post(
        `/tenant/conversations/${conversationId}/messages`,
        form,
      );
      return data;
    }
    const { data } = await portalHttp.tenant.post(
      `/tenant/conversations/${conversationId}/messages`,
      { body },
    );
    return data;
  },
  /** Fetches a message attachment as a blob via Bearer-auth and returns an object URL. */
  async messageAttachmentBlob(id: number): Promise<string> {
    const { data } = await portalHttp.tenant.get(
      `/tenant/messages/attachments/${id}`,
      { responseType: 'blob' },
    );
    return URL.createObjectURL(data as Blob);
  },
  async savedListings(): Promise<Listing[]> {
    const { data } = await portalHttp.tenant.get<Listing[]>('/tenant/saved-listings');
    return data;
  },
  async saveListing(listingId: number): Promise<void> {
    await portalHttp.tenant.post(`/tenant/listings/${listingId}/save`);
  },
  async unsaveListing(listingId: number): Promise<void> {
    await portalHttp.tenant.delete(`/tenant/listings/${listingId}/save`);
  },
  async contracts(): Promise<Contract[]> {
    const { data } = await portalHttp.tenant.get<Contract[]>('/tenant/contracts');
    return data;
  },
  async contract(id: string): Promise<Contract> {
    const { data } = await portalHttp.tenant.get<Contract>(`/tenant/contracts/${id}`);
    return data;
  },
  async acceptContract(id: string): Promise<Contract> {
    const { data } = await portalHttp.tenant.post<{ contract: Contract }>(
      `/tenant/contracts/${id}/accept`,
    );
    return data.contract;
  },
  async terminateContract(id: string, reason: string): Promise<void> {
    await portalHttp.tenant.post(`/tenant/contracts/${id}/terminate`, {
      termination_reason: reason,
    });
  },
  async ledger(): Promise<LedgerEntry[]> {
    const { data } = await portalHttp.tenant.get<LedgerEntry[]>('/tenant/ledger');
    return data;
  },

  /* ---- Verification ------------------------------------------------------ */
  async verificationStatus(): Promise<VerificationStatusResponse> {
    const { data } = await portalHttp.tenant.get<VerificationStatusResponse>(
      '/tenant/verification',
    );
    return data;
  },
  async submitVerification(note?: string): Promise<VerificationSubmitResponse> {
    const { data } = await portalHttp.tenant.post<VerificationSubmitResponse>(
      '/tenant/verification/submit',
      { note: note ?? null },
    );
    return data;
  },

  /* ---- Reviews ----------------------------------------------------------- */
  async reviews(): Promise<Review[]> {
    const { data } = await portalHttp.tenant.get<Review[]>('/tenant/reviews');
    return data;
  },
  async reviewEligibility(listingId: number): Promise<ReviewEligibility> {
    const { data } = await portalHttp.tenant.get<ReviewEligibility>(
      `/tenant/listings/${listingId}/review-eligibility`,
    );
    return data;
  },
  async createReview(payload: {
    contract_id: string;
    rating: number;
    title?: string;
    body: string;
  }): Promise<Review> {
    const { data } = await portalHttp.tenant.post<Review>('/tenant/reviews', payload);
    return data;
  },
  async updateReview(
    id: number,
    payload: { rating?: number; title?: string; body?: string },
  ): Promise<Review> {
    const { data } = await portalHttp.tenant.patch<Review>(`/tenant/reviews/${id}`, payload);
    return data;
  },

  /* ---- Avatar ------------------------------------------------------------ */
  async uploadAvatar(file: File): Promise<MediaAsset> {
    const form = new FormData();
    form.append('file', file);
    const { data } = await portalHttp.tenant.post<MediaAsset>('/tenant/avatar', form);
    return data;
  },

  async balance(): Promise<{ balance_cents: number; balance_dollars: number }> {
    const { data } = await portalHttp.tenant.get('/tenant/payments/balance');
    return data;
  },
  async initiatePayment(
    ledgerEntryId: string,
  ): Promise<{ client_secret: string; payment_intent_id: string }> {
    const { data } = await portalHttp.tenant.post(
      `/tenant/payments/initiate/${ledgerEntryId}`,
    );
    return data;
  },
};

/* =========================== Landlord =================================== */
export const landlordApi = {
  /** Single source of truth for the landlord dashboard (all real, owner-scoped). */
  async dashboard(): Promise<LandlordDashboard> {
    const { data } = await portalHttp.landlord.get<LandlordDashboard>('/landlord/dashboard');
    return data;
  },
  async onboarding(): Promise<LandlordOnboarding> {
    const { data } = await portalHttp.landlord.get<LandlordOnboarding>('/landlord/onboarding');
    return data;
  },

  /* ---- Applications (decide on tenant applications) ---------------------- */
  async applications(): Promise<Application[]> {
    const { data } = await portalHttp.landlord.get<Application[]>('/landlord/applications');
    return data;
  },
  async application(id: number): Promise<Application> {
    const { data } = await portalHttp.landlord.get<Application>(`/landlord/applications/${id}`);
    return data;
  },
  async decideApplication(
    id: number,
    decision: 'approved' | 'rejected',
    reason?: string,
  ): Promise<Application> {
    const { data } = await portalHttp.landlord.post<Application>(
      `/landlord/applications/${id}/decide`,
      { decision, decision_reason: reason ?? null },
    );
    return data;
  },

  /* ---- Maintenance (manage requests on owned properties) ---------------- */
  async maintenance(): Promise<MaintenanceRequest[]> {
    const { data } = await portalHttp.landlord.get<MaintenanceRequest[]>('/landlord/maintenance');
    return data;
  },
  async updateMaintenanceStatus(
    id: number,
    status: MaintenanceStatus,
    resolutionNotes?: string,
  ): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.landlord.patch<MaintenanceRequest>(
      `/landlord/maintenance/${id}/status`,
      { status, resolution_notes: resolutionNotes ?? null },
    );
    return data;
  },

  /* ---- Analytics (scoped to the landlord's portfolio) ------------------- */
  async analyticsFinancial(params?: {
    start_date?: string;
    end_date?: string;
    property_id?: number;
  }): Promise<AnalyticsResponse> {
    const { data } = await portalHttp.landlord.get<AnalyticsResponse>(
      '/landlord/analytics/financial',
      { params },
    );
    return data;
  },
  async analyticsContracts(params?: {
    start_date?: string;
    end_date?: string;
    property_id?: number;
  }): Promise<AnalyticsResponse> {
    const { data } = await portalHttp.landlord.get<AnalyticsResponse>(
      '/landlord/analytics/contracts',
      { params },
    );
    return data;
  },
  async properties(): Promise<Property[]> {
    const { data } = await portalHttp.landlord.get<Property[]>('/landlord/properties');
    return data;
  },
  async property(id: number): Promise<Property> {
    const { data } = await portalHttp.landlord.get<Property>(`/landlord/properties/${id}`);
    return data;
  },
  async createProperty(payload: Partial<Property>): Promise<Property> {
    const { data } = await portalHttp.landlord.post<{ property: Property }>(
      '/landlord/properties',
      payload,
    );
    return data.property ?? (data as unknown as Property);
  },
  async updateProperty(id: number, payload: Partial<Property>): Promise<Property> {
    const { data } = await portalHttp.landlord.put<{ property: Property }>(
      `/landlord/properties/${id}`,
      payload,
    );
    return data.property ?? (data as unknown as Property);
  },
  async deleteProperty(id: number): Promise<void> {
    await portalHttp.landlord.delete(`/landlord/properties/${id}`);
  },
  async units(): Promise<Unit[]> {
    const { data } = await portalHttp.landlord.get<Unit[]>('/landlord/units');
    return data;
  },
  /** Single unit detail — includes `media_assets` (gallery) ordered by sort_order. */
  async unit(id: number): Promise<Unit> {
    const { data } = await portalHttp.landlord.get<Unit>(`/landlord/units/${id}`);
    return data;
  },
  async createUnit(propertyId: number, payload: Partial<Unit>): Promise<Unit> {
    const { data } = await portalHttp.landlord.post<{ unit: Unit }>(
      `/landlord/properties/${propertyId}/units`,
      payload,
    );
    return data.unit ?? (data as unknown as Unit);
  },
  async updateUnit(id: number, payload: Partial<Unit>): Promise<Unit> {
    const { data } = await portalHttp.landlord.put<{ unit: Unit }>(
      `/landlord/units/${id}`,
      payload,
    );
    return data.unit ?? (data as unknown as Unit);
  },
  async deleteUnit(id: number): Promise<void> {
    await portalHttp.landlord.delete(`/landlord/units/${id}`);
  },
  async listings(): Promise<Listing[]> {
    const { data } = await portalHttp.landlord.get<Listing[]>('/landlord/listings');
    return data;
  },
  /** Single listing detail — includes `media_assets` (gallery) ordered by sort_order. */
  async listing(id: number): Promise<Listing> {
    const { data } = await portalHttp.landlord.get<Listing>(`/landlord/listings/${id}`);
    return data;
  },
  async createListing(unitId: number, payload: Partial<Listing>): Promise<Listing> {
    const { data } = await portalHttp.landlord.post<{ listing: Listing }>(
      `/landlord/units/${unitId}/listings`,
      payload,
    );
    return data.listing ?? (data as unknown as Listing);
  },
  async updateListing(id: number, payload: Partial<Listing>): Promise<Listing> {
    const { data } = await portalHttp.landlord.put<{ listing: Listing }>(
      `/landlord/listings/${id}`,
      payload,
    );
    return data.listing ?? (data as unknown as Listing);
  },
  async submitListing(id: number): Promise<void> {
    await portalHttp.landlord.post(`/landlord/listings/${id}/submit`);
  },
  async deleteListing(id: number): Promise<void> {
    await portalHttp.landlord.delete(`/landlord/listings/${id}`);
  },
  async contracts(): Promise<Contract[]> {
    const { data } = await portalHttp.landlord.get<Contract[]>('/landlord/contracts');
    return data;
  },
  async createContract(payload: {
    listing_id: number;
    // The landlord identifies the tenant by email; the API resolves it to a
    // real tenant id server-side (StoreContractRequest::prepareForValidation).
    tenant_email: string;
    rent_amount: number;
    payment_day: number;
    start_date: string;
    // Optional at the backend (`end_date` is nullable). Omit for an open-ended
    // lease rather than sending an empty string.
    end_date?: string;
  }): Promise<Contract> {
    const { data } = await portalHttp.landlord.post<{ contract: Contract }>(
      '/landlord/contracts',
      payload,
    );
    return data.contract ?? (data as unknown as Contract);
  },
  async sendContract(id: string): Promise<void> {
    await portalHttp.landlord.post(`/landlord/contracts/${id}/send`);
  },
  async terminateContract(id: string, reason: string): Promise<void> {
    await portalHttp.landlord.post(`/landlord/contracts/${id}/terminate`, {
      termination_reason: reason,
    });
  },
  async ledger(): Promise<LedgerEntry[]> {
    const { data } = await portalHttp.landlord.get<LedgerEntry[]>('/landlord/ledger');
    return data;
  },

  /* ---- CSV exports (real server-generated, owner-scoped) ---------------- */
  async exportLedger(): Promise<void> {
    await downloadCsv(portalHttp.landlord, '/landlord/ledger/export', 'rent-ledger.csv');
  },
  async exportListings(): Promise<void> {
    await downloadCsv(portalHttp.landlord, '/landlord/listings/export', 'listings.csv');
  },
  async exportApplications(): Promise<void> {
    await downloadCsv(portalHttp.landlord, '/landlord/applications/export', 'applicants.csv');
  },

  /* ---- Media: property / unit / listing galleries ----------------------- */
  /**
   * Upload an image to a property's gallery.
   * Accepts multipart/form-data; optional alt_text and caption.
   * Returns the created MediaAsset (id is a UUID string).
   */
  async uploadPropertyMedia(
    propertyId: number,
    file: File,
    meta?: { alt_text?: string; caption?: string },
  ): Promise<MediaAsset> {
    const form = new FormData();
    form.append('file', file);
    if (meta?.alt_text) form.append('alt_text', meta.alt_text);
    if (meta?.caption) form.append('caption', meta.caption);
    const { data } = await portalHttp.landlord.post<MediaAsset>(
      `/landlord/properties/${propertyId}/media`,
      form,
    );
    return data;
  },
  async uploadUnitMedia(
    unitId: number,
    file: File,
    meta?: { alt_text?: string; caption?: string },
  ): Promise<MediaAsset> {
    const form = new FormData();
    form.append('file', file);
    if (meta?.alt_text) form.append('alt_text', meta.alt_text);
    if (meta?.caption) form.append('caption', meta.caption);
    const { data } = await portalHttp.landlord.post<MediaAsset>(
      `/landlord/units/${unitId}/media`,
      form,
    );
    return data;
  },
  async uploadListingMedia(
    listingId: number,
    file: File,
    meta?: { alt_text?: string; caption?: string },
  ): Promise<MediaAsset> {
    const form = new FormData();
    form.append('file', file);
    if (meta?.alt_text) form.append('alt_text', meta.alt_text);
    if (meta?.caption) form.append('caption', meta.caption);
    const { data } = await portalHttp.landlord.post<MediaAsset>(
      `/landlord/listings/${listingId}/media`,
      form,
    );
    return data;
  },
  /** Reorder media assets by sending an ordered array of UUIDs. */
  async reorderMedia(ids: string[]): Promise<{ message: string }> {
    const { data } = await portalHttp.landlord.patch<{ message: string }>(
      '/landlord/media/reorder',
      { ids },
    );
    return data;
  },
  /** Soft-delete a media asset by UUID. */
  async deleteMedia(id: string): Promise<{ message: string }> {
    const { data } = await portalHttp.landlord.delete<{ message: string }>(
      `/landlord/media/${id}`,
    );
    return data;
  },

  /* ---- Verification (landlord identity, same lifecycle as tenant) -------- */
  async verificationStatus(): Promise<VerificationStatusResponse> {
    const { data } = await portalHttp.landlord.get<VerificationStatusResponse>(
      '/landlord/verification',
    );
    return data;
  },
  async submitVerification(note?: string): Promise<VerificationSubmitResponse> {
    const { data } = await portalHttp.landlord.post<VerificationSubmitResponse>(
      '/landlord/verification/submit',
      { note: note ?? null },
    );
    return data;
  },

  /* ---- Reviews (read approved reviews on own properties + respond) ------- */
  async reviews(): Promise<LandlordReviewsResponse> {
    const { data } = await portalHttp.landlord.get<LandlordReviewsResponse>('/landlord/reviews');
    return data;
  },
  async respondToReview(reviewId: number, response: string): Promise<Review> {
    const { data } = await portalHttp.landlord.post<Review>(
      `/landlord/reviews/${reviewId}/respond`,
      { response },
    );
    return data;
  },

  /* ---- Documents (private storage; mirrors the tenant document endpoints) - */
  async documents(): Promise<TenantDocument[]> {
    const { data } = await portalHttp.landlord.get<TenantDocument[]>('/landlord/documents');
    return data;
  },
  async uploadDocument(file: File, documentType: string): Promise<TenantDocument> {
    const form = new FormData();
    form.append('file', file);
    form.append('document_type', documentType);
    const { data } = await portalHttp.landlord.post<TenantDocument>('/landlord/documents', form);
    return data;
  },
  /** Streams the file via an authorized endpoint (Bearer token), returns a Blob. */
  async downloadDocument(id: number): Promise<Blob> {
    const { data } = await portalHttp.landlord.get(`/landlord/documents/${id}/download`, {
      responseType: 'blob',
    });
    return data as Blob;
  },
  async deleteDocument(id: number): Promise<void> {
    await portalHttp.landlord.delete(`/landlord/documents/${id}`);
  },
};

/**
 * Stream a server-generated CSV to a browser download. Keeps the truth rule:
 * the file is produced by the API (owner-scoped, policy-safe), not fabricated
 * in the client. Falls back to the server filename when present.
 */
async function downloadCsv(
  client: (typeof portalHttp)['landlord'] | (typeof portalHttp)['admin'],
  url: string,
  fallbackName: string,
  params?: Record<string, unknown>,
): Promise<void> {
  const res = await client.get(url, { responseType: 'blob', params });
  const disposition = String(res.headers['content-disposition'] ?? '');
  const match = disposition.match(/filename="?([^"]+)"?/i);
  const filename = match?.[1] ?? fallbackName;

  const blobUrl = URL.createObjectURL(res.data as Blob);
  const a = document.createElement('a');
  a.href = blobUrl;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(blobUrl);
}

/* ============================= Admin ==================================== */
export const adminApi = {
  async dashboard(): Promise<AdminDashboard> {
    const { data } = await portalHttp.admin.get<AdminDashboard>('/admin/dashboard');
    return data;
  },

  /* ---- User management -------------------------------------------------- */
  async users(params?: {
    type?: UserType;
    status?: 'active' | 'suspended';
    search?: string;
    page?: number;
  }): Promise<Paginated<AdminUserSummary>> {
    const { data } = await portalHttp.admin.get<Paginated<AdminUserSummary>>('/admin/users', {
      params,
    });
    return data;
  },
  async user(id: number): Promise<AdminUserDetail> {
    const { data } = await portalHttp.admin.get<AdminUserDetail>(`/admin/users/${id}`);
    return data;
  },
  async suspendUser(id: number, reason: string): Promise<{ message: string; user: AdminUserSummary }> {
    const { data } = await portalHttp.admin.post(`/admin/users/${id}/suspend`, { reason });
    return data;
  },
  async activateUser(id: number): Promise<{ message: string; user: AdminUserSummary }> {
    const { data } = await portalHttp.admin.post(`/admin/users/${id}/activate`);
    return data;
  },
  async blockUser(id: number, reason: string): Promise<{ message: string; user: AdminUserSummary }> {
    const { data } = await portalHttp.admin.post(`/admin/users/${id}/block`, { reason });
    return data;
  },
  async archiveUser(id: number, reason: string): Promise<{ message: string; user: AdminUserSummary }> {
    const { data } = await portalHttp.admin.post(`/admin/users/${id}/archive`, { reason });
    return data;
  },
  async pendingListings(): Promise<Listing[]> {
    const { data } = await portalHttp.admin.get<Listing[]>('/admin/listings/pending');
    return data;
  },
  async approveListing(id: number): Promise<void> {
    await portalHttp.admin.post(`/admin/listings/${id}/approve`);
  },
  async rejectListing(id: number, reason: string): Promise<void> {
    await portalHttp.admin.post(`/admin/listings/${id}/reject`, { rejection_reason: reason });
  },
  async auditLogs(params?: {
    severity?: 'info' | 'warning' | 'critical';
    area?: string;
    actor_role?: 'admin' | 'landlord' | 'tenant' | 'user' | 'system';
    from_date?: string;
    to_date?: string;
    search?: string;
    sort?: 'newest' | 'oldest';
    page?: number;
    per_page?: number;
  }): Promise<Paginated<AuditLog>> {
    // Send the client timezone so calendar-day filters (e.g. "Last 7 days")
    // resolve on the admin's own clock — keeping the list consistent with the
    // summary counts (backend: AuditLogService::dateBoundsUtc).
    const { data } = await portalHttp.admin.get<Paginated<AuditLog>>('/admin/audit-logs', {
      params: { ...params, tz: clientTimezone() },
    });
    return data;
  },
  async auditLog(id: number): Promise<AuditLogDetail> {
    const { data } = await portalHttp.admin.get<AuditLogDetail>(`/admin/audit-logs/${id}`);
    return data;
  },
  async auditSummary(): Promise<AuditSummary> {
    const { data } = await portalHttp.admin.get<AuditSummary>('/admin/audit-logs/summary', {
      params: { tz: clientTimezone() },
    });
    return data;
  },
  /** Recompute + verify the SHA-256 audit hash chain (whole table). */
  async auditVerify(): Promise<AuditVerify> {
    const { data } = await portalHttp.admin.get<AuditVerify>('/admin/audit-logs/verify');
    return data;
  },
  async auditExport(params?: {
    severity?: 'info' | 'warning' | 'critical';
    area?: string;
    actor_role?: 'admin' | 'landlord' | 'tenant' | 'user' | 'system';
    from_date?: string;
    to_date?: string;
    search?: string;
    sort?: 'newest' | 'oldest';
  }): Promise<void> {
    await downloadCsv(portalHttp.admin, '/admin/audit-logs/export', 'audit-logs.csv', {
      ...params,
      tz: clientTimezone(),
    });
  },
  async contracts(params?: { page?: number }): Promise<Paginated<Contract>> {
    const { data } = await portalHttp.admin.get<Paginated<Contract>>('/admin/contracts', {
      params,
    });
    return data;
  },
  async ledger(params?: { page?: number }): Promise<Paginated<LedgerEntry>> {
    const { data } = await portalHttp.admin.get<Paginated<LedgerEntry>>('/admin/ledger', {
      params,
    });
    return data;
  },
  async landlordFeatures(landlordId: number): Promise<Feature[]> {
    const { data } = await portalHttp.admin.get<Feature[]>(
      `/admin/landlords/${landlordId}/features`,
    );
    return data;
  },

  /**
   * Platform-wide notification delivery monitor (email/SMS outcomes across all
   * users). Admin-only. All filters optional; paginated via `meta`.
   */
  async deliveries(params?: {
    channel?: 'email' | 'sms';
    status?: 'delivered' | 'failed';
    type?: string;
    search?: string;
    from?: string;
    to?: string;
    page?: number;
    per_page?: number;
  }): Promise<AdminNotificationDeliveriesResponse> {
    const { data } = await portalHttp.admin.get<AdminNotificationDeliveriesResponse>(
      '/admin/notifications/deliveries',
      { params },
    );
    return data;
  },

  /* ---- Verification moderation ------------------------------------------ */
  async verifications(params?: {
    status?: VerificationStatus;
    page?: number;
  }): Promise<Paginated<AdminVerificationRequest>> {
    const { data } = await portalHttp.admin.get<Paginated<AdminVerificationRequest>>(
      '/admin/verifications',
      { params },
    );
    return data;
  },
  async verification(id: string): Promise<AdminVerificationDetail> {
    const { data } = await portalHttp.admin.get<AdminVerificationDetail>(
      `/admin/verifications/${id}`,
    );
    return data;
  },
  async approveVerification(
    id: string,
    reason?: string,
  ): Promise<{ message: string; verification_request: AdminVerificationRequest }> {
    const { data } = await portalHttp.admin.post(
      `/admin/verifications/${id}/approve`,
      { reason: reason ?? null },
    );
    return data;
  },
  async rejectVerification(
    id: string,
    reason: string,
  ): Promise<{ message: string; verification_request: AdminVerificationRequest }> {
    const { data } = await portalHttp.admin.post(
      `/admin/verifications/${id}/reject`,
      { reason },
    );
    return data;
  },
  async requestInfoVerification(
    id: string,
    note: string,
  ): Promise<{ message: string; verification_request: AdminVerificationRequest }> {
    const { data } = await portalHttp.admin.post(
      `/admin/verifications/${id}/request-info`,
      { note },
    );
    return data;
  },
  /**
   * Stream an applicant's verification document via the admin-gated, audited
   * endpoint and trigger a browser download. The file is produced by the API
   * (policy-safe, every access logged) — never fabricated client-side.
   */
  async downloadDocument(id: number, fallbackName = 'document'): Promise<void> {
    const res = await portalHttp.admin.get(`/admin/documents/${id}/download`, {
      responseType: 'blob',
    });
    const disposition = String(res.headers['content-disposition'] ?? '');
    const match = disposition.match(/filename="?([^"]+)"?/i);
    const filename = match?.[1] ?? fallbackName;

    const blobUrl = URL.createObjectURL(res.data as Blob);
    const a = document.createElement('a');
    a.href = blobUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(blobUrl);
  },

  /* ---- Review moderation ------------------------------------------------- */
  async adminReviews(params?: {
    status?: ReviewStatus;
    property_id?: number;
    page?: number;
  }): Promise<Paginated<AdminReview>> {
    const { data } = await portalHttp.admin.get<Paginated<AdminReview>>(
      '/admin/reviews',
      { params },
    );
    return data;
  },
  async moderateReview(
    id: number,
    action: 'approve' | 'reject' | 'hide' | 'flag',
    reason?: string,
  ): Promise<AdminReview> {
    const { data } = await portalHttp.admin.post<AdminReview>(
      `/admin/reviews/${id}/moderate`,
      { action, reason: reason ?? null },
    );
    return data;
  },

  /* ---- Access control (Manage Users & Permissions) --------------------- */
  async accessSummary(): Promise<AccessSummary> {
    const { data } = await portalHttp.admin.get<AccessSummary>('/admin/access/summary');
    return data;
  },
  async accessRoles(): Promise<AccessRolesMatrix> {
    const { data } = await portalHttp.admin.get<AccessRolesMatrix>('/admin/access/roles');
    return data;
  },
  async accessMembers(params?: {
    type?: UserType;
    status?: 'active' | 'suspended' | 'blocked' | 'archived';
    search?: string;
    page?: number;
  }): Promise<Paginated<AdminUserSummary>> {
    const { data } = await portalHttp.admin.get<Paginated<AdminUserSummary>>(
      '/admin/access/members',
      { params },
    );
    return data;
  },
  async accessTeam(): Promise<AdminTeamMember[]> {
    const { data } = await portalHttp.admin.get<{ data: AdminTeamMember[] }>('/admin/access/admins');
    return data.data;
  },
  async inviteAdmin(payload: {
    email: string;
    name?: string;
    capabilities?: AdminCapability[];
    is_super_admin?: boolean;
    note?: string;
  }): Promise<{ message: string; admin: AdminTeamMember }> {
    const { data } = await portalHttp.admin.post('/admin/access/admins', payload);
    return data;
  },
  async resendAdminInvite(id: number): Promise<{ message: string }> {
    const { data } = await portalHttp.admin.post(`/admin/access/admins/${id}/resend-invite`);
    return data;
  },
  async revokeAdminInvite(id: number, reason: string): Promise<{ message: string }> {
    const { data } = await portalHttp.admin.post(`/admin/access/admins/${id}/revoke-invite`, { reason });
    return data;
  },
  async updateAdminCapabilities(
    id: number,
    capabilities: AdminCapability[],
    reason: string,
  ): Promise<{ message: string; admin: AdminTeamMember }> {
    const { data } = await portalHttp.admin.patch(`/admin/access/admins/${id}/capabilities`, {
      capabilities,
      reason,
    });
    return data;
  },
  async promoteAdminToSuper(id: number, reason: string): Promise<{ message: string; admin: AdminTeamMember }> {
    const { data } = await portalHttp.admin.post(`/admin/access/admins/${id}/promote-super`, { reason });
    return data;
  },
  async demoteAdminFromSuper(
    id: number,
    reason: string,
    capabilities: AdminCapability[] = [],
  ): Promise<{ message: string; admin: AdminTeamMember }> {
    const { data } = await portalHttp.admin.post(`/admin/access/admins/${id}/demote-super`, {
      reason,
      capabilities,
    });
    return data;
  },
  async deactivateAdmin(id: number, reason: string): Promise<{ message: string; admin: AdminTeamMember }> {
    const { data } = await portalHttp.admin.post(`/admin/access/admins/${id}/deactivate`, { reason });
    return data;
  },
  async activateAdmin(id: number): Promise<{ message: string; admin: AdminTeamMember }> {
    const { data } = await portalHttp.admin.post(`/admin/access/admins/${id}/activate`);
    return data;
  },
};

/* ========================= Weather ====================================== */
export const weatherApi = {
  /** Requires auth (any role). Returns a WeatherData payload. */
  async current(city = 'Accra'): Promise<WeatherData> {
    const { data } = await activePortalClient().get<WeatherData>('/weather', { params: { city } });
    return data;
  },
};

/* ========================= Notifications ================================ */
export const notificationApi = {
  async list(params?: { page?: number }): Promise<Paginated<AppNotification>> {
    const { data } = await activePortalClient().get<Paginated<AppNotification>>('/notifications', {
      params,
    });
    return data;
  },
  async unread(): Promise<AppNotification[]> {
    const { data } = await activePortalClient().get<AppNotification[]>('/notifications/unread');
    return data;
  },
  async unreadCount(): Promise<number> {
    const { data } = await activePortalClient().get<{ count: number }>(
      '/notifications/unread-count',
    );
    return data.count ?? 0;
  },
  async markRead(id: string): Promise<void> {
    await activePortalClient().patch(`/notifications/${id}/read`);
  },
  async markAllRead(): Promise<void> {
    await activePortalClient().post('/notifications/mark-all-read');
  },
  /** Per-notification-type email/SMS delivery preferences (real backend). */
  async getPreferences(): Promise<Record<string, { email: boolean; sms: boolean }>> {
    const { data } = await activePortalClient().get<Record<string, { email: boolean; sms: boolean }>>(
      '/notification-preferences',
    );
    return data;
  },
  async updatePreferences(
    prefs: Record<string, { email: boolean; sms: boolean }>,
  ): Promise<void> {
    await activePortalClient().put('/notification-preferences', prefs);
  },
};
