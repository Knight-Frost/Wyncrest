<?php

namespace App\Services\Analytics;

use App\Models\Contract;
use App\Enums\ContractStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ContractAnalyticsService
 * 
 * Phase 4.0c: Read-only contract lifecycle analytics
 * 
 * Provides insights into:
 * - Contract health and status distribution
 * - Contract duration and lifecycle metrics
 * - Early termination and renewal patterns
 */
class ContractAnalyticsService
{
    /**
     * Get comprehensive contract analytics
     * 
     * @param array $filters ['start_date' => Carbon, 'end_date' => Carbon, 'property_id' => int, 'user_id' => int]
     * @return array
     */
    public function getAnalytics(array $filters = []): array
    {
        return [
            'total_contracts' => $this->getTotalContracts($filters),
            'active_contracts' => $this->getActiveContracts($filters),
            'terminated_contracts' => $this->getTerminatedContracts($filters),
            'expired_contracts' => $this->getExpiredContracts($filters),
            'contracts_by_status' => $this->getContractsByStatus($filters),
            'average_contract_duration_days' => $this->getAverageContractDuration($filters),
            'early_termination_rate' => $this->getEarlyTerminationRate($filters),
            'renewal_rate' => $this->getRenewalRate($filters),
        ];
    }

    /**
     * Get total contracts count
     */
    protected function getTotalContracts(array $filters = []): int
    {
        $query = Contract::query();
        $this->applyFilters($query, $filters);
        
        return $query->count();
    }

    /**
     * Get active contracts count
     */
    protected function getActiveContracts(array $filters = []): int
    {
        $query = Contract::query()
            ->where('status', ContractStatus::ACTIVE);
        
        $this->applyFilters($query, $filters);
        
        return $query->count();
    }

    /**
     * Get terminated contracts count
     */
    protected function getTerminatedContracts(array $filters = []): int
    {
        $query = Contract::query()
            ->where('status', ContractStatus::TERMINATED);
        
        $this->applyFilters($query, $filters);
        
        return $query->count();
    }

    /**
     * Get expired contracts count
     */
    protected function getExpiredContracts(array $filters = []): int
    {
        $query = Contract::query()
            ->where('status', ContractStatus::EXPIRED);
        
        $this->applyFilters($query, $filters);
        
        return $query->count();
    }

    /**
     * Get contracts grouped by status
     */
    protected function getContractsByStatus(array $filters = []): array
    {
        $query = Contract::query();
        $this->applyFilters($query, $filters);
        
        $results = $query->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();
        
        $output = [];
        foreach ($results as $result) {
            $statusValue = $result->status instanceof ContractStatus 
                ? $result->status->value 
                : $result->status;
            $output[$statusValue] = $result->count;
        }
        
        return $output;
    }

    /**
     * Get average contract duration in days
     */
    protected function getAverageContractDuration(array $filters = []): float
    {
        $query = Contract::query()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');
        
        $this->applyFilters($query, $filters);
        
        $contracts = $query->get();
        
        if ($contracts->isEmpty()) {
            return 0.0;
        }
        
        $totalDays = 0;
        foreach ($contracts as $contract) {
            $totalDays += $contract->start_date->diffInDays($contract->end_date);
        }
        
        return round($totalDays / $contracts->count(), 2);
    }

    /**
     * Get early termination rate
     * 
     * Early termination = contract ended before expected end date
     */
    protected function getEarlyTerminationRate(array $filters = []): float
    {
        $query = Contract::query();
        $this->applyFilters($query, $filters);
        
        $totalContracts = (clone $query)->count();
        
        if ($totalContracts === 0) {
            return 0.0;
        }
        
        // Terminated contracts are those that ended early
        $earlyTerminated = (clone $query)
            ->where('status', ContractStatus::TERMINATED)
            ->count();
        
        return round(($earlyTerminated / $totalContracts) * 100, 2);
    }

    /**
     * Get renewal rate
     * 
     * Renewal = tenant signs new contract after prior contract expired
     * We detect this by finding tenants with multiple contracts where:
     * - First contract is expired/terminated
     * - Second contract started after first ended
     */
    protected function getRenewalRate(array $filters = []): float
    {
        $query = Contract::query();
        $this->applyFilters($query, $filters);
        
        // Get all completed contracts (expired or terminated)
        $completedContracts = (clone $query)
            ->whereIn('status', [ContractStatus::EXPIRED, ContractStatus::TERMINATED])
            ->count();
        
        if ($completedContracts === 0) {
            return 0.0;
        }
        
        // Find tenants who have multiple contracts
        $renewals = (clone $query)
            ->select('tenant_id', DB::raw('COUNT(*) as contract_count'))
            ->groupBy('tenant_id')
            ->having('contract_count', '>', 1)
            ->get()
            ->sum(function ($item) {
                // Each tenant with N contracts has N-1 renewals
                return $item->contract_count - 1;
            });
        
        return round(($renewals / $completedContracts) * 100, 2);
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        
        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }
        
        if (isset($filters['user_id'])) {
            // User filter applies to tenant
            $query->where('tenant_id', $filters['user_id']);
        }
        
        if (isset($filters['property_id'])) {
            // Property filter via listing → unit → property
            $query->whereHas('listing.unit', function ($q) use ($filters) {
                $q->where('property_id', $filters['property_id']);
            });
        }
    }
}
