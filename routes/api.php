<?php

use App\Http\Controllers\Admin\AdminAccessController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminAnalyticsOverviewController;
// ============================================================================
// AUTHENTICATION CONTROLLER
// ============================================================================
use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\AdminContractController;
use App\Http\Controllers\Admin\AdminDashboardController;
// ============================================================================
// PUBLIC CONTROLLERS
// ============================================================================
use App\Http\Controllers\Admin\AdminFeatureController;
// ============================================================================
// TENANT CONTROLLERS
// ============================================================================
use App\Http\Controllers\Admin\AdminLedgerController;
use App\Http\Controllers\Admin\AdminListingModerationController;
use App\Http\Controllers\Admin\AdminMaintenanceController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminReviewController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminVerificationController;
use App\Http\Controllers\Analytics\ContractAnalyticsController;
use App\Http\Controllers\Analytics\FinancialAnalyticsController;
use App\Http\Controllers\Analytics\NotificationAnalyticsController;
use App\Http\Controllers\Analytics\PlatformAnalyticsController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\AdminInviteController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Landlord\LandlordAnalyticsController;
use App\Http\Controllers\Landlord\LandlordApplicationController;
use App\Http\Controllers\Landlord\LandlordContractController;
use App\Http\Controllers\Landlord\LandlordDashboardController;
// ============================================================================
// LANDLORD CONTROLLERS
// ============================================================================
use App\Http\Controllers\Landlord\LandlordExportController;
use App\Http\Controllers\Landlord\LandlordLedgerController;
use App\Http\Controllers\Landlord\LandlordListingController;
use App\Http\Controllers\Landlord\LandlordMaintenanceController;
use App\Http\Controllers\Landlord\LandlordOnboardingController;
use App\Http\Controllers\Landlord\LandlordProfileController;
use App\Http\Controllers\Landlord\LandlordReviewController;
use App\Http\Controllers\Landlord\PropertyController;
use App\Http\Controllers\Landlord\UnitController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MetricsController;
// ============================================================================
// ADMIN CONTROLLERS
// ============================================================================
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\Public\PublicListingController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Tenant\ApplicationController;
use App\Http\Controllers\Tenant\ConversationController;
use App\Http\Controllers\Tenant\DocumentController;
use App\Http\Controllers\Tenant\MaintenanceRequestController;
use App\Http\Controllers\Tenant\MessageableRecipientController;
use App\Http\Controllers\Tenant\MessageAttachmentController;
// ============================================================================
// WEBHOOK CONTROLLERS
// ============================================================================
use App\Http\Controllers\Tenant\ReviewController;
// ============================================================================
// NOTIFICATION CONTROLLER - Phase 3.5
// ============================================================================
use App\Http\Controllers\Tenant\SavedListingController;
// ============================================================================
// NOTIFICATION PREFERENCE CONTROLLER - Phase 3.8
// ============================================================================
use App\Http\Controllers\Tenant\TenantContractController;
// ============================================================================
// ANALYTICS CONTROLLERS - Phase 4.0
// ============================================================================
use App\Http\Controllers\Tenant\TenantDashboardController;
use App\Http\Controllers\Tenant\TenantLedgerController;
use App\Http\Controllers\Tenant\TenantPaymentController;
use App\Http\Controllers\Tenant\TenantProfileController;
// ============================================================================
// MEDIA CONTROLLER - Phase 3 (media storage)
// ============================================================================
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\WeatherController;
// ============================================================================
// VERIFICATION CONTROLLER - Phase 4 (verification lifecycle)
// ============================================================================
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Wyncrest
|--------------------------------------------------------------------------
|
| Strict role separation enforced via middleware.
| - Public: No authentication
| - Tenant: auth:sanctum + tenant middleware
| - Landlord: auth:sanctum + landlord middleware
| - Admin: auth:sanctum + admin middleware
|
| Phase 7.5: Rate limiting and metrics applied to all routes
|
*/

// ============================================================================
// AUTHENTICATION ROUTES (NO AUTH REQUIRED)
// ============================================================================
Route::middleware(['rate.limit.role'])->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Authenticated routes (tenant/landlord — Sanctum bearer tokens)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    // Self-service password change (tenant/landlord) — audited, revokes other tokens.
    Route::post('/user/password', [AuthController::class, 'changePassword']);
});

