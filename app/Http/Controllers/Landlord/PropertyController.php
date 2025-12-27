<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Models\Property;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Property::class);

        $properties = $request->user()
            ->properties()
            ->withCount('units')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($properties);
    }

    /**
     * Store a newly created property.
     * 
     * @param StorePropertyRequest $request
     * @return JsonResponse
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
            'property' => $property->load('units')
        ], 201);
    }

    /**
     * Display the specified property.
     * 
     * @param Property $property
     * @return JsonResponse
     */
    public function show(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        return response()->json($property->load(['units', 'activeUnits']));
    }

    /**
     * Update the specified property.
     * 
     * @param UpdatePropertyRequest $request
     * @param Property $property
     * @return JsonResponse
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
            'property' => $property->fresh(['units'])
        ]);
    }

    /**
     * Remove the specified property (soft delete).
     * 
     * @param Request $request
     * @param Property $property
     * @return JsonResponse
     */
    public function destroy(Request $request, Property $property): JsonResponse
    {
        $this->authorize('delete', $property);

        // Check if property has units
        if ($property->units()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete property with existing units. Please delete all units first.'
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
            'message' => 'Property deleted successfully'
        ]);
    }
}
