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
import { ensureAdminCsrf, http, portalHttp } from './api';
import { type Portal, getActivePortal } from './storage';
import type {
  AccessRolesMatrix,
  AccessSummary,
  Admin,
  AdminCapability,
  AdminAnalyticsResponse,
  AdminDashboard,
  PlatformAnalyticsResponse,
  AdminMaintenanceCase,
  AdminMaintenanceAnalytics,
  AdminMaintenanceDetail,
  AdminMaintenanceNote,
  AdminMaintenanceOversight,
  AdminNotificationDeliveriesResponse,
  AdminReviewDetail,
  AdminReviewQueueResponse,
  AdminTeamMember,
  AdminUserDetail,
  AdminUserSummary,
  AdminUsersResponse,
  AdminVerificationDetail,
  AdminVerificationRequest,
  AppNotification,
  Application,
  ApplicationFormData,
  ApplicationMessageThread,
  DashboardMaintenanceSummary,
  DocumentType,
  AuditLog,
  AuditLogDetail,
  AuditSummary,
  AuditVerify,
  AuthResponse,
  Contract,
  ContractBillingPeriod,
  ContractCaseFileDetail,
  ContractDocument,
  ContractLandlordNote,
  ContractLedgerResponse,
  ContractMessageThread,
  ContractNote,
  ContractPayment,
  ContractQueue,
  ContractTimelineEvent,
  ConversationDetail,
  ConversationMessage,
  ConversationSummary,
  LandlordDashboard,
  LandlordOnboarding,
  LandlordProfileResponse,
  LandlordReviewsResponse,
  InitiatePaymentResponse,
  LedgerEntry,
  LedgerEntryCaseFile,
  LedgerListResponse,
  LedgerQueryParams,
  LandlordLedgerResponse,
  LandlordLedgerCaseFile,
  LedgerContractStatement,
  LedgerPropertyStatement,
  LedgerReconciliationReport,
  Listing,
  ListingHistoryEntry,
  ListingPreview,
  ListingReviewDetail,
  ListingReviewNote,
  ListingReviewQueue,
  CreateMaintenancePayload,
  MaintenanceAssigneeType,
  MaintenanceCategory,
  MaintenanceMessageThread,
  MaintenancePriority,
  MaintenanceRequest,
  MaintenanceStatus,
  MediaAsset,
  MessageableRecipient,
  Paginated,
  PaginatedLedger,
  PaymentMethod,
  Property,
  PropertyDetailPayload,
  Review,
  ReviewEligibility,
  TenantBalance,
  TenantDashboard,
  TenantDocument,
  TenantProfileResponse,
  Unit,
  User,
  UserType,
  VerificationNote,
  VerificationRequestStatus,
  VerificationStatusResponse,
  VerificationSubmitResponse,
  VerificationSummary,
  WeatherData,
} from './types';

/** Real payload shape — mirrors App\Services\LandlordAnalyticsService::build(). */
export interface PortfolioAnalytics {
  range: {
    key: 'this' | 'last' | '90' | 'ytd';
    label: string;
    from: string;
    to: string;
    prev_from: string;
    prev_to: string;
  };
  property_id: number | null;
  summary: {
    collected_cents: number;
    collected_prev_cents: number;
    expected_cents: number;
    outstanding_cents: number;
    overdue_cents: number;
    occupied_units: number;
    total_units: number;
    occupancy_pct: number;
  };
  financial_trend: Array<{ month: string; collected_cents: number; expected_cents: number }>;
  revenue_by_property: Array<{ property_id: number; name: string; collected_cents: number }>;
  occupancy: {
    trend: Array<{ month: string; occupied: number; total: number; occupancy_pct: number }>;
    unit_status: {
      occupied: number;
      vacant_listed: number;
      vacant_draft: number;
      vacant_unlisted: number;
      total: number;
    };
    vacancy_by_property: Array<{ property_id: number; name: string; vacant: number }>;
  };
  listings: {
    funnel: Array<{ step: string; value: number }>;
    applications_by_listing: Array<{ listing_id: string; label: string; value: number; status: string }>;
    status_breakdown: Array<{ status: string; count: number }>;
  };
  payments: {
    behavior_trend: Array<{ month: string; on_time: number; late: number }>;
    aging: Array<{ bucket: string; amount_cents: number; example: string | null }>;
    overdue_tenants: Array<{
      contract_id: string;
      tenant_name: string | null;
      property_name: string | null;
      unit_number: string | null;
      overdue_cents: number;
      days_overdue: number;
      last_payment_at: string | null;
    }>;
  };
  maintenance: {
    by_status: Array<{ status: string; count: number }>;
    by_category: Array<{ category: string | null; count: number }>;
    resolution_trend: Array<{ month: string; avg_days: number | null }>;
  };
  needs_attention: Array<{
    tone: 'red' | 'amber' | 'blue';
    category: 'overdue_rent' | 'maintenance' | 'vacancy' | 'listing_draft' | 'low_conversion' | 'lease_ending';
    title: string;
    description: string;
    action_label: string;
    action_route: string;
  }>;
  properties: Array<{
    id: number;
    name: string;
    area: string;
    units: number;
    occupied: number;
    occupancy_pct: number;
    collected_cents: number;
    outstanding_cents: number;
    applications_count: number;
    open_maintenance: number;
    status: 'healthy' | 'attention' | 'vacancy';
  }>;
}

/** Returns the active portal's axios instance for endpoints usable by any role. */
function activePortalClient() {
  const p = getActivePortal();
  return p ? portalHttp[p] : http;
}

