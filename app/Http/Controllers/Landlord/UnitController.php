<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ContractStatus;
use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Contract;
use App\Models\Property;
use App\Models\Unit;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UnitController
 *
 * Handles landlord unit management.
 * Units must belong to landlord's properties.
 */
class UnitController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Display a listing of the landlord's units.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Unit::class);

        $units = Unit::whereHas('property', function ($query) use ($request) {
            $query->where('landlord_id', $request->user()->id);
        })
            ->with(['property', 'activeListing'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($units);
    }

    /**
     * Store a newly created unit.
     */
    public function store(StoreUnitRequest $request, Property $property): JsonResponse
    {
        $this->authorize('create', Unit::class);

        $unit = new Unit($request->validated());
        $unit->property_id = $property->id;
        $unit->save();

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'unit_created',
            subject: $unit,
            description: "Created unit in {$property->name}"
        );

        return response()->json([
            'message' => 'Unit created successfully',
            'unit' => $unit->load('property'),
        ], 201);
    }

    /**
     * Display the specified unit.
     */
    public function show(Unit $unit): JsonResponse
    {
        $this->authorize('view', $unit);

        return response()->json($unit->load(['property', 'listings', 'mediaAssets']));
    }

    /**
     * Update the specified unit.
     */
    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        $oldValues = $unit->only(array_keys($request->validated()));

        $unit->update($request->validated());

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'unit_updated',
            subject: $unit,
            description: "Updated unit in {$unit->property->name}",
            oldValues: $oldValues,
            newValues: $unit->only(array_keys($request->validated()))
        );

        return response()->json([
            'message' => 'Unit updated successfully',
            'unit' => $unit->fresh(['property', 'listings']),
        ]);
    }

    /**
     * Remove the specified unit (soft delete).
     */
    public function destroy(Request $request, Unit $unit): JsonResponse
    {
        $this->authorize('delete', $unit);

        // Check if the unit has any listing that isn't archived — an active
        // listing may still receive applications/contracts, so anything short
        // of archived must be cleaned up first.
        if ($unit->listings()->where('status', '!=', ListingStatus::ARCHIVED->value)->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete unit with active listings. Please deactivate all listings first.',
            ], 422);
        }

        // Check if any of the unit's listings is referenced by a live contract
        // (active or awaiting tenant signature) — deleting the unit out from
        // under a real lease would orphan financial/contractual records.
        $hasLiveContract = Contract::whereIn('listing_id', $unit->listings()->pluck('id'))
            ->whereIn('status', [
                ContractStatus::ACTIVE->value,
                ContractStatus::PENDING_TENANT->value,
            ])
            ->exists();

        if ($hasLiveContract) {
            return response()->json([
                'message' => 'Cannot delete unit with an active or pending contract. Terminate the contract first.',
            ], 422);
        }

        $unit->delete();

        // Audit log
        $this->auditService->log(
            actor: $request->user(),
            action: 'unit_deleted',
            subject: $unit,
            description: "Deleted unit in {$unit->property->name}"
        );

        return response()->json([
            'message' => 'Unit deleted successfully',
        ]);
    }
}
