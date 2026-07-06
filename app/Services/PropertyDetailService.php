<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Enums\UnitAvailabilityStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Document;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Ledger\LedgerComputationEngine;
use Illuminate\Support\Collection;

/**
 * PropertyDetailService
 *
 * Assembles the full landlord "Property Detail" payload from real domain data:
 * property info, summary counts, attention warnings, units (with current tenant
 * and listing state), listings (apps/views/moderation feedback), contracts and
 * tenants (with computed balances), a property-scoped ledger, maintenance,
 * documents, photos, and an audit-backed activity log.
 *
 * The backend is the authority: balances, "collected", and every warning are
 * computed here, never guessed by the client.
 */
class PropertyDetailService
{
    public function __construct(
        protected LedgerComputationEngine $engine,
    ) {}

    /**
     * Build the complete detail payload for one property.
     *
     * @return array<string, mixed>
     */
    public function build(Property $property): array
    {
        $landlordId = $property->landlord_id;

        $units = $property->units()->orderBy('unit_number')->get();
        $unitIds = $units->pluck('id');

        // All listings for this property's units, newest first.
        $listings = Listing::whereIn('unit_id', $unitIds)
            ->withCount('applications')
            ->with('unit:id,unit_number')
            ->orderByDesc('created_at')
            ->get();

        // Contracts touching any of this property's units.
        $contracts = Contract::byLandlord($landlordId)
            ->whereHas('listing.unit', fn ($q) => $q->where('property_id', $property->id))
            ->with(['tenant', 'listing.unit:id,unit_number'])
            ->orderByDesc('created_at')
            ->get();

        // Index the *active* contract per unit so a unit row can name its tenant.
        $activeContractByUnit = $contracts
            ->filter(fn (Contract $c) => $c->status->value === 'active' && $c->listing?->unit)
            ->keyBy(fn (Contract $c) => $c->listing->unit->id);

        $maintenance = MaintenanceRequest::where('landlord_id', $landlordId)
            ->where('property_id', $property->id)
            ->with(['tenant', 'unit:id,unit_number'])
            ->latest('submitted_at')
            ->get();

        $documents = Document::where('related_type', Property::class)
            ->where('related_id', $property->id)
            ->with('uploader')
            ->latest()
            ->get();

        $financial = $this->engine->computePlatformFinancialSummary([
            'landlord_id' => $landlordId,
            'property_id' => $property->id,
        ]);

        return [
            'property' => $this->propertyInfo($property),
            'summary' => $this->summary($units, $listings, $financial),
            'attention' => $this->attention($property, $units, $listings),
            'units' => $this->units($units, $listings, $activeContractByUnit),
            'listings' => $this->listings($listings),
            'contracts' => $this->contracts($contracts),
            'ledger' => $this->ledger($property, $landlordId),
            'maintenance' => $this->maintenance($maintenance),
            'documents' => $this->documents($documents),
            'photos' => $this->photos($property, $units),
            'activity' => $this->activity($property, $unitIds, $listings, $contracts, $maintenance),
        ];
    }

    /** Property fields plus the review aggregate the header shows. */
    protected function propertyInfo(Property $property): array
    {
        return array_merge($property->toArray(), [
            'average_rating' => $property->average_rating,
            'review_count' => $property->review_count,
        ]);
    }

    /**
     * Headline counts. Rent figures are in cents so the client formats them
     * the same way it formats the ledger.
     *
     * @param  Collection<int, Unit>  $units
     * @param  Collection<int, Listing>  $listings
     * @param  array<string, int>  $financial
     */
    protected function summary(Collection $units, Collection $listings, array $financial): array
    {
        $occupied = $units->where('availability_status', UnitAvailabilityStatus::OCCUPIED)->count();
        $vacant = $units->where('availability_status', UnitAvailabilityStatus::AVAILABLE)->count();

        $listed = $listings->where('status', ListingStatus::ACTIVE)->count();
        $pending = $listings->where('status', ListingStatus::PENDING_REVIEW)->count();

        // Expected monthly rent = sum of occupied units' rent, in cents.
        $expectedRentCents = (int) $units
            ->where('availability_status', UnitAvailabilityStatus::OCCUPIED)
            ->sum(fn (Unit $u) => (int) round(((float) $u->rent_amount) * 100));

        return [
            'units_total' => $units->count(),
            'occupied' => $occupied,
            'vacant' => $vacant,
            'listed' => $listed,
            'pending_review' => $pending,
            'expected_rent_cents' => $expectedRentCents,
            'collected_cents' => $financial['collected_cents'] ?? 0,
            'outstanding_cents' => $financial['outstanding_cents'] ?? 0,
            'overdue_cents' => $financial['overdue_cents'] ?? 0,
        ];
    }

