<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\ListingStatus;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Analytics\PlatformAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformAnalyticsTest
 *
 * Phase 4.0c: Tests for platform health analytics
 */
class PlatformAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $tenant;

    protected User $landlord;

    protected Property $property;

    protected Unit $unit;

    protected Listing $listing;

    protected PlatformAnalyticsService $analyticsService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users
        $this->tenant = User::factory()->tenant()->create();
        $this->landlord = User::factory()->landlord()->create();
        $this->admin = $this->landlord;

        // Create property chain
        $this->property = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
        ]);

        $this->unit = Unit::factory()->create([
            'property_id' => $this->property->id,
        ]);

        $this->listing = Listing::factory()->create([
            'unit_id' => $this->unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        $this->analyticsService = app(PlatformAnalyticsService::class);
    }

    public function test_occupancy_rate_is_correct()
    {
        // Create 3 units total
        $unit2 = Unit::factory()->create(['property_id' => $this->property->id]);
        $unit3 = Unit::factory()->create(['property_id' => $this->property->id]);

        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        // Only 2 units have active contracts (occupied)
        Contract::factory()->create([
            'listing_id' => $this->listing->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEquals(3, $analytics['occupancy']['total_units']);
        $this->assertEquals(2, $analytics['occupancy']['occupied_units']);
        $this->assertEquals(1, $analytics['occupancy']['vacant_units']);
        $this->assertEqualsWithDelta(66.67, $analytics['occupancy']['occupancy_rate_percentage'], 0.1);
    }

    public function test_vacancy_duration_calculation()
    {
        // Create unit with expired contract from 30 days ago
        $unit2 = Unit::factory()->create(['property_id' => $this->property->id]);
        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::EXPIRED,
            'end_date' => Carbon::now()->subDays(30),
        ]);

        $analytics = $this->analyticsService->getAnalytics();

        // Should be approximately 30 days
        $this->assertGreaterThan(25, $analytics['occupancy']['average_vacancy_duration_days']);
        $this->assertLessThan(35, $analytics['occupancy']['average_vacancy_duration_days']);
    }

    public function test_user_counts_by_role()
    {
        // Create additional users
        User::factory()->tenant()->count(3)->create();
        User::factory()->landlord()->count(2)->create();

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertIsArray($analytics['growth']['users_by_role']);
        $this->assertGreaterThanOrEqual(4, $analytics['growth']['users_by_role']['tenant'] ?? 0);
        $this->assertGreaterThanOrEqual(3, $analytics['growth']['users_by_role']['landlord'] ?? 0);
    }

    public function test_listing_conversion_rate_calculation()
    {
        // Create 2 more listings (3 total)
        $unit2 = Unit::factory()->create(['property_id' => $this->property->id]);
        $unit3 = Unit::factory()->create(['property_id' => $this->property->id]);

        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        $listing3 = Listing::factory()->create([
            'unit_id' => $unit3->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        // Create 2 contracts from listings (2 out of 3 = 66.67%)
        Contract::factory()->create([
            'listing_id' => $this->listing->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEquals(3, $analytics['utilization']['total_listings']);
        $this->assertEqualsWithDelta(66.67, $analytics['utilization']['listing_to_contract_conversion_rate'], 0.1);
    }

    public function test_listing_conversion_rate_is_clamped_at_100_percent()
    {
        // A listing can back more than one contract over its lifetime
        // (renewals/re-lets), and contracts on a since-removed listing still
        // count — so the raw ratio can exceed 100%. Simulate that: one visible
        // listing but two live contracts (the second listing is soft-deleted,
        // dropping it from the listing count while its contract remains).
        $unit2 = Unit::factory()->create(['property_id' => $this->property->id]);
        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        Contract::factory()->create([
            'listing_id' => $this->listing->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);
        Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        // Soft-delete listing2: now 1 visible listing, 2 live contracts -> 200% raw.
        $listing2->delete();

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEquals(1, $analytics['utilization']['total_listings']);
        // Must be clamped to 100, never an impossible >100% conversion rate.
        $this->assertLessThanOrEqual(100.0, $analytics['utilization']['listing_to_contract_conversion_rate']);
        $this->assertEquals(100.0, $analytics['utilization']['listing_to_contract_conversion_rate']);
    }

    public function test_active_listings_count()
    {
        // Create inactive listing
        $unit2 = Unit::factory()->create(['property_id' => $this->property->id]);

        Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::INACTIVE,
        ]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEquals(2, $analytics['utilization']['total_listings']);
        $this->assertEquals(1, $analytics['utilization']['active_listings']);
    }

    public function test_property_scoping_for_occupancy()
    {
        // Create second property with units
        $property2 = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
        ]);

        $unit2 = Unit::factory()->create(['property_id' => $property2->id]);

        // Only count units from property 1
        $analytics = $this->analyticsService->getAnalytics([
            'property_id' => $this->property->id,
        ]);

        $this->assertEquals(1, $analytics['occupancy']['total_units']);
    }

    public function test_admin_can_access_platform_analytics_api()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/platform');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'analytics' => [
                'occupancy',
                'growth',
                'utilization',
            ],
        ]);
    }

    public function test_tenant_cannot_access_platform_analytics()
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/analytics/platform');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_platform_analytics()
    {
        $response = $this->getJson('/api/analytics/platform');
        $response->assertStatus(401);
    }

    public function test_platform_analytics_does_not_mutate_data()
    {
        $unit = Unit::factory()->create(['property_id' => $this->property->id]);

        $originalCreatedAt = $unit->created_at;
        $originalUpdatedAt = $unit->updated_at;

        // Call analytics multiple times
        $this->analyticsService->getAnalytics();
        $this->analyticsService->getAnalytics();

        $unit->refresh();

        $this->assertEquals($originalCreatedAt, $unit->created_at);
        $this->assertEquals($originalUpdatedAt, $unit->updated_at);
    }

    public function test_platform_analytics_returns_deterministic_results()
    {
        Unit::factory()->count(5)->create(['property_id' => $this->property->id]);

        $result1 = $this->analyticsService->getAnalytics();
        $result2 = $this->analyticsService->getAnalytics();

        $this->assertEquals($result1, $result2);
    }

    public function test_empty_data_returns_zero_metrics()
    {
        // Delete all data
        $this->unit->delete();
        $this->listing->delete();

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEquals(0, $analytics['occupancy']['total_units']);
        $this->assertEquals(0, $analytics['occupancy']['occupied_units']);
        $this->assertEquals(0.0, $analytics['occupancy']['occupancy_rate_percentage']);
    }

    public function test_platform_growth_metrics_are_accurate()
    {
        // Create additional properties and units
        Property::factory()->count(2)->create(['landlord_id' => $this->landlord->id]);
        Unit::factory()->count(3)->create(['property_id' => $this->property->id]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertGreaterThanOrEqual(3, $analytics['growth']['total_properties']);
        $this->assertGreaterThanOrEqual(4, $analytics['growth']['total_units']);
    }

    public function test_utilization_metrics_structure()
    {
        Contract::factory()->create([
            'listing_id' => $this->listing->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertArrayHasKey('total_listings', $analytics['utilization']);
        $this->assertArrayHasKey('active_listings', $analytics['utilization']);
        $this->assertArrayHasKey('listing_to_contract_conversion_rate', $analytics['utilization']);
    }

    public function test_occupancy_metrics_structure()
    {
        $analytics = $this->analyticsService->getAnalytics();

        $this->assertArrayHasKey('total_units', $analytics['occupancy']);
        $this->assertArrayHasKey('occupied_units', $analytics['occupancy']);
        $this->assertArrayHasKey('vacant_units', $analytics['occupancy']);
        $this->assertArrayHasKey('occupancy_rate_percentage', $analytics['occupancy']);
        $this->assertArrayHasKey('average_vacancy_duration_days', $analytics['occupancy']);
    }

    public function test_growth_metrics_structure()
    {
        $analytics = $this->analyticsService->getAnalytics();

        $this->assertArrayHasKey('total_users', $analytics['growth']);
        $this->assertArrayHasKey('users_by_role', $analytics['growth']);
        $this->assertArrayHasKey('total_properties', $analytics['growth']);
        $this->assertArrayHasKey('total_units', $analytics['growth']);
    }
}
