<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\LandlordFeature;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * FeatureGatingService
 *
 * Backend enforcement of feature flags.
 * ALL feature checks must go through this service.
 */
class FeatureGatingService
{
    /**
     * Check if landlord has a specific feature enabled
     */
    public function hasFeature(User $landlord, string $featureKey): bool
    {
        if (! $landlord->isLandlord()) {
            return false;
        }

        $feature = Feature::where('key', $featureKey)
            ->where('is_available', true)
            ->first();

        if (! $feature) {
            return false;
        }

        // Check if feature requires identity verification
        if ($feature->requires_identity_verification && ! $landlord->hasVerifiedIdentity()) {
            return false;
        }

        // Check if landlord has this feature enabled
        $landlordFeature = LandlordFeature::where('landlord_id', $landlord->id)
            ->where('feature_id', $feature->id)
            ->where('enabled', true)
            ->first();

        return $landlordFeature !== null;
    }

    /**
     * Require feature or throw exception
     */
    public function requireFeature(User $landlord, string $featureKey): void
    {
        if (! $this->hasFeature($landlord, $featureKey)) {
            throw new \Exception("Feature '{$featureKey}' is not enabled for this account");
        }
    }

    /**
     * Get all enabled features for landlord
     */
    public function getEnabledFeatures(User $landlord): Collection
    {
        if (! $landlord->isLandlord()) {
            return collect();
        }

        return $landlord->enabledFeatures()
            ->where('features.is_available', true)
            ->get();
    }

    /**
     * Get all available features (for UI display)
     */
    public function getAvailableFeatures(): Collection
    {
        return Feature::available()->get();
    }

    /**
     * Check if landlord can enable a feature
     */
    public function canEnableFeature(User $landlord, string $featureKey): array
    {
        $feature = Feature::where('key', $featureKey)
            ->where('is_available', true)
            ->first();

        if (! $feature) {
            return [
                'can_enable' => false,
                'reason' => 'Feature not available',
            ];
        }

        // Check identity verification requirement
        if ($feature->requires_identity_verification && ! $landlord->hasVerifiedIdentity()) {
            return [
                'can_enable' => false,
                'reason' => 'Identity verification required',
            ];
        }

        // Check feature dependencies
        if (! empty($feature->requires_features)) {
            foreach ($feature->requires_features as $requiredFeatureKey) {
                if (! $this->hasFeature($landlord, $requiredFeatureKey)) {
                    return [
                        'can_enable' => false,
                        'reason' => "Requires '{$requiredFeatureKey}' feature",
                    ];
                }
            }
        }

        return [
            'can_enable' => true,
            'reason' => null,
        ];
    }

    /**
     * Enable feature for landlord (admin action)
     */
    public function enableFeature(User $landlord, string $featureKey, ?int $adminId = null): LandlordFeature
    {
        $feature = Feature::where('key', $featureKey)->firstOrFail();

        // Check if can enable
        $check = $this->canEnableFeature($landlord, $featureKey);
        if (! $check['can_enable']) {
            throw new \Exception($check['reason']);
        }

        // Enable or update
        $landlordFeature = LandlordFeature::updateOrCreate(
            [
                'landlord_id' => $landlord->id,
                'feature_id' => $feature->id,
            ],
            [
                'enabled' => true,
                'enabled_by' => $adminId,
                'enabled_at' => now(),
                'disabled_by' => null,
                'disabled_at' => null,
            ]
        );

        return $landlordFeature;
    }

    /**
     * Disable feature for landlord (admin action)
     */
    public function disableFeature(User $landlord, string $featureKey, ?int $adminId = null): void
    {
        $feature = Feature::where('key', $featureKey)->firstOrFail();

        LandlordFeature::where('landlord_id', $landlord->id)
            ->where('feature_id', $feature->id)
            ->update([
                'enabled' => false,
                'disabled_by' => $adminId,
                'disabled_at' => now(),
            ]);
    }
}