    /**
     * Server-computed "needs attention" warnings for the detail page. Reduces
     * the property to a few primitives and hands off to the shared builder so
     * the list card and the detail page always agree on what's wrong.
     *
     * @param  Collection<int, Unit>  $units
     * @param  Collection<int, Listing>  $listings
     * @return list<array{level:string,message:string}>
     */
    protected function attention(Property $property, Collection $units, Collection $listings): array
    {
        $listedUnitIds = $listings
            ->whereIn('status', [ListingStatus::ACTIVE, ListingStatus::PENDING_REVIEW, ListingStatus::DRAFT])
            ->pluck('unit_id')
            ->unique();

        $vacantUnlisted = $units
            ->where('availability_status', UnitAvailabilityStatus::AVAILABLE)
            ->reject(fn (Unit $u) => $listedUnitIds->contains($u->id))
            ->count();

        return self::attentionSummary(
            isActive: $property->is_active,
            unitsTotal: $units->count(),
            vacantUnlisted: $vacantUnlisted,
            rejected: $listings->where('status', ListingStatus::REJECTED)->count(),
            changesRequested: $listings->filter(
                fn (Listing $l) => $l->status === ListingStatus::DRAFT && $l->changes_requested_reason
            )->count(),
            hasCover: $property->mediaAssets()->count() > 0,
        );
    }

    /**
     * Single source of truth for "needs attention" warnings, driven only by
     * primitives so both the property list (grouped queries) and the detail
     * page (loaded collections) produce identical, truthful messages.
     *
     * @return list<array{level:string,message:string}>
     */
    public static function attentionSummary(
        bool $isActive,
        int $unitsTotal,
        int $vacantUnlisted,
        int $rejected,
        int $changesRequested,
        bool $hasCover,
    ): array {
        $warnings = [];

        if ($rejected > 0) {
            $noun = $rejected === 1 ? 'A listing was' : "{$rejected} listings were";
            $warnings[] = ['level' => 'red', 'message' => "{$noun} rejected and needs changes."];
        }

        if ($changesRequested > 0) {
            $noun = $changesRequested === 1 ? 'A listing needs' : "{$changesRequested} listings need";
            $warnings[] = ['level' => 'red', 'message' => "{$noun} changes an admin requested before it can go live."];
        }

        if (! $isActive) {
            $warnings[] = ['level' => 'warn', 'message' => 'This property is inactive. Reactivate it before publishing listings.'];
        }

        if (! $hasCover) {
            $warnings[] = ['level' => 'warn', 'message' => 'This property has no cover photo. Add one before publishing a listing.'];
        }

        if ($unitsTotal === 0) {
            $warnings[] = ['level' => 'warn', 'message' => 'This property has no units yet. Add a unit before creating a listing.'];
        } elseif ($vacantUnlisted > 0) {
            $noun = $vacantUnlisted === 1 ? 'unit has' : 'units have';
            $warnings[] = ['level' => 'warn', 'message' => "{$vacantUnlisted} vacant {$noun} no listing yet, so tenants cannot apply for them."];
        }

        return $warnings;
    }

