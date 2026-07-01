<?php

use App\Http\Controllers\Admin\AdminAccessController;
use App\Http\Controllers\Admin\AdminAuditController;
// ============================================================================
// AUTHENTICATION CONTROLLER
// ============================================================================
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
use App\Http\Controllers\Admin\AdminReviewController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminVerificationController;
use App\Http\Controllers\Analytics\ContractAnalyticsController;
use App\Http\Controllers\Analytics\FinancialAnalyticsController;
use App\Http\Controllers\Analytics\NotificationAnalyticsController;
use App\Http\Controllers\Analytics\PlatformAnalyticsController;
use App\Http\Controllers\Auth\AdminInviteController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\AuthController;
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

// Authenticated routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    // Self-service password change (User or Admin) — audited, revokes other sessions.
    Route::post('/user/password', [AuthController::class, 'changePassword']);
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

        // Applications
        Route::get('/applications', [ApplicationController::class, 'index']);
        Route::post('/applications', [ApplicationController::class, 'store']);
        Route::get('/applications/{application}', [ApplicationController::class, 'show']);
        Route::post('/applications/{application}/withdraw', [ApplicationController::class, 'withdraw']);

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

        // Properties
        Route::get('/properties', [PropertyController::class, 'index']);
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::get('/properties/{property}', [PropertyController::class, 'show']);
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
        Route::delete('/listings/{listing}', [LandlordListingController::class, 'destroy']);

        // Contracts (Phase 3.1)
        Route::get('/contracts', [LandlordContractController::class, 'index']);
        Route::post('/contracts', [LandlordContractController::class, 'store']);
        Route::get('/contracts/{contract}', [LandlordContractController::class, 'show']);
        Route::post('/contracts/{contract}/send', [LandlordContractController::class, 'send']);
        Route::post('/contracts/{contract}/terminate', [LandlordContractController::class, 'terminate']);

        // Ledger (Phase 3.2) — static export path MUST come before the {ledgerEntry} wildcard
        Route::get('/ledger/export', [LandlordExportController::class, 'ledger']);
        Route::get('/ledger', [LandlordLedgerController::class, 'index']);
        Route::get('/ledger/{ledgerEntry}', [LandlordLedgerController::class, 'show']);

        // Applications — static export path MUST come before the {application} wildcard
        Route::get('/applications/export', [LandlordExportController::class, 'applications']);
        Route::get('/applications', [LandlordApplicationController::class, 'index']);
        Route::get('/applications/{application}', [LandlordApplicationController::class, 'show']);
        Route::post('/applications/{application}/decide', [LandlordApplicationController::class, 'decide']);

        // Maintenance Requests
        Route::get('/maintenance', [LandlordMaintenanceController::class, 'index']);
        Route::patch('/maintenance/{maintenanceRequest}/status', [LandlordMaintenanceController::class, 'updateStatus']);

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
        Route::get('/analytics/financial', [FinancialAnalyticsController::class, 'index']);
        Route::get('/analytics/contracts', [ContractAnalyticsController::class, 'index']);

        // Reviews (Phase 8)
        Route::get('/reviews', [LandlordReviewController::class, 'index']);
        Route::post('/reviews/{review}/respond', [LandlordReviewController::class, 'respond']);
    });

    // ============================================================================
    // ADMIN ROUTES - Protected with admin middleware
    // ============================================================================
    Route::middleware(['auth:sanctum', 'admin', 'rate.limit.role'])->prefix('admin')->group(function () {
        // Dashboard — available to any active admin (no specific capability).
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

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

        // User Management
        Route::middleware('admin.can:manage_users')->group(function () {
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/{user}', [AdminUserController::class, 'show']);
            Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend']);
            Route::post('/users/{user}/activate', [AdminUserController::class, 'activate']);
            Route::post('/users/{user}/block', [AdminUserController::class, 'block']);
            Route::post('/users/{user}/archive', [AdminUserController::class, 'archive']);
        });

        // Verification Management (Phase 4)
        Route::middleware('admin.can:review_verifications')->group(function () {
            Route::get('/verifications', [AdminVerificationController::class, 'index']);
            Route::get('/verifications/{verificationRequest}', [AdminVerificationController::class, 'show']);
            Route::post('/verifications/{verificationRequest}/approve', [AdminVerificationController::class, 'approve']);
            Route::post('/verifications/{verificationRequest}/reject', [AdminVerificationController::class, 'reject']);
            Route::post('/verifications/{verificationRequest}/request-info', [AdminVerificationController::class, 'requestInfo']);
            // Stream applicant documents during moderation (admin-gated + audited).
            Route::get('/documents/{document}/download', [AdminVerificationController::class, 'downloadDocument']);
        });

        // Listing Moderation
        Route::middleware('admin.can:moderate_listings')->group(function () {
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
        });

        // Contracts (Phase 3.1)
        Route::middleware('admin.can:manage_contracts')->group(function () {
            Route::get('/contracts', [AdminContractController::class, 'index']);
            Route::get('/contracts/{contract}', [AdminContractController::class, 'show']);
            Route::post('/contracts/{contract}/terminate', [AdminContractController::class, 'terminate']);
        });

        // Ledger (Phase 3.2)
        Route::middleware('admin.can:manage_ledger')->group(function () {
            Route::get('/ledger', [AdminLedgerController::class, 'index']);
            Route::get('/ledger/{ledgerEntry}', [AdminLedgerController::class, 'show']);
            Route::post('/ledger/{ledgerEntry}/late-fee', [AdminLedgerController::class, 'generateLateFee']);
        });

        // Reviews moderation (Phase 8)
        Route::middleware('admin.can:moderate_reviews')->group(function () {
            Route::get('/reviews', [AdminReviewController::class, 'index']);
            Route::post('/reviews/{review}/moderate', [AdminReviewController::class, 'moderate']);
        });

        // Admin Analytics (full platform view)
        Route::middleware('admin.can:view_analytics')->prefix('analytics')->group(function () {
            Route::get('/notifications', [NotificationAnalyticsController::class, 'index']);
            Route::get('/financial', [FinancialAnalyticsController::class, 'index']);
            Route::get('/contracts', [ContractAnalyticsController::class, 'index']);
            Route::get('/platform', [PlatformAnalyticsController::class, 'index']);
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
