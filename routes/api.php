<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Public\PublicListingController;
use App\Http\Controllers\Tenant\TenantDashboardController;
use App\Http\Controllers\Tenant\SavedListingController;
use App\Http\Controllers\Landlord\LandlordOnboardingController;
use App\Http\Controllers\Landlord\PropertyController;
use App\Http\Controllers\Landlord\UnitController;
use App\Http\Controllers\Landlord\LandlordListingController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminListingModerationController;
use App\Http\Controllers\Admin\AdminFeatureController;
use App\Http\Controllers\Admin\AdminAuditController;

/*
|--------------------------------------------------------------------------
| API Routes - Nexus Phase 2
|--------------------------------------------------------------------------
|
| Strict role separation enforced via middleware.
| - Public: No authentication
| - Tenant: auth:sanctum + tenant middleware
| - Landlord: auth:sanctum + landlord middleware
| - Admin: auth:sanctum,admin guard
|
*/

// ============================================================================
// PUBLIC ROUTES (No Authentication)
// ============================================================================

Route::prefix('listings')->group(function () {
    Route::get('/', [PublicListingController::class, 'index'])->name('listings.index');
    Route::get('/featured', [PublicListingController::class, 'featured'])->name('listings.featured');
    Route::get('/{id}', [PublicListingController::class, 'show'])->name('listings.show');
});

// ============================================================================
// TENANT ROUTES (auth:sanctum + tenant middleware)
// ============================================================================

Route::middleware(['auth:sanctum', 'tenant'])->prefix('tenant')->group(function () {
    
    // Dashboard
    Route::get('/dashboard', [TenantDashboardController::class, 'index'])->name('tenant.dashboard');
    
    // Saved Listings
    Route::get('/saved-listings', [SavedListingController::class, 'index'])->name('tenant.saved-listings.index');
    Route::post('/listings/{listing}/save', [SavedListingController::class, 'store'])->name('tenant.listings.save');
    Route::delete('/listings/{listing}/save', [SavedListingController::class, 'destroy'])->name('tenant.listings.unsave');
});

// ============================================================================
// LANDLORD ROUTES (auth:sanctum + landlord middleware)
// ============================================================================

Route::middleware(['auth:sanctum', 'landlord'])->prefix('landlord')->group(function () {
    
    // Onboarding
    Route::get('/onboarding', [LandlordOnboardingController::class, 'index'])->name('landlord.onboarding');
    
    // Properties
    Route::get('/properties', [PropertyController::class, 'index'])->name('landlord.properties.index');
    Route::post('/properties', [PropertyController::class, 'store'])->name('landlord.properties.store');
    Route::get('/properties/{property}', [PropertyController::class, 'show'])->name('landlord.properties.show');
    Route::put('/properties/{property}', [PropertyController::class, 'update'])->name('landlord.properties.update');
    Route::delete('/properties/{property}', [PropertyController::class, 'destroy'])->name('landlord.properties.destroy');
    
    // Units
    Route::get('/units', [UnitController::class, 'index'])->name('landlord.units.index');
    Route::post('/properties/{property}/units', [UnitController::class, 'store'])->name('landlord.units.store');
    Route::get('/units/{unit}', [UnitController::class, 'show'])->name('landlord.units.show');
    Route::put('/units/{unit}', [UnitController::class, 'update'])->name('landlord.units.update');
    Route::delete('/units/{unit}', [UnitController::class, 'destroy'])->name('landlord.units.destroy');
    
    // Listings
    Route::get('/listings', [LandlordListingController::class, 'index'])->name('landlord.listings.index');
    Route::post('/units/{unit}/listings', [LandlordListingController::class, 'store'])->name('landlord.listings.store');
    Route::get('/listings/{listing}', [LandlordListingController::class, 'show'])->name('landlord.listings.show');
    Route::put('/listings/{listing}', [LandlordListingController::class, 'update'])->name('landlord.listings.update');
    Route::post('/listings/{listing}/submit', [LandlordListingController::class, 'submit'])->name('landlord.listings.submit');
    Route::delete('/listings/{listing}', [LandlordListingController::class, 'destroy'])->name('landlord.listings.destroy');
});

// ============================================================================
// ADMIN ROUTES (auth:sanctum,admin guard)
// ============================================================================

Route::middleware(['auth:sanctum,admin'])->prefix('admin')->group(function () {
    
    // Dashboard
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    
    // Listing Moderation
    Route::get('/listings/pending', [AdminListingModerationController::class, 'pending'])->name('admin.listings.pending');
    Route::post('/listings/{listing}/approve', [AdminListingModerationController::class, 'approve'])->name('admin.listings.approve');
    Route::post('/listings/{listing}/reject', [AdminListingModerationController::class, 'reject'])->name('admin.listings.reject');
    
    // Feature Management
    Route::get('/landlords/{landlord}/features', [AdminFeatureController::class, 'index'])->name('admin.landlords.features');
    Route::post('/landlords/{landlord}/features/{feature}/enable', [AdminFeatureController::class, 'enable'])->name('admin.features.enable');
    Route::post('/landlords/{landlord}/features/{feature}/disable', [AdminFeatureController::class, 'disable'])->name('admin.features.disable');
    
    // Audit Logs
    Route::get('/audit-logs', [AdminAuditController::class, 'index'])->name('admin.audit-logs.index');
    Route::get('/audit-logs/{auditLog}', [AdminAuditController::class, 'show'])->name('admin.audit-logs.show');
});