    /**
     * Per-unit rows: specs, occupancy, its derived listing state, and the
     * current tenant (from the active contract) when occupied.
     *
     * @param  Collection<int, Unit>  $units
     * @param  Collection<int, Listing>  $listings
     * @param  Collection<int, Contract>  $activeContractByUnit
     * @return list<array<string, mixed>>
     */
    protected function units(Collection $units, Collection $listings, Collection $activeContractByUnit): array
    {
        $listingsByUnit = $listings->groupBy('unit_id');

        return $units->map(function (Unit $unit) use ($listingsByUnit, $activeContractByUnit) {
            $unitListings = $listingsByUnit->get($unit->id, collect());
            $listingStatus = $this->deriveUnitListingStatus($unitListings);
            $tenant = $activeContractByUnit->get($unit->id)?->tenant;

            $hasBlocking = $unitListings->contains(
                fn (Listing $l) => in_array($l->status, [
                    ListingStatus::DRAFT,
                    ListingStatus::PENDING_REVIEW,
                    ListingStatus::ACTIVE,
                ], true)
            );

            return [
                'id' => $unit->id,
                'unit_number' => $unit->unit_number,
                'internal_name' => $unit->internal_name,
                'bedrooms' => $unit->bedrooms,
                'bathrooms' => $unit->bathrooms,
                'square_feet' => $unit->square_feet,
                'rent_amount' => $unit->rent_amount,
                'availability_status' => $unit->availability_status->value,
                'listing_status' => $listingStatus,
                'tenant_name' => $tenant?->full_name,
                'has_blocking_listing' => $hasBlocking,
            ];
        })->values()->all();
    }

