<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FeatureGatingService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * AdminFeatureController
 * 
 * Handles admin feature enablement for landlords.
 * All actions are audited and routed through FeatureGatingService.
 */
class AdminFeatureController extends Controller
{
    public function __construct(
        protected FeatureGatingService $featureGatingService,
        protected AuditService $auditService
    ) {}

    /**
     * Get all features and their status for a landlord.
     * 
     * @param User $landlord
     * @return JsonResponse
     */
    public function index(User $landlord): JsonResponse
    {
        if (!$landlord->isLandlord()) {
            return response()->json([
                'message' => 'User is not a landlord'
            ], 422);
        }

        $allFeatures = $this->featureGatingService->getAvailableFeatures();
        $enabledFeatures = $this->featureGatingService->getEnabledFeatures($landlord);

        $featuresWithStatus = $allFeatures->map(function($feature) use ($landlord, $enabledFeatures) {
            $isEnabled = $enabledFeatures->contains('id', $feature->id);
            $canEnable = $this->featureGatingService->canEnableFeature($landlord, $feature->key);

            return [
                'id' => $feature->id,
                'key' => $feature->key,
                'name' => $feature->name,
                'description' => $feature->description,
                'requires_identity_verification' => $feature->requires_identity_verification,
                'enabled' => $isEnabled,
                'can_enable' => $canEnable['can_enable'],
                'reason' => $canEnable['reason'],
            ];
        });

        return response()->json([
            'landlord' => [
                'id' => $landlord->id,
                'name' => $landlord->full_name,
                'email' => $landlord->email,
                'identity_verified' => $landlord->hasVerifiedIdentity(),
            ],
            'features' => $featuresWithStatus,
        ]);
    }

    /**
     * Enable a feature for a landlord.
     * 
     * @param Request $request
     * @param User $landlord
     * @param string $featureKey
     * @return JsonResponse
     */
    public function enable(Request $request, User $landlord, string $featureKey): JsonResponse
    {
        if (!$landlord->isLandlord()) {
            return response()->json([
                'message' => 'User is not a landlord'
            ], 422);
        }

        // Check if can enable
        $check = $this->featureGatingService->canEnableFeature($landlord, $featureKey);
        if (!$check['can_enable']) {
            return response()->json([
                'message' => $check['reason']
            ], 422);
        }

        // Validate optional notes
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500']
        ]);

        try {
            $landlordFeature = $this->featureGatingService->enableFeature(
                $landlord,
                $featureKey,
                auth('admin')->id()
            );

            // Add notes if provided
            if (!empty($validated['notes'])) {
                $landlordFeature->update(['notes' => $validated['notes']]);
            }

            // Audit log
            $this->auditService->logFeatureEnabled(
                $landlord,
                $featureKey,
                auth('admin')->user()
            );

            return response()->json([
                'message' => "Feature '{$featureKey}' enabled for landlord",
                'landlord_feature' => $landlordFeature
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Disable a feature for a landlord.
     * 
     * @param Request $request
     * @param User $landlord
     * @param string $featureKey
     * @return JsonResponse
     */
    public function disable(Request $request, User $landlord, string $featureKey): JsonResponse
    {
        if (!$landlord->isLandlord()) {
            return response()->json([
                'message' => 'User is not a landlord'
            ], 422);
        }

        try {
            $this->featureGatingService->disableFeature(
                $landlord,
                $featureKey,
                auth('admin')->id()
            );

            // Audit log
            $this->auditService->logFeatureDisabled(
                $landlord,
                $featureKey,
                auth('admin')->user()
            );

            return response()->json([
                'message' => "Feature '{$featureKey}' disabled for landlord"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
