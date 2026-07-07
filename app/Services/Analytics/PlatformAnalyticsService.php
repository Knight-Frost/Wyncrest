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
        $unitsQuery = $this->scopeUnits(Unit::query(), $filters);

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
        $propertyQuery = Property::query();
        if (isset($filters['property_id'])) {
            $propertyQuery->where('id', $filters['property_id']);
        }
        if (isset($filters['landlord_id'])) {
            $propertyQuery->where('landlord_id', $filters['landlord_id']);
        }

        $totalProperties = $propertyQuery->count();

        $totalUnits = $this->scopeUnits(Unit::query(), $filters)->count();

        $growth = [
            'total_properties' => $totalProperties,
            'total_units' => $totalUnits,
        ];

        // why: platform-wide user counts are a super-admin figure. A landlord-
        // scoped caller must NOT receive the platform's total user count or the
        // tenant/landlord role split — that is neither their data nor a truthful
        // "their portfolio" number. Only the unscoped (admin) view exposes it.
        if (! isset($filters['landlord_id'])) {
            $growth = [
                'total_users' => User::count(),
                'users_by_role' => User::select('user_type', DB::raw('COUNT(*) as count'))
                    ->groupBy('user_type')
                    ->get()
                    ->mapWithKeys(function ($item) {
                        $roleValue = $item->user_type instanceof \UnitEnum
                            ? $item->user_type->value
                            : $item->user_type;

                        return [$roleValue => $item->count];
                    })
                    ->toArray(),
                ...$growth,
            ];
        }

        return $growth;
    }

    public function getUtilizationMetrics(array $filters = []): array
    {
        $listingQuery = $this->scopeListings(Listing::query(), $filters);

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
            ->when(isset($filters['landlord_id']), function ($q) use ($filters) {
                $q->where('landlord_id', $filters['landlord_id']);
            })
            ->count();

        // why: a listing can produce more than one contract over its lifetime
        // (renewals/re-lets), and the date/property/landlord scoping between
        // $listingQuery and $contractsFromListings isn't identical, so the
        // raw ratio can mathematically exceed 100%. Conversion is conceptually
        // capped at 100% — clamp rather than show an impossible rate.
        $conversionRate = $totalListings > 0
            ? min(100.0, round(($contractsFromListings / $totalListings) * 100, 2))
            : 0.0;

        return [
            'total_listings' => $totalListings,
            'active_listings' => $activeListings,
            'listing_to_contract_conversion_rate' => $conversionRate,
        ];
    }

    /**
     * Scope a Unit query to the requested property and/or landlord. property_id
     * is the specific unit's property; landlord_id constrains to every property
     * the landlord owns (via the property relation). Both may be present (the
     * intersection is still correct); an admin passes neither → platform-wide.
     */
    protected function scopeUnits($query, array $filters)
    {
        if (isset($filters['property_id'])) {
            $query->where('property_id', $filters['property_id']);
        }

        if (isset($filters['landlord_id'])) {
            $query->whereHas('property', function ($q) use ($filters) {
                $q->where('landlord_id', $filters['landlord_id']);
            });
        }

        return $query;
    }

    /**
     * Scope a Listing query to the requested property and/or landlord via
     * listing → unit → property.
     */
    protected function scopeListings($query, array $filters)
    {
        if (isset($filters['property_id'])) {
            $query->whereHas('unit', function ($q) use ($filters) {
                $q->where('property_id', $filters['property_id']);
            });
        }

        if (isset($filters['landlord_id'])) {
            $query->whereHas('unit.property', function ($q) use ($filters) {
                $q->where('landlord_id', $filters['landlord_id']);
            });
        }

        return $query;
    }

    protected function getAverageVacancyDuration(array $filters = []): float
    {
        // Get vacant units (units without active contracts)
        $vacantUnits = $this->scopeUnits(Unit::query(), $filters)
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