    /**
     * Collapse a unit's listings to one representative status the row can badge:
     * active > pending_review > rejected > draft > none.
     *
     * @param  Collection<int, Listing>  $unitListings
     */
    protected function deriveUnitListingStatus(Collection $unitListings): string
    {
        foreach ([
            ListingStatus::ACTIVE,
            ListingStatus::PENDING_REVIEW,
            ListingStatus::REJECTED,
            ListingStatus::DRAFT,
        ] as $status) {
            if ($unitListings->contains('status', $status)) {
                return $status->value;
            }
        }

        return 'none';
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return list<array<string, mixed>>
     */
    protected function listings(Collection $listings): array
    {
        return $listings->map(fn (Listing $l) => [
            'id' => $l->id,
            'title' => $l->title,
            'unit_id' => $l->unit_id,
            'unit_number' => $this->unitLabel($l->unit),
            'rent_amount' => $l->unit?->rent_amount,
            'status' => $l->status->value,
            'rejection_reason' => $l->rejection_reason,
            'changes_requested_reason' => $l->changes_requested_reason,
            'published_at' => $l->published_at?->toIso8601String(),
            'applications_count' => (int) ($l->applications_count ?? 0),
            'view_count' => (int) $l->view_count,
        ])->values()->all();
    }

    /**
     * Contracts and their tenants, with balances the ledger engine computes.
     *
     * @param  Collection<int, Contract>  $contracts
     * @return list<array<string, mixed>>
     */
    protected function contracts(Collection $contracts): array
    {
        return $contracts->map(fn (Contract $c) => [
            'id' => $c->id,
            'tenant_name' => $c->tenant?->full_name,
            'unit_number' => $this->unitLabel($c->listing?->unit),
            'status' => $c->status->value,
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'rent_amount_cents' => (int) $c->rent_amount,
            'balance_cents' => $this->engine->computeContractBalance($c->id),
            'outstanding_cents' => $this->engine->computeOutstandingByContract($c->id),
            'payment_status' => $this->engine->deriveContractPaymentStatus($c->id),
        ])->values()->all();
    }

    /**
     * Property-scoped ledger, decorated exactly like the ledger page so the
     * display semantics (direction, category, reference, running balance) match.
     *
     * @return list<array<string, mixed>>
     */
    protected function ledger(Property $property, int $landlordId): array
    {
        $entries = LedgerEntry::where('landlord_id', $landlordId)
            ->whereHas('contract.listing.unit', fn ($q) => $q->where('property_id', $property->id))
            ->with(['contract.listing.unit:id,unit_number', 'tenant'])
            ->orderByDesc('due_date')
            ->get();

        return $this->engine->decorateEntries($entries)
            ->map(function (array $row) use ($entries) {
                // Attach the unit label + tenant name the property ledger table shows.
                $entry = $entries->firstWhere('id', $row['id']);

                return array_merge($row, [
                    'unit_number' => $this->unitLabel($entry?->contract?->listing?->unit),
                    'tenant_name' => $entry?->tenant?->full_name,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MaintenanceRequest>  $maintenance
     * @return list<array<string, mixed>>
     */
    protected function maintenance(Collection $maintenance): array
    {
        return $maintenance->map(fn (MaintenanceRequest $m) => [
            'id' => $m->id,
            'title' => $m->title,
            'unit_number' => $this->unitLabel($m->unit),
            'tenant_name' => $m->tenant?->full_name,
            'category' => $m->category?->value,
            'priority' => $m->priority->value,
            'status' => $m->status->value,
            'submitted_at' => $m->submitted_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Document>  $documents
     * @return list<array<string, mixed>>
     */
    protected function documents(Collection $documents): array
    {
        return $documents->map(fn (Document $d) => [
            'id' => $d->id,
            'original_filename' => $d->original_filename,
            'document_type' => $d->document_type?->value,
            'uploader_name' => $d->uploader?->full_name,
            'is_verified' => $d->is_verified,
            'created_at' => $d->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Property gallery photos plus per-unit photos, flattened into one grid.
     * The first property-gallery image is flagged as the cover.
     *
     * @param  Collection<int, Unit>  $units
     * @return list<array<string, mixed>>
     */
    protected function photos(Property $property, Collection $units): array
    {
        $photos = [];

        foreach ($property->mediaAssets as $index => $asset) {
            $photos[] = [
                'id' => $asset->id,
                'url' => $asset->url,
                'scope' => 'Property',
                'alt_text' => $asset->alt_text,
                'caption' => $asset->caption,
                'is_cover' => $index === 0,
            ];
        }

        foreach ($units as $unit) {
            foreach ($unit->mediaAssets as $asset) {
                $photos[] = [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'scope' => 'Unit '.$this->unitLabel($unit),
                    'alt_text' => $asset->alt_text,
                    'caption' => $asset->caption,
                    'is_cover' => false,
                ];
            }
        }

        return $photos;
    }

    /**
     * Audit-log-backed activity for the property and everything under it
     * (units, listings, contracts, maintenance). Newest first, bounded.
     *
     * @param  Collection<int, int>  $unitIds
     * @param  Collection<int, Listing>  $listings
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, MaintenanceRequest>  $maintenance
     * @return list<array<string, mixed>>
     */
    protected function activity(
        Property $property,
        Collection $unitIds,
        Collection $listings,
        Collection $contracts,
        Collection $maintenance,
    ): array {
        $subjects = [
            [Property::class, [$property->id]],
            [Unit::class, $unitIds->all()],
            [Listing::class, $listings->pluck('id')->all()],
            [Contract::class, $contracts->pluck('id')->all()],
            [MaintenanceRequest::class, $maintenance->pluck('id')->all()],
        ];

        $logs = AuditLog::where(function ($query) use ($subjects) {
            foreach ($subjects as [$type, $ids]) {
                if (! empty($ids)) {
                    $query->orWhere(function ($q) use ($type, $ids) {
                        $q->where('subject_type', $type)->whereIn('subject_id', $ids);
                    });
                }
            }
        })
            ->with('actor')
            ->latest()
            ->limit(40)
            ->get();

        return $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'actor_name' => $this->actorName($log),
            'actor_role' => $this->actorRole($log),
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values()->all();
    }

    protected function actorName(AuditLog $log): ?string
    {
        $actor = $log->actor;

        if ($actor instanceof User || $actor instanceof Admin) {
            return $actor->full_name ?? $actor->name ?? null;
        }

        return null;
    }

    protected function actorRole(AuditLog $log): string
    {
        $actor = $log->actor;

        if ($actor instanceof Admin) {
            return 'Admin';
        }

        if ($actor instanceof User) {
            return ucfirst($actor->user_type->value);
        }

        return 'System';
    }

    /** A unit's display label ("3B", "Villa 1"), or an em dash when absent. */
    protected function unitLabel(?Unit $unit): string
    {
        return $unit?->unit_number ?? '—';
    }
}
