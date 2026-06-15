<?php

use App\Http\Controllers\Admin\AdminContractController;
use App\Http\Controllers\Landlord\LandlordContractController;
use App\Http\Controllers\Tenant\TenantContractController;

/*
|--------------------------------------------------------------------------
| Contract Routes - Phase 3.1
|--------------------------------------------------------------------------
*/

// ============================================================================
// LANDLORD CONTRACT ROUTES (auth:sanctum + landlord middleware)
// ============================================================================

Route::middleware(['auth:sanctum', 'landlord'])->prefix('landlord')->group(function () {
    // Contracts
    Route::get('/contracts', [LandlordContractController::class, 'index'])->name('landlord.contracts.index');
    Route::post('/contracts', [LandlordContractController::class, 'store'])->name('landlord.contracts.store');
    Route::get('/contracts/{contract}', [LandlordContractController::class, 'show'])->name('landlord.contracts.show');
    Route::post('/contracts/{contract}/send', [LandlordContractController::class, 'send'])->name('landlord.contracts.send');
    Route::post('/contracts/{contract}/terminate', [LandlordContractController::class, 'terminate'])->name('landlord.contracts.terminate');
});

// ============================================================================
// TENANT CONTRACT ROUTES (auth:sanctum + tenant middleware)
// ============================================================================

Route::middleware(['auth:sanctum', 'tenant'])->prefix('tenant')->group(function () {
    // Contracts
    Route::get('/contracts', [TenantContractController::class, 'index'])->name('tenant.contracts.index');
    Route::get('/contracts/{contract}', [TenantContractController::class, 'show'])->name('tenant.contracts.show');
    Route::post('/contracts/{contract}/accept', [TenantContractController::class, 'accept'])->name('tenant.contracts.accept');
    Route::post('/contracts/{contract}/terminate', [TenantContractController::class, 'terminate'])->name('tenant.contracts.terminate');
});

// ============================================================================
// ADMIN CONTRACT ROUTES (auth:sanctum,admin guard)
// ============================================================================

Route::middleware(['auth:sanctum,admin'])->prefix('admin')->group(function () {
    // Contracts
    Route::get('/contracts', [AdminContractController::class, 'index'])->name('admin.contracts.index');
    Route::get('/contracts/{contract}', [AdminContractController::class, 'show'])->name('admin.contracts.show');
    Route::post('/contracts/{contract}/terminate', [AdminContractController::class, 'terminate'])->name('admin.contracts.terminate');
});
