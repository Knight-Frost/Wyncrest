<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Unit::class);

        $units = Unit::whereHas('property', function($query) use ($request) {
                $query->where('landlord_id', $request->user()->id);
            })
            ->with(['property', 'activeListing'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($units);
    }

    /**
     * Store a newly created unit.
     * 
     * @param StoreUnitRequest $request
     * @param Property $property
     * @return JsonResponse
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
            'unit' => $unit->load('property')
        ], 201);
    }

    /**
     * Display the specified unit.
     * 
     * @param Unit $unit
     * @return JsonResponse
     */
    public function show(Unit $unit): JsonResponse
    {
        $this->authorize('view', $unit);

        return response()->json($unit->load(['property', 'listings']));
    }

    /**
     * Update the specified unit.
     * 
     * @param UpdateUnitRequest $request
     * @param Unit $unit
     * @return JsonResponse
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
            'unit' => $unit->fresh(['property', 'listings'])
        ]);
    }

    /**
     * Remove the specified unit (soft delete).
     * 
     * @param Request $request
     * @param Unit $unit
     * @return JsonResponse
     */
    public function destroy(Request $request, Unit $unit): JsonResponse
    {
        $this->authorize('delete', $unit);

        // Check if unit has active listings
        if ($unit->listings()->where('status', 'active')->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete unit with active listings. Please deactivate all listings first.'
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
            'message' => 'Unit deleted successfully'
        ]);
    }
}
