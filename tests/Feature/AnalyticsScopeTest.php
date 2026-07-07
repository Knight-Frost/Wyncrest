<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AnalyticsScopeTest
 *
 * Covers cross-tenant/cross-landlord scoping guarantees on the shared
 * /api/analytics/* endpoints: a landlord may never see another landlord's
 * property data (403 on explicit request, zero totals when unscoped), an
 * admin session must not 500 (Admin has no user_type), and the analytics
 * cache key must not collide across two different landlords.
 */
class AnalyticsScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function landlordWithRevenue(int $rentCents = 500000, ?string $propertyName = null): array
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(array_filter([
            'landlord_id' => $landlord->id,
            'name' => $propertyName,
        ]));
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);
        $contract = Contract::factory()->create([
            'listing_id' => $listing->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $landlord->id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => $rentCents,
        ]);

        return compact('landlord', 'tenant', 'property', 'unit', 'listing', 'contract');
    }

    public function test_landlord_cannot_request_another_landlords_property_financial_analytics(): void
    {
        $a = $this->landlordWithRevenue();
        $b = $this->landlordWithRevenue();

        Sanctum::actingAs($a['landlord'], [], 'sanctum');

        $this->getJson("/api/analytics/financial?property_id={$b['property']->id}")
            ->assertStatus(403);
    }

    public function test_landlord_cannot_request_another_landlords_property_contract_analytics(): void
    {
        $a = $this->landlordWithRevenue();
        $b = $this->landlordWithRevenue();

        Sanctum::actingAs($a['landlord'], [], 'sanctum');

        $this->getJson("/api/analytics/contracts?property_id={$b['property']->id}")
            ->assertStatus(403);
    }

    public function test_landlord_with_zero_properties_gets_scoped_empty_financial_result(): void
    {
        // Another landlord has real revenue...
        $this->landlordWithRevenue();

        // ...but this landlord owns nothing, so their unscoped request must
        // not fall through to a platform-wide total.
        $empty = User::factory()->landlord()->create();
        Sanctum::actingAs($empty, [], 'sanctum');

        $response = $this->getJson('/api/analytics/financial')->assertOk();

        $this->assertEquals(0, $response->json('analytics.revenue.total_payments_received'));
    }

    public function test_two_landlords_with_no_query_params_do_not_share_a_cached_financial_payload(): void
    {
        $a = $this->landlordWithRevenue(500000);
        $b = $this->landlordWithRevenue(120000);

        Sanctum::actingAs($a['landlord'], [], 'sanctum');
        $aResponse = $this->getJson('/api/analytics/financial')->assertOk();

        Sanctum::actingAs($b['landlord'], [], 'sanctum');
        $bResponse = $this->getJson('/api/analytics/financial')->assertOk();

        $this->assertEquals(5000, $aResponse->json('analytics.revenue.total_payments_received'));
        $this->assertEquals(1200, $bResponse->json('analytics.revenue.total_payments_received'));
        $this->assertNotEquals(
            $aResponse->json('analytics.revenue.total_payments_received'),
            $bResponse->json('analytics.revenue.total_payments_received')
        );
    }

    public function test_admin_session_can_load_platform_financial_analytics(): void
    {
        $this->landlordWithRevenue();

        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin, 'admin');

        $this->getJson('/api/admin/analytics/financial')->assertOk();
    }

    /**
     * Regression for the CRITICAL cross-landlord revenue leak: getRevenueByProperty()
     * built its own joined query and never re-applied ownership scoping, so any
     * authenticated caller could see every landlord's property names + paid-rent totals.
     */
    public function test_revenue_by_property_does_not_leak_another_landlords_property(): void
    {
        $a = $this->landlordWithRevenue(500000, 'Ridge Heights A');
        $b = $this->landlordWithRevenue(300000, 'Harbor View B');

        Sanctum::actingAs($a['landlord'], [], 'sanctum');
        $response = $this->getJson('/api/analytics/financial')->assertOk();

        $byProperty = $response->json('analytics.revenue.revenue_by_property');
        $this->assertArrayHasKey('Ridge Heights A', $byProperty);
        $this->assertArrayNotHasKey('Harbor View B', $byProperty);

        // A tenant caller must likewise only ever see their own property's revenue.
        Sanctum::actingAs($a['tenant'], [], 'sanctum');
        $tenantResponse = $this->getJson('/api/analytics/financial')->assertOk();
        $tenantByProperty = $tenantResponse->json('analytics.revenue.revenue_by_property');
        $this->assertArrayHasKey('Ridge Heights A', $tenantByProperty);
        $this->assertArrayNotHasKey('Harbor View B', $tenantByProperty);
    }

    /**
     * Regression for the "first property only" scoping collapse: a landlord with
     * multiple properties, each carrying outstanding rent, must see the SUM across
     * their whole portfolio, not just whichever property happened to resolve first.
     */
    public function test_multi_property_landlord_sees_outstanding_summed_across_the_whole_portfolio(): void
    {
        $landlord = User::factory()->landlord()->create();

        $propertyA = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unitA = Unit::factory()->create(['property_id' => $propertyA->id]);
        $listingA = Listing::factory()->create(['unit_id' => $unitA->id, 'landlord_id' => $landlord->id]);
        $contractA = Contract::factory()->create([
            'listing_id' => $listingA->id,
            'landlord_id' => $landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);
        LedgerEntry::factory()->create([
            'contract_id' => $contractA->id,
            'tenant_id' => $contractA->tenant_id,
            'landlord_id' => $landlord->id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PENDING,
            'amount_cents' => 400000,
        ]);

        $propertyB = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unitB = Unit::factory()->create(['property_id' => $propertyB->id]);
        $listingB = Listing::factory()->create(['unit_id' => $unitB->id, 'landlord_id' => $landlord->id]);
        $contractB = Contract::factory()->create([
            'listing_id' => $listingB->id,
            'landlord_id' => $landlord->id,
            'status' => ContractStatus::ACTIVE,
        ]);
        LedgerEntry::factory()->create([
            'contract_id' => $contractB->id,
            'tenant_id' => $contractB->tenant_id,
            'landlord_id' => $landlord->id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PENDING,
            'amount_cents' => 250000,
        ]);

        Sanctum::actingAs($landlord, [], 'sanctum');

        $response = $this->getJson('/api/analytics/financial')->assertOk();

        // 400000 + 250000 cents = 6500.00, not either property's total alone.
        $this->assertEquals(6500.0, $response->json('analytics.outstanding.total_outstanding_balance'));
    }
}
