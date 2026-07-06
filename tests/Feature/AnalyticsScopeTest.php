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

    protected function landlordWithRevenue(int $rentCents = 500000): array
    {
        $landlord = User::factory()->landlord()->create();
        $tenant = User::factory()->tenant()->create();

        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
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
}
