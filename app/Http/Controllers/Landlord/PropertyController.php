<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ListingStatus;
use App\Enums\MediaCollection;
use App\Enums\UnitAvailabilityStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Models\Listing;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\Unit;
use App\Services\AuditService;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\PropertyDetailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PropertyController
 *
 * Handles landlord property management.
 * All operations are owner-restricted via policies.
 */
class PropertyController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Display a listing of the landlord's properties.
     */
    public function index(Request $request, LedgerComputationEngine $engine): JsonResponse
    {
        $this->authorize('viewAny', Property::class);

        $landlordId = $request->user()->id;

        $properties = $request->user()
            ->properties()
            ->withCount('units')
            ->orderBy('created_at', 'desc')
            ->get();

        $propertyIds = $properties->pluck('id');

        // Per-property unit availability counts (one grouped query, no N+1).
        $unitCounts = Unit::whereIn('property_id', $propertyIds)
            ->selectRaw('property_id, availability_status, COUNT(*) as aggregate')
            ->groupBy('property_id', 'availability_status')
            ->get()
            ->groupBy('property_id');

        // Listings for these properties, joined via their units. Used to derive
        // listed/pending counts and the "needs attention" warnings the cards show.
        $listings = Listing::whereHas('unit', fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->with('unit:id,property_id')
            ->get(['id', 'unit_id', 'status', 'changes_requested_reason'])
            ->groupBy(fn (Listing $l) => $l->unit->property_id);

        // Vacant unit ids per property (to spot vacant units with no listing).
        $vacantUnits = Unit::whereIn('property_id', $propertyIds)
            ->where('availability_status', UnitAvailabilityStatus::AVAILABLE)
            ->get(['id', 'property_id'])
            ->groupBy('property_id');

        // First gallery photo per property → the card cover (and a truthful
        // "no cover photo" signal when absent).
        $coverByProperty = MediaAsset::where('collection', MediaCollection::PropertyGallery->value)
            ->where('status', 'active')
            ->where('attachable_type', Property::class)
            ->whereIn('attachable_id', $propertyIds)
            ->ordered()
            ->get()
            ->groupBy('attachable_id')
            ->map(fn ($group) => $group->first());

        $activeInFlight = [ListingStatus::ACTIVE, ListingStatus::PENDING_REVIEW, ListingStatus::DRAFT];

        $payload = $properties->map(function (Property $property) use (
            $unitCounts,
            $listings,
            $vacantUnits,
            $coverByProperty,
            $activeInFlight,
            $landlordId,
            $engine
        ) {
            // availability_status is enum-cast on the model, so key by its scalar value.
            $counts = ($unitCounts[$property->id] ?? collect())
                ->mapWithKeys(fn ($row) => [$row->availability_status->value => (int) $row->aggregate]);

            $total = (int) $property->units_count;
            $occupied = (int) ($counts[UnitAvailabilityStatus::OCCUPIED->value] ?? 0);
            $vacant = (int) ($counts[UnitAvailabilityStatus::AVAILABLE->value] ?? 0);

            $propertyListings = $listings->get($property->id, collect());
            $listed = $propertyListings->where('status', ListingStatus::ACTIVE)->count();
            $pending = $propertyListings->where('status', ListingStatus::PENDING_REVIEW)->count();
            $rejected = $propertyListings->where('status', ListingStatus::REJECTED)->count();
            $changesRequested = $propertyListings->filter(
                fn (Listing $l) => $l->status === ListingStatus::DRAFT && $l->changes_requested_reason
            )->count();

            // Vacant units that carry no active/in-flight listing can't be applied for.
            $listedUnitIds = $propertyListings
                ->whereIn('status', $activeInFlight)
                ->pluck('unit_id')
                ->unique();
            $vacantUnlisted = ($vacantUnits->get($property->id, collect()))
                ->reject(fn (Unit $u) => $listedUnitIds->contains($u->id))
                ->count();

            $cover = $coverByProperty->get($property->id);

            $attention = PropertyDetailService::attentionSummary(
                isActive: $property->is_active,
                unitsTotal: $total,
                vacantUnlisted: $vacantUnlisted,
                rejected: $rejected,
                changesRequested: $changesRequested,
                hasCover: $cover !== null,
            );

            // Per-property rent collected this calendar month, via the same
            // computation engine that powers the dashboard and ledger page
            // (a landlord's property list is small, so a per-property call
            // trades a little query volume for one authoritative definition
            // of "collected" instead of a third bespoke SQL aggregate).
            $collectedThisMonthCents = $engine->computeCollected([
                'landlord_id' => $landlordId,
                'property_id' => $property->id,
                'date_from' => now()->startOfMonth(),
                'date_to' => now()->endOfMonth(),
            ]);

            return array_merge($property->toArray(), [
                'occupied_units' => $occupied,
                'vacant_units' => $vacant,
                'listed_units' => $listed,
                'pending_units' => $pending,
                'rejected_units' => $rejected,
                'occupancy_rate' => (int) round($occupied / max($total, 1) * 100),
                'collected_this_month_cents' => $collectedThisMonthCents,
                'cover_url' => $cover?->url,
                'attention' => $attention,
            ]);
        });

        return response()->json($payload);
    }

    /**
     * Store a newly created property.
     */
    public function store(StorePropertyRequest $request): JsonResponse
    {
        $this->authorize('create', Property::class);

        $property = new Property($request->validated());
        $property->landlord_id = $request->user()->id;
        $property->save();

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'property_created',
            subject: $property,
            description: "Created property: {$property->name}"
        );

        return response()->json([
            'message' => 'Property created successfully',
            'property' => $property->load('units'),
        ], 201);
    }

    /**
     * Display the specified property.
     */
    public function show(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        return response()->json($property->load(['units', 'activeUnits', 'mediaAssets']));
    }

    /**
     * Rich detail payload for the landlord Property page — property info,
     * summary counts, attention warnings, units, listings, contracts/tenants,
     * a property-scoped ledger, maintenance, documents, photos, and activity.
     * All aggregation and money computation happens server-side.
     */
    public function detail(Property $property, PropertyDetailService $detailService): JsonResponse
    {
        $this->authorize('view', $property);

        return response()->json($detailService->build($property));
    }

    /**
     * Update the specified property.
     */
    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $oldValues = $property->only(array_keys($request->validated()));

        $property->update($request->validated());

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'property_updated',
            subject: $property,
            description: "Updated property: {$property->name}",
            oldValues: $oldValues,
            newValues: $property->only(array_keys($request->validated()))
        );

        return response()->json([
            'message' => 'Property updated successfully',
            'property' => $property->fresh(['units']),
        ]);
    }

    /**
     * Remove the specified property (soft delete).
     */
    public function destroy(Request $request, Property $property): JsonResponse
    {
        $this->authorize('delete', $property);

        // Check if property has units
        if ($property->units()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete property with existing units. Please delete all units first.',
            ], 422);
        }

        $property->delete();

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'property_deleted',
            subject: $property,
            description: "Deleted property: {$property->name}"
        );

        return response()->json([
            'message' => 'Property deleted successfully',
        ]);
    }
}
