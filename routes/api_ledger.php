<?php

use App\Http\Controllers\Admin\AdminLedgerController;
use App\Http\Controllers\Landlord\LandlordLedgerController;
use App\Http\Controllers\Tenant\TenantLedgerController;

/*
|--------------------------------------------------------------------------
| Ledger Routes - Phase 3.2
|--------------------------------------------------------------------------
*/

// ============================================================================
// TENANT LEDGER ROUTES (auth:sanctum + tenant middleware)
// ============================================================================

Route::middleware(['auth:sanctum', 'tenant'])->prefix('tenant')->group(function () {
    // Ledger (read-only)
    Route::get('/ledger', [TenantLedgerController::class, 'index'])->name('tenant.ledger.index');
    Route::get('/ledger/{ledgerEntry}', [TenantLedgerController::class, 'show'])->name('tenant.ledger.show');
});

// ============================================================================
// LANDLORD LEDGER ROUTES (auth:sanctum + landlord middleware)
// ============================================================================

Route::middleware(['auth:sanctum', 'landlord'])->prefix('landlord')->group(function () {
    // Ledger (read-only)
    Route::get('/ledger', [LandlordLedgerController::class, 'index'])->name('landlord.ledger.index');
    Route::get('/ledger/{ledgerEntry}', [LandlordLedgerController::class, 'show'])->name('landlord.ledger.show');
});

// ============================================================================
// ADMIN LEDGER ROUTES (auth:sanctum,admin guard)
// ============================================================================

Route::middleware(['auth:sanctum,admin'])->prefix('admin')->group(function () {
    // Ledger
    Route::get('/ledger', [AdminLedgerController::class, 'index'])->name('admin.ledger.index');
    Route::get('/ledger/{ledgerEntry}', [AdminLedgerController::class, 'show'])->name('admin.ledger.show');
    Route::post('/ledger/{ledgerEntry}/late-fee', [AdminLedgerController::class, 'generateLateFee'])->name('admin.ledger.late-fee');
});