// ============================================================================
// ADMIN CONSOLE AUTH — first-party COOKIE SESSION (never bearer tokens)
// ----------------------------------------------------------------------------
// The admin SPA authenticates via an HttpOnly, Secure (prod), SameSite session
// cookie on the native `admin` guard. These routes use the `web` middleware
// group (StartSession + CSRF), so the SPA must GET /sanctum/csrf-cookie before
// any mutating call. Isolated from the tenant/landlord bearer flow by design.
// ============================================================================
Route::middleware(['web'])->prefix('admin')->group(function () {
    // Public: establish a session. CSRF-protected; rate-limited in-controller.
    Route::middleware(['rate.limit.role'])->post('/login', [AdminAuthController::class, 'login']);

    // Authenticated: identity, logout, password. `auth.session` binds the session
    // to the password hash so a password change ends the admin's other sessions.
    Route::middleware(['auth:admin', 'auth.session', 'admin', 'rate.limit.role'])->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::post('/password', [AdminAuthController::class, 'password']);
        Route::patch('/profile', [AdminAuthController::class, 'updateProfile']);
    });
});

// ============================================================================
// SOCIAL AUTH — Google OAuth (Socialite, stateless)
// ============================================================================
Route::middleware(['rate.limit.role'])->group(function () {
    // Which providers are configured (SPA uses this to show/hide buttons).
    Route::get('/auth/providers', [SocialAuthController::class, 'providers']);
    // Returns a JSON {url} for the SPA to navigate to.
    Route::get('/auth/google/redirect', [SocialAuthController::class, 'googleRedirect']);
    // Google redirects the browser here after consent.
    Route::get('/auth/google/callback', [SocialAuthController::class, 'googleCallback']);
});

// ============================================================================
// PASSWORD RESET (NO AUTH REQUIRED)
// ============================================================================
Route::middleware(['throttle:6,1'])->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
    // Invited admin sets their password and activates their account (token-guarded).
    Route::post('/admin/accept-invite', [AdminInviteController::class, 'accept']);
});

// ============================================================================
// EMAIL VERIFICATION
// ============================================================================
// Resend link — requires auth (user must be logged in to request verification).
Route::middleware(['auth:sanctum', 'throttle:6,1'])->group(function () {
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'sendVerification']);
});
// Consume verification link — no auth (the signed token is the credential).
Route::middleware(['throttle:6,1'])->group(function () {
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
        ->name('email.verify');
});

