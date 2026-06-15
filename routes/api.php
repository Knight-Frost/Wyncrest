<?php

use App\Http\Controllers\Admin\AdminAuditController;
// ============================================================================
// AUTHENTICATION CONTROLLER
// ============================================================================
use App\Http\Controllers\Admin\AdminContractController;
// ============================================================================
// PUBLIC CONTROLLERS
// ============================================================================
use App\Http\Controllers\Admin\AdminDashboardController;
// ============================================================================
// TENANT CONTROLLERS
// ============================================================================
use App\Http\Controllers\Admin\AdminFeatureController;
use App\Http\Controllers\Admin\AdminLedgerController;
use App\Http\Controllers\Admin\AdminListingModerationController;
use App\Http\Controllers\Analytics\ContractAnalyticsController;
use App\Http\Controllers\Analytics\FinancialAnalyticsController;
// ============================================================================
// LANDLORD CONTROLLERS
// ============================================================================
use App\Http\Controllers\Analytics\NotificationAnalyticsController;
use App\Http\Controllers\Analytics\PlatformAnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Landlord\LandlordContractController;
use App\Http\Controllers\Landlord\LandlordLedgerController;
use App\Http\Controllers\Landlord\LandlordListingController;
// ============================================================================
// ADMIN CONTROLLERS
// ============================================================================
use App\Http\Controllers\Landlord\LandlordOnboardingController;
use App\Http\Controllers\Landlord\PropertyController;
use App\Http\Controllers\Landlord\UnitController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\Public\PublicListingController;
// ============================================================================
// WEBHOOK CONTROLLERS
// ============================================================================
use App\Http\Controllers\StripeWebhookController;
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
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Nexus
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
    });

    // ============================================================================
    // LANDLORD ROUTES
    // ============================================================================
    Route::middleware(['auth:sanctum', 'landlord', 'rate.limit.role'])->prefix('landlord')->group(function () {
        // Onboarding
        Route::get('/onboarding', [LandlordOnboardingController::class, 'index']);

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

        // Listings
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

        // Ledger (Phase 3.2)
        Route::get('/ledger', [LandlordLedgerController::class, 'index']);
        Route::get('/ledger/{ledgerEntry}', [LandlordLedgerController::class, 'show']);

        // Analytics (scoped to landlord's properties)
        Route::get('/analytics/financial', [FinancialAnalyticsController::class, 'index']);
        Route::get('/analytics/contracts', [ContractAnalyticsController::class, 'index']);
    });

    // ============================================================================
    // ADMIN ROUTES - Protected with admin middleware
    // ============================================================================
    Route::middleware(['auth:sanctum', 'admin', 'rate.limit.role'])->prefix('admin')->group(function () {
        // Dashboard
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        // Listing Moderation
        Route::get('/listings/pending', [AdminListingModerationController::class, 'pending']);
        Route::post('/listings/{listing}/approve', [AdminListingModerationController::class, 'approve']);
        Route::post('/listings/{listing}/reject', [AdminListingModerationController::class, 'reject']);

        // Feature Management
        Route::get('/landlords/{landlord}/features', [AdminFeatureController::class, 'index']);
        Route::post('/landlords/{landlord}/features/{feature}/enable', [AdminFeatureController::class, 'enable']);
        Route::post('/landlords/{landlord}/features/{feature}/disable', [AdminFeatureController::class, 'disable']);

        // Audit Logs
        Route::get('/audit-logs', [AdminAuditController::class, 'index']);
        Route::get('/audit-logs/{auditLog}', [AdminAuditController::class, 'show']);

        // Contracts (Phase 3.1)
        Route::get('/contracts', [AdminContractController::class, 'index']);
        Route::get('/contracts/{contract}', [AdminContractController::class, 'show']);
        Route::post('/contracts/{contract}/terminate', [AdminContractController::class, 'terminate']);

        // Ledger (Phase 3.2)
        Route::get('/ledger', [AdminLedgerController::class, 'index']);
        Route::get('/ledger/{ledgerEntry}', [AdminLedgerController::class, 'show']);
        Route::post('/ledger/{ledgerEntry}/late-fee', [AdminLedgerController::class, 'generateLateFee']);

        // Admin Analytics (full platform view)
        Route::prefix('analytics')->group(function () {
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
