<?php

namespace App\Services\Analytics;

use App\Enums\ContractStatus;
use App\Enums\ListingStatus;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PlatformAnalyticsService
 *
 * Phase 4.0c: Read-only platform health analytics
 */
class PlatformAnalyticsService
{
    public function getAnalytics(array $filters = []): array
    {
        return [
            'occupancy' => $this->getOccupancyMetrics($filters),
            'growth' => $this->getGrowthMetrics($filters),
            'utilization' => $this->getUtilizationMetrics($filters),
        ];
    }

    public function getOccupancyMetrics(array $filters = []): array
    {
        $unitsQuery = Unit::query();

        if (isset($filters['property_id'])) {
            $unitsQuery->where('property_id', $filters['property_id']);
        }

        $totalUnits = $unitsQuery->count();

        // Units are occupied if they have a listing with an active contract
        $occupiedUnits = (clone $unitsQuery)
            ->whereHas('listings', function ($listingQuery) {
                $listingQuery->whereIn('id', function ($subQuery) {
                    $subQuery->select('listing_id')
                        ->from('contracts')
                        ->where('status', ContractStatus::ACTIVE);
                });
            })
            ->count();

        $vacantUnits = $totalUnits - $occupiedUnits;

        $occupancyRate = $totalUnits > 0
            ? round(($occupiedUnits / $totalUnits) * 100, 2)
            : 0.0;

        $averageVacancyDuration = $this->getAverageVacancyDuration($filters);

        return [
            'total_units' => $totalUnits,
            'occupied_units' => $occupiedUnits,
            'vacant_units' => $vacantUnits,
            'occupancy_rate_percentage' => $occupancyRate,
            'average_vacancy_duration_days' => $averageVacancyDuration,
        ];
    }

    public function getGrowthMetrics(array $filters = []): array
    {
        $usersByRole = User::select('user_type', DB::raw('COUNT(*) as count'))
            ->groupBy('user_type')
            ->get()
            ->mapWithKeys(function ($item) {
                $roleValue = $item->user_type instanceof \UnitEnum
                    ? $item->user_type->value
                    : $item->user_type;

                return [$roleValue => $item->count];
            })
            ->toArray();

        $propertyQuery = Property::query();
        if (isset($filters['property_id'])) {
            $propertyQuery->where('id', $filters['property_id']);
        }

        $totalProperties = $propertyQuery->count();

        $unitQuery = Unit::query();
        if (isset($filters['property_id'])) {
            $unitQuery->where('property_id', $filters['property_id']);
        }

        $totalUnits = $unitQuery->count();

        return [
            'total_users' => User::count(),
            'users_by_role' => $usersByRole,
            'total_properties' => $totalProperties,
            'total_units' => $totalUnits,
        ];
    }

    public function getUtilizationMetrics(array $filters = []): array
    {
        $listingQuery = Listing::query();

        if (isset($filters['property_id'])) {
            $listingQuery->whereHas('unit', function ($q) use ($filters) {
                $q->where('property_id', $filters['property_id']);
            });
        }

        $totalListings = $listingQuery->count();

        $activeListings = (clone $listingQuery)
            ->where('status', ListingStatus::ACTIVE)
            ->count();

        // Count contracts - check listing_id exists in contracts table
        $contractsFromListings = Contract::query()
            ->when(isset($filters['property_id']), function ($q) use ($filters) {
                $q->whereHas('listing.unit', function ($subQ) use ($filters) {
                    $subQ->where('property_id', $filters['property_id']);
                });
            })
            ->count();

        $conversionRate = $totalListings > 0
            ? round(($contractsFromListings / $totalListings) * 100, 2)
            : 0.0;

        return [
            'total_listings' => $totalListings,
            'active_listings' => $activeListings,
            'listing_to_contract_conversion_rate' => $conversionRate,
        ];
    }

    protected function getAverageVacancyDuration(array $filters = []): float
    {
        $unitsQuery = Unit::query();

        if (isset($filters['property_id'])) {
            $unitsQuery->where('property_id', $filters['property_id']);
        }

        // Get vacant units (units without active contracts)
        $vacantUnits = $unitsQuery
            ->whereDoesntHave('listings', function ($listingQuery) {
                $listingQuery->whereIn('id', function ($subQuery) {
                    $subQuery->select('listing_id')
                        ->from('contracts')
                        ->where('status', ContractStatus::ACTIVE);
                });
            })
            ->with(['listings' => function ($q) {
                $q->select('id', 'unit_id');
            }])
            ->get();

        if ($vacantUnits->isEmpty()) {
            return 0.0;
        }

        $totalDays = 0;
        $countedUnits = 0;

        foreach ($vacantUnits as $unit) {
            // Find the most recent ended contract for any of this unit's listings
            $listingIds = $unit->listings->pluck('id')->toArray();

            if (empty($listingIds)) {
                continue;
            }

            $lastContract = Contract::whereIn('listing_id', $listingIds)
                ->whereIn('status', [ContractStatus::EXPIRED, ContractStatus::TERMINATED])
                ->orderBy('end_date', 'desc')
                ->first();

            if ($lastContract && $lastContract->end_date) {
                $totalDays += $lastContract->end_date->diffInDays(now());
                $countedUnits++;
            }
        }

        return $countedUnits > 0
            ? round($totalDays / $countedUnits, 2)
            : 0.0;
    }
}
