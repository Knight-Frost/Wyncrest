<?php

namespace App\Observers;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Services\AuditService;
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

        // why: getOriginal() returns the enum-cast value, so comparing it to the
        // string 'active' was always true. Compare the raw stored value.
        return $contract->status === ContractStatus::ACTIVE
            && $contract->getRawOriginal('status') !== ContractStatus::ACTIVE->value;
    }

    /**
     * Generate initial rent entry for newly activated contract
     */
    protected function generateInitialRentEntry(Contract $contract): void
    {
        try {
            $this->ledgerService->generateFirstRentEntry($contract);
        } catch (\Exception $e) {
            // Don't fail the activation, but never fail silently either
            // (forensic audit WYN-020): a critical audit row is durable,
            // visible in the admin audit log, and `ledger:reconcile` reports
            // the same contract under missing_rent_generation until staff
            // resolve it (docs/OPERATIONS.md "First rent failed").
            Log::critical('Failed to generate initial rent entry', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            try {
                app(AuditService::class)->log(
                    actor: null,
                    action: 'first_rent_generation_failed',
                    subject: $contract,
                    description: "First rent charge could not be generated when contract {$contract->id} was activated. Run `php artisan ledger:reconcile` and see docs/OPERATIONS.md.",
                    metadata: [
                        'contract_id' => $contract->id,
                        'start_date' => $contract->start_date?->toDateString(),
                        'error_class' => $e::class,
                        'error' => mb_substr($e->getMessage(), 0, 500),
                    ],
                    severity: 'critical'
                );
            } catch (\Throwable $auditFailure) {
                Log::critical('Could not record first_rent_generation_failed audit row', [
                    'contract_id' => $contract->id,
                    'error' => $auditFailure->getMessage(),
                ]);
            }
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
