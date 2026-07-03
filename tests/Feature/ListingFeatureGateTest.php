<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Admin;
use App\Models\Feature;
use App\Models\LandlordFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landlord listings feature gate is an AUTHORIZATION control, not a server
 * fault. A landlord who lacks the 'listings' feature must get a clean 403 (with
 * a safe message) — never a 500 — while a landlord who has it is unaffected and
 * non-landlords remain blocked by the route middleware.
 */
class ListingFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private Feature $listingsFeature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listingsFeature = Feature::create([
            'key' => 'listings',
            'name' => 'Property Listings',
            'description' => 'Create and manage property listings',
            'requires_identity_verification' => false,
            'enabled_by_default' => true,
            'is_available' => true,
        ]);
    }

    private function landlord(bool $withFeature): User
    {
        $landlord = User::factory()->create([
            'user_type' => UserType::LANDLORD,
            'identity_verified' => true,
            'email_verified_at' => now(),
            'verification_status' => 'verified',
        ]);

        if ($withFeature) {
            LandlordFeature::create([
                'landlord_id' => $landlord->id,
                'feature_id' => $this->listingsFeature->id,
                'enabled' => true,
                'enabled_at' => now(),
            ]);
        }

        return $landlord;
    }

    public function test_landlord_without_listings_feature_gets_403_not_500(): void
    {
        $landlord = $this->landlord(withFeature: false);

        $response = $this->actingAs($landlord, 'sanctum')->getJson('/api/landlord/listings');

        $response->assertStatus(403);
        // Message is clear and safe — it names only the feature, no internals.
        $this->assertStringContainsStringIgnoringCase('listings', (string) $response->json('message'));
        $this->assertStringNotContainsString('Exception', (string) $response->json('message'));
    }

    public function test_landlord_with_listings_feature_gets_200(): void
    {
        $landlord = $this->landlord(withFeature: true);

        $response = $this->actingAs($landlord, 'sanctum')->getJson('/api/landlord/listings');

        $response->assertStatus(200);
        // Returns their (empty) listing collection — access is not weakened.
        $response->assertJson([]);
    }

    public function test_tenant_cannot_access_landlord_listings(): void
    {
        $tenant = User::factory()->tenant()->create();

        // A tenant IS authenticated (bearer) but is the wrong role → 403.
        $this->actingAs($tenant, 'sanctum')
            ->getJson('/api/landlord/listings')
            ->assertStatus(403);
    }

    public function test_admin_cannot_access_landlord_listings(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);

        // An admin has no bearer identity on the sanctum guard → unauthenticated (401).
        $this->actingAs($admin, 'admin')
            ->getJson('/api/landlord/listings')
            ->assertStatus(401);
    }
}