/**
 * Cross-role endpoints (notifications, preferences, weather) exist twice: the
 * shared bearer routes for tenants/landlords, and isolated `/admin/*` copies
 * for the admin cookie session. Pick the right path for the active portal.
 */
function crossRolePath(path: string): string {
  return getActivePortal() === 'admin' ? `/admin${path}` : path;
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

  // ---- Admin console: first-party cookie session (no bearer token) --------

  /**
   * Establish the admin session. Primes the CSRF cookie, then POSTs credentials;
   * the server sets an HttpOnly session cookie and returns the admin identity —
   * there is deliberately no token in the response for JS to store.
   */
  async adminLogin(email: string, password: string, remember = true): Promise<Admin> {
    await ensureAdminCsrf();
    const { data } = await portalHttp.admin.post<{ user: Admin }>('/admin/login', {
      email,
      password,
      remember,
    });
    return data.user;
  },

  /** The authenticated admin, resolved from the session cookie (source of truth). */
  async adminMe(): Promise<Admin> {
    const { data } = await portalHttp.admin.get<{ user: Admin }>('/admin/me');
    return data.user;
  },

  /** Destroy the admin session server-side. */
  async adminLogout(): Promise<void> {
    await portalHttp.admin.post('/admin/logout');
  },

  /** Update the authenticated admin's own display name and email. */
  async adminUpdateProfile(payload: { name: string; email: string }): Promise<Admin> {
    const { data } = await portalHttp.admin.patch<{ user: Admin }>('/admin/profile', payload);
    return data.user;
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
    const payload = {
      current_password: currentPassword,
      password,
      password_confirmation: passwordConfirmation,
    };

    // Admins use their isolated session endpoint (revokes other sessions via
    // logoutOtherDevices); tenants/landlords use the shared bearer endpoint.
    if (portal === 'admin') {
      const { data } = await portalHttp.admin.post<{ message: string }>(
        '/admin/password',
        payload,
      );
      return { message: data.message, revoked_other_sessions: 0 };
    }

    const { data } = await portalHttp[portal].post<{
      message: string;
      revoked_other_sessions: number;
    }>('/user/password', payload);
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
  /** Start a DRAFT application and open the guided form. */
  async startApplicationDraft(listingId: number): Promise<Application> {
    const { data } = await portalHttp.tenant.post<Application>('/tenant/applications/draft', {
      listing_id: listingId,
    });
    return data;
  },
  /** Partial autosave of a draft's structured form. */
  async saveApplicationDraft(
    id: number,
    formData: ApplicationFormData,
  ): Promise<Application> {
    const { data } = await portalHttp.tenant.patch<Application>(
      `/tenant/applications/${id}`,
      { form_data: formData },
    );
    return data;
  },
  async submitApplication(id: number, coverNote?: string): Promise<Application> {
    const { data } = await portalHttp.tenant.post<Application>(
      `/tenant/applications/${id}/submit`,
      { cover_note: coverNote ?? null },
    );
    return data;
  },
  async deleteApplicationDraft(id: number): Promise<void> {
    await portalHttp.tenant.delete(`/tenant/applications/${id}`);
  },
  /** Attach a document to a specific application. */
  async uploadApplicationDocument(
    id: number,
    file: File,
    documentType?: DocumentType,
  ): Promise<{ document: TenantDocument; application: Application }> {
    const form = new FormData();
    form.append('file', file);
    if (documentType) form.append('document_type', documentType);
    const { data } = await portalHttp.tenant.post<{
      document: TenantDocument;
      application: Application;
    }>(`/tenant/applications/${id}/documents`, form);
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
  async createMaintenance(payload: CreateMaintenancePayload): Promise<MaintenanceRequest> {
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
  async maintenanceMessages(id: number): Promise<MaintenanceMessageThread> {
    const { data } = await portalHttp.tenant.get<MaintenanceMessageThread>(
      `/tenant/maintenance/${id}/messages`,
    );
    return data;
  },
  async sendMaintenanceMessage(id: number, body: string): Promise<MaintenanceMessageThread> {
    const { data } = await portalHttp.tenant.post<MaintenanceMessageThread>(
      `/tenant/maintenance/${id}/messages`,
      { body },
    );
    return data;
  },
  async uploadMaintenanceMedia(id: number, file: File, caption?: string): Promise<MediaAsset> {
    const form = new FormData();
    form.append('file', file);
    if (caption) form.append('caption', caption);
    const { data } = await portalHttp.tenant.post<MediaAsset>(
      `/tenant/maintenance/${id}/media`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    );
    return data;
  },
  /** Stream a restricted media asset (e.g. maintenance evidence) as a Bearer-authed Blob. */
  async mediaBlob(mediaAssetId: string): Promise<Blob> {
    const { data } = await portalHttp.tenant.get(`/media/${mediaAssetId}`, { responseType: 'blob' });
    return data as Blob;
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
    await portalHttp.tenant.post(`/tenant/contracts/${id}/terminate`, { reason });
  },
  async ledger(contractId?: string): Promise<LedgerListResponse> {
    const { data } = await portalHttp.tenant.get<LedgerListResponse>('/tenant/ledger', {
      params: contractId ? { contract_id: contractId } : undefined,
    });
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

  async balance(): Promise<TenantBalance> {
    const { data } = await portalHttp.tenant.get<TenantBalance>('/tenant/payments/balance');
    return data;
  },
  async initiatePayment(ledgerEntryId: string): Promise<InitiatePaymentResponse> {
    const { data } = await portalHttp.tenant.post<InitiatePaymentResponse>(
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

  /* ---- Profile ------------------------------------------------------------ */
  async profile(): Promise<LandlordProfileResponse> {
    const { data } = await portalHttp.landlord.get<LandlordProfileResponse>('/landlord/profile');
    return data;
  },
  async updateProfile(payload: {
    first_name?: string;
    last_name?: string;
    phone?: string | null;
  }): Promise<LandlordProfileResponse> {
    const { data } = await portalHttp.landlord.patch<LandlordProfileResponse>(
      '/landlord/profile',
      payload,
    );
    return data;
  },
  async uploadAvatar(file: File): Promise<MediaAsset> {
    const form = new FormData();
    form.append('file', file);
    const { data } = await portalHttp.landlord.post<MediaAsset>('/landlord/avatar', form);
    return data;
  },

  /* ---- Applications (decide on tenant applications) ---------------------- */
  async applications(params?: { listing_id?: number }): Promise<Application[]> {
    const { data } = await portalHttp.landlord.get<Application[]>('/landlord/applications', { params });
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
  /** Ask the tenant for more info / a document replacement (→ needs_action). */
  async requestApplicationInfo(
    id: number,
    payload: {
      message: string;
      type?: 'document_replacement' | 'more_info' | 'general';
      document_type?: DocumentType | null;
      reason?: string | null;
      due_at?: string | null;
    },
  ): Promise<Application> {
    const { data } = await portalHttp.landlord.post<Application>(
      `/landlord/applications/${id}/request-info`,
      payload,
    );
    return data;
  },
  /** Internal organisational flag — toggles on/off, independent of status. */
  async toggleApplicationShortlist(id: number): Promise<Application> {
    const { data } = await portalHttp.landlord.post<Application>(
      `/landlord/applications/${id}/shortlist`,
    );
    return data;
  },
  /** The message thread with this applicant about their listing (may be empty). */
  async applicationMessages(id: number): Promise<ApplicationMessageThread> {
    const { data } = await portalHttp.landlord.get<ApplicationMessageThread>(
      `/landlord/applications/${id}/messages`,
    );
    return data;
  },
  /** Send a message to the applicant, creating the conversation on first use. */
  async sendApplicationMessage(id: number, body: string): Promise<ApplicationMessageThread> {
    const { data } = await portalHttp.landlord.post<ApplicationMessageThread>(
      `/landlord/applications/${id}/messages`,
      { body },
    );
    return data;
  },

  /* ---- Maintenance (manage requests on owned properties) ---------------- */
  async maintenance(): Promise<MaintenanceRequest[]> {
    const { data } = await portalHttp.landlord.get<MaintenanceRequest[]>('/landlord/maintenance');
    return data;
  },
  async maintenanceDetail(id: number): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.landlord.get<MaintenanceRequest>(`/landlord/maintenance/${id}`);
    return data;
  },
  async createLandlordMaintenance(payload: {
    contract_id: string;
    title: string;
    description?: string;
    category: MaintenanceCategory;
    priority: MaintenancePriority;
    mode: 'new' | 'assign' | 'resolved';
    assignee_name?: string;
    assignee_phone?: string;
    assignee_type?: MaintenanceAssigneeType;
    appointment_at?: string;
    expected_completion_date?: string;
    resolution_notes?: string;
  }): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.landlord.post<MaintenanceRequest>('/landlord/maintenance', payload);
    return data;
  },
  async updateMaintenanceStatus(
    id: number,
    payload: {
      status: MaintenanceStatus;
      resolution_notes?: string;
      waiting_reason?: string;
      assignee_name?: string;
      assignee_phone?: string;
      assignee_type?: MaintenanceAssigneeType;
      appointment_at?: string;
      expected_completion_date?: string;
      labor_cost_cents?: number;
      parts_cost_cents?: number;
    },
  ): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.landlord.patch<MaintenanceRequest>(
      `/landlord/maintenance/${id}/status`,
      payload,
    );
    return data;
  },
  async reopenMaintenance(id: number, reason: string): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.landlord.post<MaintenanceRequest>(
      `/landlord/maintenance/${id}/reopen`,
      { reason },
    );
    return data;
  },
  async updateMaintenanceCosts(id: number, payload: {
    labor_cost_cents?: number;
    parts_cost_cents?: number;
    invoice_reference?: string;
    cost_notes?: string;
    cost_paid?: boolean;
  }): Promise<MaintenanceRequest> {
    const { data } = await portalHttp.landlord.patch<MaintenanceRequest>(
      `/landlord/maintenance/${id}/costs`,
      payload,
    );
    return data;
  },
  async maintenanceMessages(id: number): Promise<MaintenanceMessageThread> {
    const { data } = await portalHttp.landlord.get<MaintenanceMessageThread>(
      `/landlord/maintenance/${id}/messages`,
    );
    return data;
  },
  async sendMaintenanceMessage(id: number, body: string): Promise<MaintenanceMessageThread> {
    const { data } = await portalHttp.landlord.post<MaintenanceMessageThread>(
      `/landlord/maintenance/${id}/messages`,
      { body },
    );
    return data;
  },
  async uploadMaintenanceMedia(id: number, file: File, caption?: string): Promise<MediaAsset> {
    const form = new FormData();
    form.append('file', file);
    if (caption) form.append('caption', caption);
    const { data } = await portalHttp.landlord.post<MediaAsset>(
      `/landlord/maintenance/${id}/media`,
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    );
    return data;
  },
  /** Stream a restricted media asset (maintenance evidence) as a Bearer-authed Blob. */
  async mediaBlob(mediaAssetId: string): Promise<Blob> {
    const { data } = await portalHttp.landlord.get(`/media/${mediaAssetId}`, { responseType: 'blob' });
    return data as Blob;
  },
  /** Scoped CSV export; the checksum is a real SHA-256 of the returned bytes. */
  async exportMaintenance(params: {
    scope: 'filtered' | 'property' | 'tenant' | 'single' | 'full';
    status?: string;
    priority?: string;
    property_id?: number;
    tenant_id?: number;
    maintenance_request_id?: number;
    reason?: string;
  }): Promise<{ blob: Blob; filename: string; checksum: string; rowCount: number }> {
    const response = await portalHttp.landlord.get('/landlord/maintenance/export', {
      params,
      responseType: 'blob',
    });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const filenameMatch = /filename="?([^"]+)"?/.exec(disposition);
    return {
      blob: response.data,
      filename: filenameMatch?.[1] ?? 'maintenance.csv',
      checksum: String(response.headers['x-export-checksum'] ?? ''),
      rowCount: Number(response.headers['x-export-row-count'] ?? 0),
    };
  },

  /* ---- Analytics (scoped to the landlord's portfolio) ------------------- */
  /** Full Portfolio Analytics page payload — real, portfolio-wide by default, optionally scoped to one property. */
  async analyticsPortfolio(range?: 'this' | 'last' | '90' | 'ytd', propertyId?: number): Promise<PortfolioAnalytics> {
    const { data } = await portalHttp.landlord.get<PortfolioAnalytics>('/landlord/analytics', {
      params: { range, property_id: propertyId },
    });
    return data;
  },
  /** Scoped, multi-section CSV export; the checksum is a real SHA-256 of the returned bytes. */
  async exportAnalytics(
    range?: 'this' | 'last' | '90' | 'ytd',
    propertyId?: number,
  ): Promise<{ blob: Blob; filename: string; checksum: string; rowCount: number }> {
    const response = await portalHttp.landlord.get('/landlord/analytics/export', {
      params: { range, property_id: propertyId },
      responseType: 'blob',
    });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const filenameMatch = /filename="?([^"]+)"?/.exec(disposition);
    return {
      blob: response.data,
      filename: filenameMatch?.[1] ?? 'analytics.csv',
      checksum: String(response.headers['x-export-checksum'] ?? ''),
      rowCount: Number(response.headers['x-export-row-count'] ?? 0),
    };
  },
  async properties(): Promise<Property[]> {
    const { data } = await portalHttp.landlord.get<Property[]>('/landlord/properties');
    return data;
  },
  async property(id: number): Promise<Property> {
    const { data } = await portalHttp.landlord.get<Property>(`/landlord/properties/${id}`);
    return data;
  },
  /** Rich Property page payload: summary, attention, units, listings, contracts, ledger, maintenance, documents, photos, activity. */
  async propertyDetail(id: number): Promise<PropertyDetailPayload> {
    const { data } = await portalHttp.landlord.get<PropertyDetailPayload>(
      `/landlord/properties/${id}/detail`,
    );
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
  /** Submits a draft, or resubmits a rejected listing (clears the prior rejection reason). */
  async submitListing(id: number): Promise<void> {
    await portalHttp.landlord.post(`/landlord/listings/${id}/submit`);
  },
  /** Pulls a pending submission back to draft before an admin has decided. */
  async withdrawListing(id: number): Promise<Listing> {
    const { data } = await portalHttp.landlord.post<{ listing: Listing }>(`/landlord/listings/${id}/withdraw`);
    return data.listing ?? (data as unknown as Listing);
  },
  /** Hides an active listing from tenants without deleting it. */
  async deactivateListing(id: number): Promise<Listing> {
    const { data } = await portalHttp.landlord.post<{ listing: Listing }>(`/landlord/listings/${id}/deactivate`);
    return data.listing ?? (data as unknown as Listing);
  },
  /** Makes a previously-active listing live again, no re-review needed. */
  async reactivateListing(id: number): Promise<Listing> {
    const { data } = await portalHttp.landlord.post<{ listing: Listing }>(`/landlord/listings/${id}/reactivate`);
    return data.listing ?? (data as unknown as Listing);
  },
  /** Archives a draft/rejected/inactive listing for record-keeping (read-only until restored). */
  async archiveListing(id: number): Promise<Listing> {
    const { data } = await portalHttp.landlord.post<{ listing: Listing }>(`/landlord/listings/${id}/archive`);
    return data.listing ?? (data as unknown as Listing);
  },
  /** Restores an archived listing back to an editable draft. */
  async restoreListing(id: number): Promise<Listing> {
    const { data } = await portalHttp.landlord.post<{ listing: Listing }>(`/landlord/listings/${id}/restore`);
    return data.listing ?? (data as unknown as Listing);
  },
  /** Real audit-log-backed timeline for a listing (review decisions, edits, uploads). */
  async listingHistory(id: number): Promise<ListingHistoryEntry[]> {
    const { data } = await portalHttp.landlord.get<ListingHistoryEntry[]>(`/landlord/listings/${id}/history`);
    return data;
  },
  async deleteListing(id: number): Promise<void> {
    await portalHttp.landlord.delete(`/landlord/listings/${id}`);
  },
  async contracts(): Promise<Contract[]> {
    const { data } = await portalHttp.landlord.get<Contract[]>('/landlord/contracts');
    return data;
  },
  /** Single contract detail — eager-loads listing/tenant/admin/renewals. */
  async contract(id: string): Promise<Contract> {
    const { data } = await portalHttp.landlord.get<Contract>(`/landlord/contracts/${id}`);
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
    await portalHttp.landlord.post(`/landlord/contracts/${id}/terminate`, { reason });
  },
  /**
   * Renews an ACTIVE contract in-place (new end date / rent amount take
   * effect immediately — there is no separate tenant re-signature step).
   * Records a ContractRenewal history row server-side.
   */
  async renewContract(
    id: string,
    payload: { new_end_date: string; new_rent_amount?: number; note?: string },
  ): Promise<{ message: string; contract: Contract }> {
    const { data } = await portalHttp.landlord.post<{ message: string; contract: Contract }>(
      `/landlord/contracts/${id}/renew`,
      payload,
    );
    return data;
  },
  /** The message thread with this contract's tenant, if one exists yet. */
  async contractMessages(id: string): Promise<ContractMessageThread> {
    const { data } = await portalHttp.landlord.get<ContractMessageThread>(
      `/landlord/contracts/${id}/messages`,
    );
    return data;
  },
  /** Send a message to this contract's tenant, creating the conversation on first use. */
  async sendContractMessage(id: string, body: string): Promise<ContractMessageThread> {
    const { data } = await portalHttp.landlord.post<ContractMessageThread>(
      `/landlord/contracts/${id}/messages`,
      { body },
    );
    return data;
  },
  /** Landlord-authored, landlord-visible notes on this tenancy's case file. */
  async contractNotes(id: string): Promise<ContractLandlordNote[]> {
    const { data } = await portalHttp.landlord.get<ContractLandlordNote[]>(
      `/landlord/contracts/${id}/notes`,
    );
    return data;
  },
  async addContractNote(id: string, body: string): Promise<ContractLandlordNote> {
    const { data } = await portalHttp.landlord.post<ContractLandlordNote>(
      `/landlord/contracts/${id}/notes`,
      { body },
    );
    return data;
  },
  async ledger(): Promise<LandlordLedgerResponse> {
    const { data } = await portalHttp.landlord.get<LandlordLedgerResponse>('/landlord/ledger');
    return data;
  },
  /** Single-entry case file: decorated entry + audit trail + linked entries. */
  async ledgerEntry(id: string): Promise<LandlordLedgerCaseFile> {
    const { data } = await portalHttp.landlord.get<LandlordLedgerCaseFile>(`/landlord/ledger/${id}`);
    return data;
  },
  /** Tenant/contract statement for one billing month (defaults to current month). */
  async contractStatement(
    contractId: string,
    period?: { year: number; month: number },
  ): Promise<LedgerContractStatement> {
    const { data } = await portalHttp.landlord.get<LedgerContractStatement>(
      `/landlord/ledger/statement/contract/${contractId}`,
      { params: period },
    );
    return data;
  },
  /** Property statement for one billing month, broken down by unit. */
  async propertyStatement(
    propertyId: number,
    period?: { year: number; month: number },
  ): Promise<LedgerPropertyStatement> {
    const { data } = await portalHttp.landlord.get<LedgerPropertyStatement>(
      `/landlord/ledger/statement/property/${propertyId}`,
      { params: period },
    );
    return data;
  },
  /**
   * Record a full-amount manual/offline payment against a chosen open
   * rent/late-fee ledger entry. There is no free-typed amount — the full
   * `display_amount_cents` of `entryId` is what gets recorded (partial
   * payments are not a real backend concept).
   */
  async recordLedgerPayment(
    entryId: string,
    payload: { method: PaymentMethod; reference?: string },
  ): Promise<{ entry: LedgerEntry; payment: LedgerEntry }> {
    const { data } = await portalHttp.landlord.post<{ entry: LedgerEntry; payment: LedgerEntry }>(
      `/landlord/ledger/${entryId}/record-payment`,
      payload,
    );
    return data;
  },

  /* ---- CSV exports (real server-generated, owner-scoped) ---------------- */
  /**
   * Ledger CSV export, optionally scoped to one contract, one property,
   * and/or a date range, with a stated reason recorded to the audit log.
   */
  async exportLedger(params?: {
    contract_id?: string;
    property_id?: number;
    date_from?: string;
    date_to?: string;
    reason?: string;
  }): Promise<void> {
    await downloadCsv(
      portalHttp.landlord,
      '/landlord/ledger/export',
      'rent-ledger.csv',
      params as Record<string, unknown> | undefined,
    );
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

  /* ---- Platform Analytics (Super Admin) ---------------------------------- */
  async platformAnalytics(params?: {
    range?: '7d' | '30d' | '90d' | 'this_month' | 'last_month' | 'ytd' | 'custom';
    start_date?: string;
    end_date?: string;
  }): Promise<PlatformAnalyticsResponse> {
    const { data } = await portalHttp.admin.get<PlatformAnalyticsResponse>('/admin/analytics/overview', { params });
    return data;
  },

  /* ---- Admin Analytics (scoped to the signed-in admin) ------------------- */
  async adminAnalytics(params?: {
    range?: '7d' | '30d' | '90d' | 'this_month' | 'last_month' | 'ytd' | 'custom';
    start_date?: string;
    end_date?: string;
  }): Promise<AdminAnalyticsResponse> {
    const { data } = await portalHttp.admin.get<AdminAnalyticsResponse>('/admin/analytics/admin-summary', { params });
    return data;
  },

  async exportAdminAnalytics(params?: { range?: string; start_date?: string; end_date?: string }): Promise<void> {
    await downloadCsv(portalHttp.admin, '/admin/analytics/admin-summary/export', 'wyncrest-admin-analytics.csv', params);
  },

  /* ---- User management -------------------------------------------------- */
  async users(params?: {
    type?: UserType;
    status?: 'active' | 'suspended' | 'blocked' | 'archived' | 'unverified';
    search?: string;
    sort?: 'review' | 'joined' | 'name';
    page?: number;
  }): Promise<AdminUsersResponse> {
    const { data } = await portalHttp.admin.get<AdminUsersResponse>('/admin/users', {
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
  // ── Maintenance oversight (viewing is baseline; mutations need manage_maintenance) ───────
  async maintenanceQueue(params?: {
    status?: 'open' | 'urgent' | 'overdue' | 'waiting' | 'escalated' | 'unassigned' | 'all';
    limit?: number;
  }): Promise<{ data: AdminMaintenanceCase[] }> {
    const { data } = await portalHttp.admin.get<{ data: AdminMaintenanceCase[] }>('/admin/maintenance', {
      params,
    });
    return data;
  },
  async maintenanceSummary(): Promise<DashboardMaintenanceSummary> {
    const { data } = await portalHttp.admin.get<DashboardMaintenanceSummary>('/admin/maintenance/summary');
    return data;
  },
  async maintenanceDetail(id: string): Promise<{ data: AdminMaintenanceDetail }> {
    const { data } = await portalHttp.admin.get<{ data: AdminMaintenanceDetail }>(`/admin/maintenance/${id}`);
    return data;
  },
  async maintenanceAnalytics(params?: { date_from?: string; date_to?: string }): Promise<AdminMaintenanceAnalytics> {
    const { data } = await portalHttp.admin.get<AdminMaintenanceAnalytics>('/admin/maintenance/analytics', { params });
    return data;
  },
  /** Super-admin only — platform-wide oversight aggregates. */
  async maintenanceOversight(): Promise<AdminMaintenanceOversight> {
    const { data } = await portalHttp.admin.get<AdminMaintenanceOversight>('/admin/maintenance/oversight');
    return data;
  },
  async assignMaintenanceCaseOwner(id: string, handlingAdminId: number): Promise<{ data: AdminMaintenanceDetail }> {
    const { data } = await portalHttp.admin.post<{ data: AdminMaintenanceDetail }>(
      `/admin/maintenance/${id}/assign`,
      { handling_admin_id: handlingAdminId },
    );
    return data;
  },
  async escalateMaintenance(id: string, reason: string): Promise<{ data: AdminMaintenanceDetail }> {
    const { data } = await portalHttp.admin.post<{ data: AdminMaintenanceDetail }>(
      `/admin/maintenance/${id}/escalate`,
      { reason },
    );
    return data;
  },
  async clearMaintenanceEscalation(id: string): Promise<{ data: AdminMaintenanceDetail }> {
    const { data } = await portalHttp.admin.post<{ data: AdminMaintenanceDetail }>(
      `/admin/maintenance/${id}/clear-escalation`,
    );
    return data;
  },
  async addMaintenanceNote(id: string, body: string): Promise<{ message: string; note: AdminMaintenanceNote }> {
    const { data } = await portalHttp.admin.post(`/admin/maintenance/${id}/notes`, { body });
    return data;
  },
  async overrideCloseMaintenance(id: string, reason: string): Promise<{ data: AdminMaintenanceDetail }> {
    const { data } = await portalHttp.admin.post<{ data: AdminMaintenanceDetail }>(
      `/admin/maintenance/${id}/override-close`,
      { reason },
    );
    return data;
  },
  async overrideReopenMaintenance(id: string, reason: string): Promise<{ data: AdminMaintenanceDetail }> {
    const { data } = await portalHttp.admin.post<{ data: AdminMaintenanceDetail }>(
      `/admin/maintenance/${id}/override-reopen`,
      { reason },
    );
    return data;
  },
  /** Scoped CSV export; the checksum is a real SHA-256 of the returned bytes. */
  async exportMaintenance(params: {
    scope: 'filtered' | 'property' | 'landlord' | 'single' | 'full';
    status?: string;
    priority?: string;
    property_id?: number;
    landlord_id?: number;
    maintenance_request_id?: string;
    reason?: string;
  }): Promise<{ blob: Blob; filename: string; checksum: string; rowCount: number }> {
    const response = await portalHttp.admin.get('/admin/maintenance/export', {
      params,
      responseType: 'blob',
    });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const filenameMatch = /filename="?([^"]+)"?/.exec(disposition);
    return {
      blob: response.data,
      filename: filenameMatch?.[1] ?? 'maintenance.csv',
      checksum: String(response.headers['x-export-checksum'] ?? ''),
      rowCount: Number(response.headers['x-export-row-count'] ?? 0),
    };
  },
  /** Stream a restricted media asset (e.g. maintenance evidence) as a Bearer-authed Blob. */
  async mediaBlob(mediaAssetId: string): Promise<Blob> {
    const { data } = await portalHttp.admin.get(`/admin/media/${mediaAssetId}`, { responseType: 'blob' });
    return data as Blob;
  },
  /** Review queue with truthful per-status counts + filtered/sorted summaries. */
  async listingReviewQueue(params?: {
    status?: 'pending' | 'approved' | 'rejected' | 'all';
    search?: string;
    sort?: 'newest' | 'oldest' | 'rent_high' | 'rent_low' | 'attention';
  }): Promise<ListingReviewQueue> {
    const { data } = await portalHttp.admin.get<ListingReviewQueue>('/admin/listings/review', {
      params,
    });
    return data;
  },
  /** Full review detail for a single listing. */
  async listingReviewDetail(id: number): Promise<ListingReviewDetail> {
    const { data } = await portalHttp.admin.get<ListingReviewDetail>(
      `/admin/listings/review/${id}`,
    );
    return data;
  },
  /** Tenant-safe preview payload (what tenants see once published). */
  async listingReviewPreview(id: number): Promise<ListingPreview> {
    const { data } = await portalHttp.admin.get<ListingPreview>(
      `/admin/listings/review/${id}/preview`,
    );
    return data;
  },
  /** Approve (publish) a listing, with an optional admin-only note. Returns fresh detail. */
  async approveListing(id: number, internalNote?: string): Promise<ListingReviewDetail> {
    const { data } = await portalHttp.admin.post<{ listing: ListingReviewDetail }>(
      `/admin/listings/review/${id}/approve`,
      internalNote ? { internal_note: internalNote } : {},
    );
    return data.listing;
  },
  /** Reject a listing with a required, landlord-facing reason + optional internal note. */
  async rejectListing(
    id: number,
    reason: string,
    internalNote?: string,
  ): Promise<ListingReviewDetail> {
    const { data } = await portalHttp.admin.post<{ listing: ListingReviewDetail }>(
      `/admin/listings/review/${id}/reject`,
      { reason, ...(internalNote ? { internal_note: internalNote } : {}) },
    );
    return data.listing;
  },
  /** Send a listing back to the landlord for changes (returns it to draft). */
  async requestListingChanges(
    id: number,
    reason: string,
    internalNote?: string,
  ): Promise<ListingReviewDetail> {
    const { data } = await portalHttp.admin.post<{ listing: ListingReviewDetail }>(
      `/admin/listings/review/${id}/request-changes`,
      { reason, ...(internalNote ? { internal_note: internalNote } : {}) },
    );
    return data.listing;
  },
  /** Attach an internal, admin-only note to a listing. */
  async addListingNote(id: number, body: string): Promise<ListingReviewNote> {
    const { data } = await portalHttp.admin.post<{ note: ListingReviewNote }>(
      `/admin/listings/review/${id}/notes`,
      { body },
    );
    return data.note;
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
  // ── Contracts case-file command centre ─────────────────────────────────
  /** The contracts queue: truthful segment counts + filtered/sorted summaries. */
  async contractQueue(params?: {
    status?: 'all' | 'active' | 'awaiting' | 'expiring' | 'overdue' | 'ended' | 'draft';
    search?: string;
    sort?: 'ending_soonest' | 'newest' | 'rent' | 'property';
  }): Promise<ContractQueue> {
    const { data } = await portalHttp.admin.get<ContractQueue>('/admin/contracts', { params });
    return data;
  },
  /** Full case-file detail for a single contract. */
  async contract(id: string): Promise<ContractCaseFileDetail> {
    const { data } = await portalHttp.admin.get<ContractCaseFileDetail>(`/admin/contracts/${id}`);
    return data;
  },
  /** Contract-scoped, decorated ledger entries + financial summary. */
  async contractLedger(id: string): Promise<ContractLedgerResponse> {
    const { data } = await portalHttp.admin.get<ContractLedgerResponse>(`/admin/contracts/${id}/ledger`);
    return data;
  },
  /** Contract-scoped payment history. */
  async contractPayments(id: string): Promise<ContractPayment[]> {
    const { data } = await portalHttp.admin.get<{ data: ContractPayment[] }>(`/admin/contracts/${id}/payments`);
    return data.data;
  },
  /** Computed billing schedule (real generated periods + at most one projected upcoming period). */
  async contractBillingSchedule(id: string): Promise<ContractBillingPeriod[]> {
    const { data } = await portalHttp.admin.get<{ data: ContractBillingPeriod[] }>(
      `/admin/contracts/${id}/billing-schedule`,
    );
    return data.data;
  },
  /** Real lifecycle timeline, sourced from the audit log. */
  async contractTimeline(id: string): Promise<ContractTimelineEvent[]> {
    const { data } = await portalHttp.admin.get<{ data: ContractTimelineEvent[] }>(`/admin/contracts/${id}/timeline`);
    return data.data;
  },
  /** Real contract-attached documents (truthfully empty until one is uploaded). */
  async contractDocuments(id: string): Promise<ContractDocument[]> {
    const { data } = await portalHttp.admin.get<{ data: ContractDocument[] }>(`/admin/contracts/${id}/documents`);
    return data.data;
  },
  /** Attach an internal, admin-only note to a contract's case file. */
  async addContractNote(id: string, body: string): Promise<ContractNote> {
    const { data } = await portalHttp.admin.post<{ note: ContractNote }>(`/admin/contracts/${id}/notes`, { body });
    return data.note;
  },
  /** Force-terminate an active contract. Reason is required and audited. */
  async terminateContract(id: string, reason: string): Promise<void> {
    await portalHttp.admin.post(`/admin/contracts/${id}/terminate`, { reason });
  },
  async ledger(params?: LedgerQueryParams): Promise<PaginatedLedger> {
    const { data } = await portalHttp.admin.get<PaginatedLedger>('/admin/ledger', {
      params,
    });
    return data;
  },
  async ledgerEntry(id: string): Promise<LedgerEntryCaseFile> {
    const { data } = await portalHttp.admin.get<LedgerEntryCaseFile>(`/admin/ledger/${id}`);
    return data;
  },
  async ledgerReconciliation(): Promise<LedgerReconciliationReport> {
    const { data } = await portalHttp.admin.get<LedgerReconciliationReport>('/admin/ledger/reconciliation');
    return data;
  },
  /** Waive a pending/overdue rent or late fee entry. Reason is required and audited. */
  async waiveLedgerEntry(id: string, reason: string): Promise<LedgerEntryCaseFile> {
    const { data } = await portalHttp.admin.post<LedgerEntryCaseFile>(`/admin/ledger/${id}/waive`, { reason });
    return data;
  },
  /** Generate a late fee against an overdue rent entry. */
  async generateLateFee(id: string, amountCents: number): Promise<void> {
    await portalHttp.admin.post(`/admin/ledger/${id}/late-fee`, { amount_cents: amountCents });
  },
  /** CSV export of the (optionally filtered) platform ledger. Audit-logged server-side. */
  async exportLedger(params?: LedgerQueryParams): Promise<void> {
    await downloadCsv(portalHttp.admin, '/admin/ledger/export', 'wyncrest-ledger.csv', params as Record<string, unknown> | undefined);
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
    // Filters verification_request *records*, so this speaks the request
    // vocabulary (terminal = 'approved'), matching the backend's
    // `in:pending,under_review,approved,rejected,needs_more_information` rule.
    status?: VerificationRequestStatus;
    role?: 'tenant' | 'landlord';
    search?: string;
    from_date?: string;
    to_date?: string;
    needs_documents?: boolean;
    sort?: 'newest' | 'oldest' | 'needs_attention_first';
    page?: number;
  }): Promise<Paginated<AdminVerificationRequest>> {
    const { data } = await portalHttp.admin.get<Paginated<AdminVerificationRequest>>(
      '/admin/verifications',
      { params },
    );
    return data;
  },
  /** Truthful counts for the queue's summary cards. */
  async verificationsSummary(): Promise<VerificationSummary> {
    const { data } = await portalHttp.admin.get<VerificationSummary>(
      '/admin/verifications/summary',
    );
    return data;
  },
  async verification(id: string): Promise<AdminVerificationDetail> {
    const { data } = await portalHttp.admin.get<AdminVerificationDetail>(
      `/admin/verifications/${id}`,
    );
    return data;
  },
  async addVerificationNote(id: string, body: string): Promise<VerificationNote> {
    const { data } = await portalHttp.admin.post<{ message: string; note: VerificationNote }>(
      `/admin/verifications/${id}/notes`,
      { body },
    );
    return data.note;
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
  /**
   * Fetch a document as a blob and hand back an object URL for inline preview
   * (image/PDF viewer). Reuses the same admin-gated, audited download route —
   * the Content-Disposition header is irrelevant once fetched as a blob, since
   * the frontend controls how the object URL is used. Caller must revoke the
   * URL (URL.revokeObjectURL) when done.
   */
  async previewDocumentBlob(id: number): Promise<{ url: string; mimeType: string }> {
    const res = await portalHttp.admin.get(`/admin/documents/${id}/download`, {
      responseType: 'blob',
    });
    const blob = res.data as Blob;
    return { url: URL.createObjectURL(blob), mimeType: blob.type };
  },

  /* ---- Review moderation ------------------------------------------------- */
  async adminReviewQueue(params?: {
    status?: 'queue' | 'approved' | 'rejected' | 'hidden' | 'flagged' | 'all';
    search?: string;
    sort?: 'risk' | 'newest' | 'oldest' | 'lowrating';
  }): Promise<AdminReviewQueueResponse> {
    const { data } = await portalHttp.admin.get<AdminReviewQueueResponse>(
      '/admin/reviews',
      { params },
    );
    return data;
  },
  async adminReviewDetail(id: number): Promise<AdminReviewDetail> {
    const { data } = await portalHttp.admin.get<AdminReviewDetail>(`/admin/reviews/${id}`);
    return data;
  },
  async moderateReview(
    id: number,
    action: 'approve' | 'reject' | 'hide' | 'flag',
    reason?: string,
  ): Promise<AdminReviewDetail> {
    const { data } = await portalHttp.admin.post<AdminReviewDetail>(
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
    const { data } = await activePortalClient().get<WeatherData>(crossRolePath('/weather'), {
      params: { city },
    });
    return data;
  },
};

/* ========================= Notifications ================================ */
export const notificationApi = {
  async list(params?: { page?: number }): Promise<Paginated<AppNotification>> {
    const { data } = await activePortalClient().get<Paginated<AppNotification>>(
      crossRolePath('/notifications'),
      { params },
    );
    return data;
  },
  async unreadCount(): Promise<number> {
    const { data } = await activePortalClient().get<{ count: number }>(
      crossRolePath('/notifications/unread-count'),
    );
    return data.count ?? 0;
  },
  async markRead(id: string): Promise<void> {
    await activePortalClient().patch(crossRolePath(`/notifications/${id}/read`));
  },
  async markAllRead(): Promise<void> {
    await activePortalClient().post(crossRolePath('/notifications/mark-all-read'));
  },
  /** Per-notification-type email/SMS delivery preferences (real backend). */
  async getPreferences(): Promise<Record<string, { email: boolean; sms: boolean }>> {
    const { data } = await activePortalClient().get<Record<string, { email: boolean; sms: boolean }>>(
      crossRolePath('/notification-preferences'),
    );
    return data;
  },
  async updatePreferences(
    prefs: Record<string, { email: boolean; sms: boolean }>,
  ): Promise<void> {
    await activePortalClient().put(crossRolePath('/notification-preferences'), prefs);
  },
};
