<?php

namespace App\Observers;

use App\Models\LedgerEntry;
use App\Support\Cache\AnalyticsCacheInvalidator;

/**
 * LedgerEntryObserver
 * 
 * Phase 5.2: Invalidates financial analytics cache when ledger entries change.
 * Affects: Financial analytics only
 */
class LedgerEntryObserver
{
    /**
     * Handle the LedgerEntry "created" event.
     */
    public function created(LedgerEntry $entry): void
    {
        $this->invalidateFinancialAnalytics($entry);
    }

    /**
     * Handle the LedgerEntry "updated" event.
     */
    public function updated(LedgerEntry $entry): void
    {
        $this->invalidateFinancialAnalytics($entry);
    }

    /**
     * Handle the LedgerEntry "deleted" event.
     */
    public function deleted(LedgerEntry $entry): void
    {
        $this->invalidateFinancialAnalytics($entry);
    }
    
    /**
     * Invalidate financial analytics cache
     * 
     * @param LedgerEntry $entry
     * @return void
     */
    protected function invalidateFinancialAnalytics(LedgerEntry $entry): void
    {
        // Get property_id from ledger_entry -> contract -> listing -> unit -> property
        $propertyId = $entry->contract?->listing?->unit?->property_id;
        $tenantId = $entry->contract?->tenant_id;
        
        // Invalidate financial analytics
        // Scope: tenant (user_id), landlord (property_id), global (admin)
        AnalyticsCacheInvalidator::invalidate('financial', [
            'user_id' => $tenantId,
            'property_id' => $propertyId,
            'global' => true, // Also invalidate admin view
        ]);
    }
}
