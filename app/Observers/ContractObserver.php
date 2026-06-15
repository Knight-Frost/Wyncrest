<?php

namespace App\Observers;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Services\LedgerService;
use App\Support\Cache\AnalyticsCacheInvalidator;
use Illuminate\Support\Facades\Log;

/**
 * ContractObserver
 *
 * Phase 5.2: Invalidates analytics cache when contracts change.
 * Also handles automatic rent generation when contracts become active.
 *
 * Affects: Contract analytics, Platform analytics
 */
class ContractObserver
{
    protected LedgerService $ledgerService;

    public function __construct(LedgerService $ledgerService)
    {
        $this->ledgerService = $ledgerService;
    }

    /**
     * Handle the Contract "created" event.
     */
    public function created(Contract $contract): void
    {
        $this->invalidateAnalytics($contract);
    }

    /**
     * Handle the Contract "updated" event.
     */
    public function updated(Contract $contract): void
    {
        // Check if contract was just activated
        if ($this->wasJustActivated($contract)) {
            $this->generateInitialRentEntry($contract);
        }

        $this->invalidateAnalytics($contract);
    }

    /**
     * Handle the Contract "deleted" event.
     */
    public function deleted(Contract $contract): void
    {
        $this->invalidateAnalytics($contract);
    }

    /**
     * Check if contract was just activated
     */
    protected function wasJustActivated(Contract $contract): bool
    {
        // Check if status changed to ACTIVE
        if (! $contract->isDirty('status')) {
            return false;
        }

        return $contract->status === ContractStatus::ACTIVE
            && $contract->getOriginal('status') !== ContractStatus::ACTIVE->value;
    }

    /**
     * Generate initial rent entry for newly activated contract
     */
    protected function generateInitialRentEntry(Contract $contract): void
    {
        try {
            $this->ledgerService->generateFirstRentEntry($contract);
        } catch (\Exception $e) {
            // Log but don't fail the contract activation
            Log::error('Failed to generate initial rent entry', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate relevant analytics caches
     */
    protected function invalidateAnalytics(Contract $contract): void
    {
        // Get property_id from contract -> listing -> unit -> property
        $propertyId = $contract->listing?->unit?->property_id;

        // Invalidate contract analytics
        // Scope: tenant (user_id), landlord (property_id), global (admin)
        AnalyticsCacheInvalidator::invalidate('contracts', [
            'user_id' => $contract->tenant_id,
            'property_id' => $propertyId,
            'global' => true, // Also invalidate admin view
        ]);

        // Invalidate platform analytics (affected by occupancy changes)
        AnalyticsCacheInvalidator::invalidate('platform', [
            'property_id' => $propertyId,
            'global' => true, // Platform metrics are global
        ]);
    }
}
