<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Property;
use App\Models\Unit;
use App\Models\Listing;
use App\Models\Contract;
use App\Enums\ContractStatus;
use App\Enums\ListingStatus;
use App\Services\Analytics\ContractAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class ContractAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tenant;
    protected User $landlord;
    protected Property $property;
    protected ContractAnalyticsService $analyticsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = User::factory()->tenant()->create();
        $this->landlord = User::factory()->landlord()->create();
        $this->admin = $this->landlord;

        $this->property = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
        ]);

        $this->analyticsService = app(ContractAnalyticsService::class);
    }

    protected function createContractWithListing(array $contractAttributes = []): Contract
    {
        $unit = Unit::factory()->create([
            'property_id' => $this->property->id,
        ]);

        $listing = Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);

        $defaults = [
            'listing_id' => $listing->id,
            'landlord_id' => $this->landlord->id,
        ];
        
        if (!isset($contractAttributes['tenant_id'])) {
            $defaults['tenant_id'] = $this->tenant->id;
        }

        return Contract::factory()->create(array_merge($defaults, $contractAttributes));
    }

    public function test_contract_counts_by_status()
    {
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::TERMINATED]);
        $this->createContractWithListing(['status' => ContractStatus::EXPIRED]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEquals(3, $analytics['total_contracts']);
        $this->assertEquals(1, $analytics['active_contracts']);
        $this->assertEquals(1, $analytics['terminated_contracts']);
        $this->assertEquals(1, $analytics['expired_contracts']);
    }

    public function test_contracts_by_status_grouping()
    {
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::EXPIRED]);
        $this->createContractWithListing(['status' => ContractStatus::EXPIRED]);
        $this->createContractWithListing(['status' => ContractStatus::EXPIRED]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertIsArray($analytics['contracts_by_status']);
        $this->assertEquals(2, $analytics['contracts_by_status']['active']);
        $this->assertEquals(3, $analytics['contracts_by_status']['expired']);
    }

    public function test_average_contract_duration_calculation()
    {
        $this->createContractWithListing([
            'start_date' => Carbon::parse('2024-01-01'),
            'end_date' => Carbon::parse('2024-12-31'),
            'status' => ContractStatus::ACTIVE,
        ]);

        $this->createContractWithListing([
            'start_date' => Carbon::parse('2024-01-01'),
            'end_date' => Carbon::parse('2024-06-29'),
            'status' => ContractStatus::EXPIRED,
        ]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEqualsWithDelta(272.5, $analytics['average_contract_duration_days'], 1);
    }

    public function test_early_termination_rate_calculation()
    {
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::TERMINATED]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEqualsWithDelta(33.33, $analytics['early_termination_rate'], 0.1);
    }

    public function test_renewal_rate_calculation()
    {
        $tenant1 = User::factory()->tenant()->create();
        
        $this->createContractWithListing([
            'tenant_id' => $tenant1->id,
            'status' => ContractStatus::EXPIRED,
        ]);

        $this->createContractWithListing([
            'tenant_id' => $tenant1->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $tenant2 = User::factory()->tenant()->create();
        
        $this->createContractWithListing([
            'tenant_id' => $tenant2->id,
            'status' => ContractStatus::EXPIRED,
        ]);

        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEqualsWithDelta(50.0, $analytics['renewal_rate'], 0.1);
    }

    public function test_date_filters_work()
    {
        $this->createContractWithListing([
            'status' => ContractStatus::EXPIRED,
            'created_at' => Carbon::now()->subMonths(6),
        ]);

        $this->createContractWithListing([
            'status' => ContractStatus::ACTIVE,
            'created_at' => Carbon::now(),
        ]);

        $analytics = $this->analyticsService->getAnalytics([
            'start_date' => Carbon::now()->subDays(7),
        ]);

        $this->assertEquals(1, $analytics['total_contracts']);
        $this->assertEquals(1, $analytics['active_contracts']);
    }

    public function test_property_scoping_is_enforced()
    {
        $property2 = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
        ]);

        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);

        $unit2 = Unit::factory()->create(['property_id' => $property2->id]);
        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);
        Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $this->tenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $analytics = $this->analyticsService->getAnalytics([
            'property_id' => $this->property->id,
        ]);

        $this->assertEquals(1, $analytics['total_contracts']);
    }

    public function test_tenant_isolation_is_enforced()
    {
        $tenant2 = User::factory()->tenant()->create();

        $this->createContractWithListing([
            'tenant_id' => $this->tenant->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $this->createContractWithListing([
            'tenant_id' => $tenant2->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $analytics = $this->analyticsService->getAnalytics([
            'user_id' => $this->tenant->id,
        ]);

        $this->assertEquals(1, $analytics['total_contracts']);
    }

    public function test_admin_can_access_contract_analytics_api()
    {
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/contracts');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'analytics' => [
                'total_contracts',
                'active_contracts',
                'terminated_contracts',
                'expired_contracts',
                'contracts_by_status',
                'average_contract_duration_days',
                'early_termination_rate',
                'renewal_rate',
            ],
        ]);
    }

    public function test_tenant_sees_only_personal_contracts()
    {
        // Use a BRAND NEW tenant for this test only
        $myTenant = User::factory()->tenant()->create();
        
        // Create myTenant's contract
        $unit1 = Unit::factory()->create(['property_id' => $this->property->id]);
        $listing1 = Listing::factory()->create([
            'unit_id' => $unit1->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);
        
        Contract::factory()->create([
            'listing_id' => $listing1->id,
            'tenant_id' => $myTenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        // Create other tenant's contract
        $otherTenant = User::factory()->tenant()->create();
        $unit2 = Unit::factory()->create(['property_id' => $this->property->id]);
        $listing2 = Listing::factory()->create([
            'unit_id' => $unit2->id,
            'landlord_id' => $this->landlord->id,
            'status' => ListingStatus::ACTIVE,
        ]);
        
        Contract::factory()->create([
            'listing_id' => $listing2->id,
            'tenant_id' => $otherTenant->id,
            'landlord_id' => $this->landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        $response = $this->actingAs($myTenant, 'sanctum')
            ->getJson('/api/analytics/contracts');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('analytics.total_contracts'));
        $this->assertEquals('personal', $response->json('scoped_to'));
    }

    public function test_unauthenticated_user_cannot_access_contract_analytics()
    {
        $response = $this->getJson('/api/analytics/contracts');
        $response->assertStatus(401);
    }

    public function test_contract_analytics_validates_date_filters()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/contracts?start_date=invalid');

        $response->assertStatus(422);
    }

    public function test_end_date_must_be_after_start_date()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/analytics/contracts?start_date=2025-12-31&end_date=2025-01-01');

        $response->assertStatus(422);
    }

    public function test_contract_analytics_does_not_mutate_data()
    {
        $contract = $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);

        $originalStatus = $contract->status;
        $originalCreatedAt = $contract->created_at;
        $originalUpdatedAt = $contract->updated_at;

        $this->analyticsService->getAnalytics();
        $this->analyticsService->getAnalytics();

        $contract->refresh();

        $this->assertEquals($originalStatus, $contract->status);
        $this->assertEquals($originalCreatedAt, $contract->created_at);
        $this->assertEquals($originalUpdatedAt, $contract->updated_at);
    }

    public function test_contract_analytics_returns_deterministic_results()
    {
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);
        $this->createContractWithListing(['status' => ContractStatus::ACTIVE]);

        $result1 = $this->analyticsService->getAnalytics();
        $result2 = $this->analyticsService->getAnalytics();

        $this->assertEquals($result1, $result2);
    }

    public function test_empty_data_returns_zero_metrics()
    {
        $analytics = $this->analyticsService->getAnalytics();

        $this->assertEquals(0, $analytics['total_contracts']);
        $this->assertEquals(0, $analytics['active_contracts']);
        $this->assertEquals(0.0, $analytics['average_contract_duration_days']);
        $this->assertEquals(0.0, $analytics['early_termination_rate']);
    }
}