// Apply metrics middleware to all API routes
Route::middleware(['metrics'])->group(function () {

    // ============================================================================
    // PUBLIC ROUTES (NO AUTH)
    // ============================================================================
    Route::middleware(['rate.limit.role'])->prefix('listings')->group(function () {
        Route::get('/', [PublicListingController::class, 'index']);
        Route::get('/featured', [PublicListingController::class, 'featured']);
        Route::get('/{id}', [PublicListingController::class, 'show']);
    });

    // ============================================================================
    // TENANT ROUTES
    // ============================================================================
    Route::middleware(['auth:sanctum', 'tenant', 'rate.limit.role'])->prefix('tenant')->group(function () {
        // Dashboard
        Route::get('/dashboard', [TenantDashboardController::class, 'index']);

        // Profile
        Route::get('/profile', [TenantProfileController::class, 'show']);
        Route::patch('/profile', [TenantProfileController::class, 'update']);

        // Avatar upload
        Route::post('/avatar', [MediaController::class, 'storeAvatar']);

        // Saved Listings
        Route::get('/saved-listings', [SavedListingController::class, 'index']);
        Route::post('/listings/{listing}/save', [SavedListingController::class, 'store']);
        Route::delete('/listings/{listing}/save', [SavedListingController::class, 'destroy']);

        // Contracts (Phase 3.1)
        Route::get('/contracts', [TenantContractController::class, 'index']);
        Route::get('/contracts/{contract}', [TenantContractController::class, 'show']);
        Route::post('/contracts/{contract}/accept', [TenantContractController::class, 'accept']);
        Route::post('/contracts/{contract}/terminate', [TenantContractController::class, 'terminate']);

        // Ledger (Phase 3.2)
        Route::get('/ledger', [TenantLedgerController::class, 'index']);
        Route::get('/ledger/{ledgerEntry}', [TenantLedgerController::class, 'show']);

        // Payments (Phase 3.3)
        Route::post('/payments/initiate/{ledgerEntry}', [TenantPaymentController::class, 'initiate']);
        Route::get('/payments/balance', [TenantPaymentController::class, 'balance']);

        // Applications — static paths MUST come before the {application} wildcard
        Route::get('/applications', [ApplicationController::class, 'index']);
        Route::post('/applications', [ApplicationController::class, 'store']);
        Route::post('/applications/draft', [ApplicationController::class, 'storeDraft']);
        Route::get('/applications/{application}', [ApplicationController::class, 'show']);
        Route::patch('/applications/{application}', [ApplicationController::class, 'update']);
        Route::delete('/applications/{application}', [ApplicationController::class, 'destroy']);
        Route::post('/applications/{application}/submit', [ApplicationController::class, 'submit']);
        Route::post('/applications/{application}/withdraw', [ApplicationController::class, 'withdraw']);
        Route::post('/applications/{application}/documents', [ApplicationController::class, 'storeDocument']);

        // Documents
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

        // Maintenance Requests
        Route::get('/maintenance', [MaintenanceRequestController::class, 'index']);
        Route::post('/maintenance', [MaintenanceRequestController::class, 'store']);
        Route::get('/maintenance/{maintenanceRequest}', [MaintenanceRequestController::class, 'show']);
        Route::post('/maintenance/{maintenanceRequest}/cancel', [MaintenanceRequestController::class, 'cancel']);
        Route::get('/maintenance/{maintenanceRequest}/messages', [MaintenanceRequestController::class, 'messages']);
        Route::post('/maintenance/{maintenanceRequest}/messages', [MaintenanceRequestController::class, 'sendMessage']);
        Route::post('/maintenance/{maintenanceRequest}/media', [MediaController::class, 'storeForMaintenanceRequest']);

        // Messaging
        Route::get('/messageable-recipients', [MessageableRecipientController::class, 'index']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
        Route::get('/messages/attachments/{attachment}', [MessageAttachmentController::class, 'show']);

        // Verification (Phase 4)
        Route::get('/verification', [VerificationController::class, 'show']);
        Route::post('/verification/submit', [VerificationController::class, 'submit']);

        // Reviews (Phase 8)
        Route::get('/reviews', [ReviewController::class, 'index']);
        Route::get('/listings/{listing}/review-eligibility', [ReviewController::class, 'eligibility']);
        Route::post('/reviews', [ReviewController::class, 'store']);
        Route::patch('/reviews/{review}', [ReviewController::class, 'update']);
    });

    // ============================================================================
    // LANDLORD ROUTES
    // ============================================================================
    Route::middleware(['auth:sanctum', 'landlord', 'rate.limit.role'])->prefix('landlord')->group(function () {
        // Onboarding
        Route::get('/onboarding', [LandlordOnboardingController::class, 'index']);

        // Dashboard
        Route::get('/dashboard', [LandlordDashboardController::class, 'index']);

        // Profile
        Route::get('/profile', [LandlordProfileController::class, 'show']);
        Route::patch('/profile', [LandlordProfileController::class, 'update']);

        // Avatar upload
        Route::post('/avatar', [MediaController::class, 'storeAvatar']);

        // Properties
        Route::get('/properties', [PropertyController::class, 'index']);
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::get('/properties/{property}', [PropertyController::class, 'show']);
        Route::get('/properties/{property}/detail', [PropertyController::class, 'detail']);
        Route::put('/properties/{property}', [PropertyController::class, 'update']);
        Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);

        // Units
        Route::get('/units', [UnitController::class, 'index']);
        Route::post('/properties/{property}/units', [UnitController::class, 'store']);
        Route::get('/units/{unit}', [UnitController::class, 'show']);
        Route::put('/units/{unit}', [UnitController::class, 'update']);
        Route::delete('/units/{unit}', [UnitController::class, 'destroy']);

        // Listings — static paths MUST come before the {listing} wildcard
        Route::get('/listings/export', [LandlordExportController::class, 'listings']);
        Route::get('/listings', [LandlordListingController::class, 'index']);
        Route::post('/units/{unit}/listings', [LandlordListingController::class, 'store']);
        Route::get('/listings/{listing}', [LandlordListingController::class, 'show']);
        Route::put('/listings/{listing}', [LandlordListingController::class, 'update']);
        Route::post('/listings/{listing}/submit', [LandlordListingController::class, 'submit']);
        Route::post('/listings/{listing}/withdraw', [LandlordListingController::class, 'withdraw']);
        Route::post('/listings/{listing}/deactivate', [LandlordListingController::class, 'deactivate']);
        Route::post('/listings/{listing}/reactivate', [LandlordListingController::class, 'reactivate']);
        Route::post('/listings/{listing}/archive', [LandlordListingController::class, 'archive']);
        Route::post('/listings/{listing}/restore', [LandlordListingController::class, 'restore']);
        Route::get('/listings/{listing}/history', [LandlordListingController::class, 'history']);
        Route::delete('/listings/{listing}', [LandlordListingController::class, 'destroy']);

        // Contracts (Phase 3.1)
        Route::get('/contracts', [LandlordContractController::class, 'index']);
        Route::post('/contracts', [LandlordContractController::class, 'store']);
        Route::get('/contracts/{contract}', [LandlordContractController::class, 'show']);
        Route::post('/contracts/{contract}/send', [LandlordContractController::class, 'send']);
        Route::post('/contracts/{contract}/terminate', [LandlordContractController::class, 'terminate']);
        Route::post('/contracts/{contract}/renew', [LandlordContractController::class, 'renew']);
        Route::get('/contracts/{contract}/messages', [LandlordContractController::class, 'messages']);
        Route::post('/contracts/{contract}/messages', [LandlordContractController::class, 'sendMessage']);
        Route::get('/contracts/{contract}/notes', [LandlordContractController::class, 'notes']);
        Route::post('/contracts/{contract}/notes', [LandlordContractController::class, 'addNote']);

        // Ledger (Phase 3.2) — static export/statement paths MUST come before the {ledgerEntry} wildcard
        Route::get('/ledger/export', [LandlordExportController::class, 'ledger']);
        Route::get('/ledger/statement/contract/{contract}', [LandlordLedgerController::class, 'contractStatement']);
        Route::get('/ledger/statement/property/{property}', [LandlordLedgerController::class, 'propertyStatement']);
        Route::get('/ledger', [LandlordLedgerController::class, 'index']);
        Route::get('/ledger/{ledgerEntry}', [LandlordLedgerController::class, 'show']);
        Route::post('/ledger/{ledgerEntry}/record-payment', [LandlordLedgerController::class, 'recordPayment'])->name('landlord.ledger.record-payment');

        // Applications — static export path MUST come before the {application} wildcard
        Route::get('/applications/export', [LandlordExportController::class, 'applications']);
        Route::get('/applications', [LandlordApplicationController::class, 'index']);
        Route::get('/applications/{application}', [LandlordApplicationController::class, 'show']);
        Route::post('/applications/{application}/decide', [LandlordApplicationController::class, 'decide']);
        Route::post('/applications/{application}/request-info', [LandlordApplicationController::class, 'requestInfo']);
        Route::post('/applications/{application}/shortlist', [LandlordApplicationController::class, 'toggleShortlist']);
        Route::get('/applications/{application}/messages', [LandlordApplicationController::class, 'messages']);
        Route::post('/applications/{application}/messages', [LandlordApplicationController::class, 'sendMessage']);

        // Maintenance Requests — static /export path MUST come before the
        // {maintenanceRequest} wildcard.
        Route::get('/maintenance/export', [LandlordMaintenanceController::class, 'export']);
        Route::get('/maintenance', [LandlordMaintenanceController::class, 'index']);
        Route::post('/maintenance', [LandlordMaintenanceController::class, 'store']);
        Route::get('/maintenance/{maintenanceRequest}', [LandlordMaintenanceController::class, 'show']);
        Route::patch('/maintenance/{maintenanceRequest}/status', [LandlordMaintenanceController::class, 'updateStatus']);
        Route::post('/maintenance/{maintenanceRequest}/reopen', [LandlordMaintenanceController::class, 'reopen']);
        Route::patch('/maintenance/{maintenanceRequest}/costs', [LandlordMaintenanceController::class, 'updateCosts']);
        Route::get('/maintenance/{maintenanceRequest}/messages', [LandlordMaintenanceController::class, 'messages']);
        Route::post('/maintenance/{maintenanceRequest}/messages', [LandlordMaintenanceController::class, 'sendMessage']);
        Route::post('/maintenance/{maintenanceRequest}/media', [MediaController::class, 'storeForMaintenanceRequest']);

        // Media (property/unit/listing galleries)
        Route::post('/properties/{property}/media', [MediaController::class, 'storeForProperty']);
        Route::post('/units/{unit}/media', [MediaController::class, 'storeForUnit']);
        Route::post('/listings/{listing}/media', [MediaController::class, 'storeForListing']);
        Route::get('/media/{mediaAsset}', [MediaController::class, 'show']);
        Route::patch('/media/reorder', [MediaController::class, 'reorder']);
        Route::delete('/media/{mediaAsset}', [MediaController::class, 'destroy']);

        // Verification (Phase 4)
        Route::get('/verification', [VerificationController::class, 'show']);
        Route::post('/verification/submit', [VerificationController::class, 'submit']);

        // Documents (verification/ownership uploads — DocumentController is scoped to the
        // authenticated user, so it serves landlords as well as tenants).
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

        // Analytics (scoped to landlord's properties)
        Route::get('/analytics/export', [LandlordExportController::class, 'analytics']);
        Route::get('/analytics', [LandlordAnalyticsController::class, 'index']);
        Route::get('/analytics/financial', [FinancialAnalyticsController::class, 'index']);
        Route::get('/analytics/contracts', [ContractAnalyticsController::class, 'index']);

        // Reviews (Phase 8)
        Route::get('/reviews', [LandlordReviewController::class, 'index']);
        Route::post('/reviews/{review}/respond', [LandlordReviewController::class, 'respond']);
    });

    // ============================================================================
    // ADMIN ROUTES — first-party cookie session on the `admin` guard.
    // `web` (session + CSRF) + auth:admin + auth.session + EnsureAdmin. Mutating
    // routes are CSRF-protected; the admin SPA sends the X-XSRF-TOKEN header.
    // ============================================================================
    Route::middleware(['web', 'auth:admin', 'auth.session', 'admin', 'rate.limit.role'])->prefix('admin')->group(function () {
        // Dashboard — available to any active admin (no specific capability).
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        // Admin Analytics — a permission-scoped work/risk dashboard for the
        // SIGNED-IN admin, reachable by any active admin (no capability gate),
        // same as /dashboard above. Distinct from the Super Admin "Platform
        // Analytics" overview below (admin.can:view_analytics): the module
        // SECTIONS in the response, not access to the route itself, are what
        // AdminAnalyticsService scopes to the admin's real capabilities.
        Route::get('/analytics/admin-summary', [AdminAnalyticsController::class, 'summary']);
        Route::get('/analytics/admin-summary/export', [AdminAnalyticsController::class, 'export']);

        // Cross-role authenticated endpoints the admin console consumes via its
        // SESSION — mirrors the tenant/landlord bearer routes. The controllers
        // already return truthful EMPTY results for admins (who have no per-user
        // notification stream), so they are reused as-is. Isolated admin copies
        // (rather than sharing the bearer routes) keep CSRF/session concerns off
        // the tenant/landlord flow. Static segments precede the {notification}
        // wildcard; `/notifications/deliveries` stays under view_audit below.
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread', [NotificationController::class, 'unread']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);
        Route::get('/weather', [WeatherController::class, 'current']);

        // Access Control — "Manage Users & Permissions".
        // Reads require the manage_access capability; every mutation is
        // super-admin-only (enforced in the FormRequest authorize()).
        Route::middleware('admin.can:manage_access')->prefix('access')->group(function () {
            Route::get('/summary', [AdminAccessController::class, 'summary']);
            Route::get('/roles', [AdminAccessController::class, 'roles']);
            Route::get('/members', [AdminAccessController::class, 'members']);
            Route::get('/admins', [AdminAccessController::class, 'admins']);
            Route::get('/admins/{admin}', [AdminAccessController::class, 'showAdmin']);

            Route::post('/admins', [AdminAccessController::class, 'invite']);
            Route::post('/admins/{admin}/resend-invite', [AdminAccessController::class, 'resendInvite']);
            Route::post('/admins/{admin}/revoke-invite', [AdminAccessController::class, 'revokeInvite']);
            Route::patch('/admins/{admin}/capabilities', [AdminAccessController::class, 'updateCapabilities']);
            Route::post('/admins/{admin}/promote-super', [AdminAccessController::class, 'promoteSuper']);
            Route::post('/admins/{admin}/demote-super', [AdminAccessController::class, 'demoteSuper']);
            Route::post('/admins/{admin}/deactivate', [AdminAccessController::class, 'deactivate']);
            Route::post('/admins/{admin}/activate', [AdminAccessController::class, 'activate']);
        });

        // User Management — reading the roster is available to any admin;
        // only the moderation actions require manage_users.
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::middleware('admin.can:manage_users')->group(function () {
            Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend']);
            Route::post('/users/{user}/activate', [AdminUserController::class, 'activate']);
            Route::post('/users/{user}/block', [AdminUserController::class, 'block']);
            Route::post('/users/{user}/archive', [AdminUserController::class, 'archive']);
        });

        // Verification Management (Phase 4)
        Route::middleware('admin.can:review_verifications')->group(function () {
            Route::get('/verifications', [AdminVerificationController::class, 'index']);
            // Declared BEFORE {verificationRequest} so "summary" is not captured as an id.
            Route::get('/verifications/summary', [AdminVerificationController::class, 'summary']);
            Route::get('/verifications/{verificationRequest}', [AdminVerificationController::class, 'show']);
            Route::post('/verifications/{verificationRequest}/approve', [AdminVerificationController::class, 'approve']);
            Route::post('/verifications/{verificationRequest}/reject', [AdminVerificationController::class, 'reject']);
            Route::post('/verifications/{verificationRequest}/request-info', [AdminVerificationController::class, 'requestInfo']);
            Route::post('/verifications/{verificationRequest}/notes', [AdminVerificationController::class, 'addNote']);
            // Stream applicant documents during moderation (admin-gated + audited).
            Route::get('/documents/{document}/download', [AdminVerificationController::class, 'downloadDocument']);
        });

        // Listing Moderation / Review
        Route::middleware('admin.can:moderate_listings')->group(function () {
            // Review command centre: queue, detail, tenant preview, notes.
            Route::get('/listings/review', [AdminListingModerationController::class, 'index']);
            Route::get('/listings/review/{listing}', [AdminListingModerationController::class, 'show']);
            Route::get('/listings/review/{listing}/preview', [AdminListingModerationController::class, 'preview']);
            Route::post('/listings/review/{listing}/approve', [AdminListingModerationController::class, 'approve']);
            Route::post('/listings/review/{listing}/reject', [AdminListingModerationController::class, 'reject']);
            Route::post('/listings/review/{listing}/request-changes', [AdminListingModerationController::class, 'requestChanges']);
            Route::post('/listings/review/{listing}/notes', [AdminListingModerationController::class, 'storeNote']);

            // Legacy endpoints (kept for backward compatibility).
            Route::get('/listings/pending', [AdminListingModerationController::class, 'pending']);
            Route::post('/listings/{listing}/approve', [AdminListingModerationController::class, 'approve']);
            Route::post('/listings/{listing}/reject', [AdminListingModerationController::class, 'reject']);
        });

        // Feature Management
        Route::middleware('admin.can:manage_features')->group(function () {
            Route::get('/landlords/{landlord}/features', [AdminFeatureController::class, 'index']);
            Route::post('/landlords/{landlord}/features/{feature}/enable', [AdminFeatureController::class, 'enable']);
            Route::post('/landlords/{landlord}/features/{feature}/disable', [AdminFeatureController::class, 'disable']);
        });

        // Audit Logs — static paths MUST come before the {auditLog} wildcard
        Route::middleware('admin.can:view_audit')->group(function () {
            Route::get('/audit-logs', [AdminAuditController::class, 'index']);
            Route::get('/audit-logs/summary', [AdminAuditController::class, 'summary']);
            Route::get('/audit-logs/export', [AdminAuditController::class, 'export']);
            // Declared BEFORE {auditLog} so "verify" is not captured as an id.
            Route::get('/audit-logs/verify', [AdminAuditController::class, 'verify']);
            Route::get('/audit-logs/{auditLog}', [AdminAuditController::class, 'show']);

            // Platform notification delivery monitor (read-only, truthful)
            Route::get('/notifications/deliveries', [AdminNotificationController::class, 'deliveries']);
        });

        // Contracts (Phase 3.1; case-file command centre added July 2026) —
        // reading contract records is available to any admin; only writes
        // (notes, termination) require manage_contracts.
        Route::get('/contracts', [AdminContractController::class, 'index']);
        // Static path MUST come before the {contract} wildcard below, or
        // "summary" is swallowed as a route param.
        Route::get('/contracts/summary', [AdminContractController::class, 'summary']);
        Route::get('/contracts/{contract}', [AdminContractController::class, 'show']);
        Route::get('/contracts/{contract}/ledger', [AdminContractController::class, 'ledger']);
        Route::get('/contracts/{contract}/payments', [AdminContractController::class, 'payments']);
        Route::get('/contracts/{contract}/billing-schedule', [AdminContractController::class, 'billingSchedule']);
        Route::get('/contracts/{contract}/timeline', [AdminContractController::class, 'timeline']);
        Route::get('/contracts/{contract}/documents', [AdminContractController::class, 'documents']);
        Route::middleware('admin.can:manage_contracts')->group(function () {
            Route::post('/contracts/{contract}/notes', [AdminContractController::class, 'storeNote']);
            Route::post('/contracts/{contract}/terminate', [AdminContractController::class, 'terminate']);
        });

        // Ledger (Phase 3.2) — reading ledger entries is available to any
        // admin; late-fee generation and waivers require manage_ledger.
        Route::get('/ledger', [AdminLedgerController::class, 'index']);
        // Static paths MUST come before the {ledgerEntry} wildcard below, or
        // "reconciliation"/"export" are swallowed as a route param.
        Route::get('/ledger/reconciliation', [AdminLedgerController::class, 'reconciliation']);
        Route::get('/ledger/export', [AdminLedgerController::class, 'export']);
        Route::get('/ledger/{ledgerEntry}', [AdminLedgerController::class, 'show']);
        Route::middleware('admin.can:manage_ledger')->group(function () {
            Route::post('/ledger/{ledgerEntry}/late-fee', [AdminLedgerController::class, 'generateLateFee']);
            Route::post('/ledger/{ledgerEntry}/waive', [AdminLedgerController::class, 'waive']);
        });

        // Maintenance oversight. Viewing (index/summary/analytics/show) is a
        // baseline admin privilege, same as Contracts/Ledger above. Mutating
        // actions require manage_maintenance. Oversight is gated inline in the
        // controller to super admins only (a different privilege tier).
        // Static paths MUST come before the {maintenanceRequest} wildcard.
        Route::get('/maintenance', [AdminMaintenanceController::class, 'index']);
        Route::get('/maintenance/summary', [AdminMaintenanceController::class, 'summary']);
        Route::get('/maintenance/analytics', [AdminMaintenanceController::class, 'analytics']);
        Route::get('/maintenance/oversight', [AdminMaintenanceController::class, 'oversight']);
        Route::middleware('admin.can:manage_maintenance')->group(function () {
            Route::get('/maintenance/export', [AdminMaintenanceController::class, 'export']);
        });
        Route::get('/maintenance/{maintenanceRequest}', [AdminMaintenanceController::class, 'show']);
        Route::middleware('admin.can:manage_maintenance')->group(function () {
            Route::post('/maintenance/{maintenanceRequest}/assign', [AdminMaintenanceController::class, 'assignCaseOwner']);
            Route::post('/maintenance/{maintenanceRequest}/escalate', [AdminMaintenanceController::class, 'escalate']);
            Route::post('/maintenance/{maintenanceRequest}/clear-escalation', [AdminMaintenanceController::class, 'clearEscalation']);
            Route::post('/maintenance/{maintenanceRequest}/notes', [AdminMaintenanceController::class, 'storeNote']);
            Route::post('/maintenance/{maintenanceRequest}/override-close', [AdminMaintenanceController::class, 'overrideClose']);
            Route::post('/maintenance/{maintenanceRequest}/override-reopen', [AdminMaintenanceController::class, 'overrideReopen']);
        });
        // Evidence photo streaming (e.g. maintenance evidence) — baseline
        // viewing privilege, same as the maintenance queue itself.
        Route::get('/media/{mediaAsset}', [MediaController::class, 'showForAdmin']);

        // Reviews moderation (Phase 8)
        Route::middleware('admin.can:moderate_reviews')->group(function () {
            Route::get('/reviews', [AdminReviewController::class, 'index']);
            Route::get('/reviews/{review}', [AdminReviewController::class, 'show']);
            Route::post('/reviews/{review}/moderate', [AdminReviewController::class, 'moderate']);
        });

        // Admin Analytics (full platform view)
        Route::middleware('admin.can:view_analytics')->prefix('analytics')->group(function () {
            Route::get('/notifications', [NotificationAnalyticsController::class, 'index']);
            Route::get('/financial', [FinancialAnalyticsController::class, 'index']);
            Route::get('/contracts', [ContractAnalyticsController::class, 'index']);
            Route::get('/platform', [PlatformAnalyticsController::class, 'index']);
            // Composite Super Admin "Platform Analytics" page (all sections in one payload).
            Route::get('/overview', [AdminAnalyticsOverviewController::class, 'overview']);
        });
    });

    // ============================================================================
    // METRICS ROUTES - Accessible to admins AND landlords
    // Platform metrics for monitoring (read-only, non-sensitive data)
    // ============================================================================
    Route::middleware(['auth:sanctum', 'admin.or.landlord', 'rate.limit.role'])->prefix('admin/metrics')->group(function () {
        Route::get('/', [MetricsController::class, 'summary']);
        Route::get('/latency', [MetricsController::class, 'latency']);
        Route::get('/errors', [MetricsController::class, 'errors']);
        Route::get('/requests', [MetricsController::class, 'requests']);
        Route::get('/queue', [MetricsController::class, 'queue']);
        Route::get('/recent', [MetricsController::class, 'recent']);
    });

    // ============================================================================
    // STRIPE WEBHOOK - Phase 3.3 (NO AUTH - signature verified in controller)
    // ============================================================================
    Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
        ->withoutMiddleware(['metrics']); // Don't track webhook metrics

    // ============================================================================
    // MEDIA STREAMING ROUTE - Phase 3 (accessible to any authenticated user)
    // Named "media.show" so MediaAsset::url accessor can generate the URL.
    // Authorization is enforced by MediaAssetPolicy inside the controller.
    // ============================================================================
    Route::middleware(['auth:sanctum', 'rate.limit.role'])
        ->get('/media/{mediaAsset}', [MediaController::class, 'show'])
        ->name('media.show');

    // Shared deletion route — a tenant needs to be able to remove their own
    // maintenance evidence too, not just landlord galleries (which also keep
    // their own /landlord/media/{mediaAsset} route above for parity).
    // Authorization is enforced by MediaAssetPolicy::delete() inside the controller.
    Route::middleware(['auth:sanctum', 'rate.limit.role'])
        ->delete('/media/{mediaAsset}', [MediaController::class, 'destroy']);

    // ============================================================================
    // NOTIFICATION ROUTES - Phase 3.5
    // Accessible to both tenants and landlords
    // ============================================================================
    Route::middleware(['auth:sanctum', 'rate.limit.role'])->prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread', [NotificationController::class, 'unread']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    });

    // ============================================================================
    // NOTIFICATION PREFERENCE ROUTES - Phase 3.8
    // Accessible to both tenants and landlords
    // ============================================================================
    Route::middleware(['auth:sanctum', 'rate.limit.role'])->group(function () {
        Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);
    });

    // ============================================================================
    // WEATHER ROUTE — accessible to any authenticated user
    // Returns {available:false} gracefully when the API key is not configured.
    // ============================================================================
    Route::middleware(['auth:sanctum', 'rate.limit.role'])
        ->get('/weather', [WeatherController::class, 'current']);

    // ============================================================================
    // USER-SCOPED ANALYTICS ROUTES - Phase 4.0
    // Tenants get their own data, landlords get their properties' data
    // Platform analytics: landlord-scoped (tenants blocked at controller level)
    // ============================================================================
    Route::middleware(['auth:sanctum', 'rate.limit.role'])->prefix('analytics')->group(function () {
        Route::get('/notifications', [NotificationAnalyticsController::class, 'index']);
        Route::get('/financial', [FinancialAnalyticsController::class, 'index']);
        Route::get('/contracts', [ContractAnalyticsController::class, 'index']);
        Route::get('/platform', [PlatformAnalyticsController::class, 'index']);
    });

}); // End metrics middleware group
