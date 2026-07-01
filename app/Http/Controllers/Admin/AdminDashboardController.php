<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Enums\ListingStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * AdminDashboardController
 *
 * Provides the platform command-center overview: headline statistics,
 * contract lifecycle distribution, platform-wide ledger health, listing
 * inventory by status, and the newest listings. Everything is computed with
 * grouped aggregate queries (no collections loaded just to be counted).
 */
class AdminDashboardController extends Controller
{
    /**
     * Get admin dashboard data.
     */
    public function index(): JsonResponse
    {
        // ── Headline counts ───────────────────────────────────────────────────
        $landlordCount = User::landlords()->count();
        $tenantCount = User::tenants()->count();
        $propertyCount = Property::count();
        $unitCount = Unit::count();

        // ── Listings grouped by status (one query) ────────────────────────────
        $listingCounts = Listing::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $listingsByStatus = [
            'draft' => (int) ($listingCounts[ListingStatus::DRAFT->value] ?? 0),
            'pending_review' => (int) ($listingCounts[ListingStatus::PENDING_REVIEW->value] ?? 0),
            'active' => (int) ($listingCounts[ListingStatus::ACTIVE->value] ?? 0),
            'rejected' => (int) ($listingCounts[ListingStatus::REJECTED->value] ?? 0),
            'inactive' => (int) ($listingCounts[ListingStatus::INACTIVE->value] ?? 0),
            'archived' => (int) ($listingCounts[ListingStatus::ARCHIVED->value] ?? 0),
        ];

        $totalListings = (int) $listingCounts->sum();

        // ── Contracts grouped by status (one query) ───────────────────────────
        $contractCounts = Contract::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $contracts = [
            'draft' => (int) ($contractCounts[ContractStatus::DRAFT->value] ?? 0),
            'pending_tenant' => (int) ($contractCounts[ContractStatus::PENDING_TENANT->value] ?? 0),
            'active' => (int) ($contractCounts[ContractStatus::ACTIVE->value] ?? 0),
            'terminated' => (int) ($contractCounts[ContractStatus::TERMINATED->value] ?? 0),
            'expired' => (int) ($contractCounts[ContractStatus::EXPIRED->value] ?? 0),
        ];

        // ── Ledger health (platform-wide) ─────────────────────────────────────
        $outstandingCents = (int) LedgerEntry::query()
            ->whereIn('status', [LedgerStatus::PENDING->value, LedgerStatus::OVERDUE->value])
            ->sum('amount_cents');

        $overdueCents = (int) LedgerEntry::query()
            ->where('status', LedgerStatus::OVERDUE)
            ->sum('amount_cents');

        $overdueEntries = LedgerEntry::query()
            ->where('status', LedgerStatus::OVERDUE)
            ->count();

        // why: ledger entries are immutable and carry no paid_at timestamp, so
        // "collected this month" is measured against due_date — the obligation's
        // period — for RENT entries now marked paid within the current calendar
        // month. We scope to RENT obligations (not PAYMENT receipts, which are
        // stored as negative amounts) so the figure is the positive rent collected,
        // matching the landlord property aggregate definition.
        $collectedThisMonthCents = (int) LedgerEntry::query()
            ->where('type', LedgerType::RENT->value)
            ->where('status', LedgerStatus::PAID)
            ->whereBetween('due_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount_cents');

        // ── Operational attention signals ─────────────────────────────────────
        // Admin-actionable verification queue: PENDING + UNDER_REVIEW. We exclude
        // NEEDS_MORE_INFORMATION because that state is waiting on the *user*, not
        // the admin, so it would overstate the admin's inbox.
        $pendingVerifications = VerificationRequest::query()
            ->whereIn('status', [
                VerificationStatus::PENDING->value,
                VerificationStatus::UNDER_REVIEW->value,
            ])
            ->count();

        // Accounts in good standing (excludes suspended/blocked/archived, which
        // clear is_active).
        $activeUsers = User::query()->where('is_active', true)->count();

        // Unresolved delivery failures across channels. A lingering *_failed_at
        // means the send failed and hasn't been retried/cleared, so this is a
        // truthful "needs attention" count (retryFailed() nulls it on recovery).
        $failedDeliveries = Notification::query()
            ->where(function (Builder $q) {
                $q->whereNotNull('delivery_failed_at')
                    ->orWhereNotNull('sms_failed_at');
            })
            ->count();

        // ── Recent activity ───────────────────────────────────────────────────
        $recentListings = Listing::with(['landlord', 'unit.property'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        return response()->json([
            'statistics' => [
                'landlords' => $landlordCount,
                'tenants' => $tenantCount,
                'properties' => $propertyCount,
                'units' => $unitCount,
                'pending_listings' => $listingsByStatus['pending_review'],
                'active_listings' => $listingsByStatus['active'],
                'total_listings' => $totalListings,
                'active_contracts' => $contracts['active'],
                'pending_verifications' => $pendingVerifications,
                'active_users' => $activeUsers,
            ],
            'contracts' => $contracts,
            'ledger' => [
                'outstanding_cents' => $outstandingCents,
                'overdue_cents' => $overdueCents,
                'overdue_entries' => $overdueEntries,
                'collected_this_month_cents' => $collectedThisMonthCents,
            ],
            'notifications' => [
                'failed_deliveries' => $failedDeliveries,
            ],
            'listings_by_status' => $listingsByStatus,
            'recent_listings' => $recentListings,
        ]);
    }
}
