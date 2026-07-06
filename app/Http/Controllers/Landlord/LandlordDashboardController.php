<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ApplicationStatus;
use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\ListingStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\UnitAvailabilityStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Ledger\LedgerComputationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordDashboardController
 *
 * Aggregates a landlord's portfolio, contract, application, maintenance and
 * financial position into a single summary. Everything is scoped to the
 * authenticated landlord and computed with aggregate queries (no N+1).
 */
class LandlordDashboardController extends Controller
{
    /**
     * Get the landlord dashboard summary.
     */
    public function index(Request $request, LedgerComputationEngine $engine): JsonResponse
    {
        $landlordId = $request->user()->id;

        // ── Portfolio ─────────────────────────────────────────────────────────
        $totalProperties = Property::where('landlord_id', $landlordId)->count();

        // Units across the landlord's properties (one grouped query).
        $unitCounts = Unit::whereHas('property', function ($q) use ($landlordId) {
            $q->where('landlord_id', $landlordId);
        })
            ->selectRaw('availability_status, COUNT(*) as aggregate')
            ->groupBy('availability_status')
            ->pluck('aggregate', 'availability_status');

        $totalUnits = (int) $unitCounts->sum();
        $occupiedUnits = (int) ($unitCounts[UnitAvailabilityStatus::OCCUPIED->value] ?? 0);
        $vacantUnits = (int) ($unitCounts[UnitAvailabilityStatus::AVAILABLE->value] ?? 0);

        // Listing counts grouped by status (one query).
        $listingCounts = Listing::where('landlord_id', $landlordId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // ── Contracts ─────────────────────────────────────────────────────────
        $contractCounts = Contract::byLandlord($landlordId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // ── Applications awaiting review (active / non-terminal states) ──────────
        $awaitingReview = Application::where('landlord_id', $landlordId)
            ->active()
            ->count();

        // ── Maintenance ───────────────────────────────────────────────────────
        $maintenanceOpen = MaintenanceRequest::where('landlord_id', $landlordId)
            ->where('status', MaintenanceStatus::OPEN)
            ->count();
        $maintenanceInProgress = MaintenanceRequest::where('landlord_id', $landlordId)
            ->where('status', MaintenanceStatus::IN_PROGRESS)
            ->count();

        // ── Ledger ────────────────────────────────────────────────────────
        // Delegated to LedgerComputationEngine — the same engine that powers
        // the landlord ledger page and admin dashboard, so this figure can
        // never disagree with what the ledger itself shows.
        $outstandingCents = $engine->computeOutstanding(['landlord_id' => $landlordId]);
        $overdueCents = $engine->computeOverdue(['landlord_id' => $landlordId]);

        // "Collected" = actual money received (sum of PAYMENT entries),
        // scoped to payments recorded within the current calendar month.
        $collectedThisMonthCents = $engine->computeCollected([
            'landlord_id' => $landlordId,
            'date_from' => now()->startOfMonth(),
            'date_to' => now()->endOfMonth(),
        ]);

        $nextDueDate = LedgerEntry::byLandlord($landlordId)
            ->where('status', LedgerStatus::PENDING)
            ->orderBy('due_date')
            ->value('due_date');

        // Active leases ending within the next 60 days.
        $expiringSoon = Contract::byLandlord($landlordId)
            ->where('status', ContractStatus::ACTIVE)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays(60)->toDateString()])
            ->count();

        // ── Rent trend (last 6 calendar months, real, for the sparklines) ─────
        // Bounded per-month sum queries keep this DB-agnostic (no strftime/DATE_FORMAT).
        $rentTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->startOfMonth()->subMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $window = [$monthStart->toDateString(), $monthEnd->toDateString()];

            $rentTrend[] = [
                'month' => $monthStart->format('M'),
                'collected_cents' => $engine->computeCollected([
                    'landlord_id' => $landlordId,
                    'date_from' => $monthStart,
                    'date_to' => $monthEnd,
                ]),
                'outstanding_cents' => (int) LedgerEntry::byLandlord($landlordId)
                    ->whereIn('status', [LedgerStatus::PENDING->value, LedgerStatus::OVERDUE->value])
                    ->whereBetween('due_date', $window)
                    ->sum('amount_cents'),
            ];
        }

        // ── Recent activity ───────────────────────────────────────────────────
        // A tenant's unsubmitted DRAFT (form_data included) is still private
        // to them — it must never surface in the landlord's activity feed.
        $recentApplications = Application::where('landlord_id', $landlordId)
            ->where('status', '!=', ApplicationStatus::DRAFT)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->load('listing.unit.property', 'tenant');

        $recentMaintenance = MaintenanceRequest::where('landlord_id', $landlordId)
            ->orderByDesc('submitted_at')
            ->limit(5)
            ->get()
            ->load('unit', 'property');

        // The landlord's own listings (real "portfolio" gallery, newest first).
        $recentListings = Listing::where('landlord_id', $landlordId)
            ->with(['unit.property', 'primaryPhoto'])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        return response()->json([
            'portfolio' => [
                'total_properties' => $totalProperties,
                'total_units' => $totalUnits,
                'occupied_units' => $occupiedUnits,
                'vacant_units' => $vacantUnits,
                'active_listings' => (int) ($listingCounts[ListingStatus::ACTIVE->value] ?? 0),
                'draft_listings' => (int) ($listingCounts[ListingStatus::DRAFT->value] ?? 0),
                'pending_review_listings' => (int) ($listingCounts[ListingStatus::PENDING_REVIEW->value] ?? 0),
            ],
            'contracts' => [
                'active' => (int) ($contractCounts[ContractStatus::ACTIVE->value] ?? 0),
                'pending_tenant' => (int) ($contractCounts[ContractStatus::PENDING_TENANT->value] ?? 0),
                'draft' => (int) ($contractCounts[ContractStatus::DRAFT->value] ?? 0),
                'expiring_soon' => $expiringSoon,
            ],
            'applications' => [
                'awaiting_review' => $awaitingReview,
            ],
            'maintenance' => [
                'open' => $maintenanceOpen,
                'in_progress' => $maintenanceInProgress,
            ],
            'ledger' => [
                'outstanding_cents' => $outstandingCents,
                'overdue_cents' => $overdueCents,
                'collected_this_month_cents' => $collectedThisMonthCents,
                'next_due_date' => $nextDueDate?->format('Y-m-d'),
            ],
            'rent_trend' => $rentTrend,
            'recent_applications' => $recentApplications,
            'recent_maintenance' => $recentMaintenance,
            'recent_listings' => $recentListings,
        ]);
    }
}
