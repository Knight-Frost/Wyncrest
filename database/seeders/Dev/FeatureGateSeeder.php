<?php

namespace Database\Seeders\Dev;

use App\Services\FeatureGatingService;

/**
 * FeatureGateSeeder — per-landlord feature access.
 *
 * Drives access through the real FeatureGatingService so enablement respects the
 * platform's own rules (identity-verification gates, audit fields). This yields
 * meaningfully different landlord dashboards:
 *   - full    : listings, applications, leases, payments, maintenance
 *   - limited : listings only
 *   - none    : no features (pending/unverified/suspended landlords)
 */
class FeatureGateSeeder extends DevSeeder
{
    public function run(): void
    {
        $gating = app(FeatureGatingService::class);
        $adminId = $this->superAdmin()?->id;
        $enabled = 0;

        foreach (SeedCatalog::LANDLORDS as $person) {
            $landlord = $this->user($person['key']);
            if (! $landlord) {
                continue;
            }

            foreach (SeedCatalog::FEATURE_TIERS[$person['features']] ?? [] as $featureKey) {
                // canEnableFeature guards verification requirements; skip cleanly
                // rather than throwing if a tier ever conflicts with a gate.
                if (! $gating->canEnableFeature($landlord, $featureKey)['can_enable']) {
                    continue;
                }

                $gating->enableFeature($landlord, $featureKey, $adminId);
                $enabled++;
            }
        }

        $this->command?->info("  ✓ Feature gates: {$enabled} landlord-feature grants (full/limited).");
    }
}
