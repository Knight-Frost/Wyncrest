<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\LandlordFeature;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
     * Require a feature to be enabled, or deny access.
     *
     * A missing feature is an AUTHORIZATION denial, not a server fault: it
     * throws AuthorizationException (rendered as HTTP 403 by Laravel), matching
     * the ownership-denial pattern in ReviewService. The message is safe and
     * user-facing — it names only the feature, never internal state.
     *
     * @throws AuthorizationException when the landlord lacks the feature
     */
    public function requireFeature(User $landlord, string $featureKey): void
    {
        if (! $this->hasFeature($landlord, $featureKey)) {
            throw new AuthorizationException(
                "The '{$featureKey}' feature is not enabled for your account."
            );
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
