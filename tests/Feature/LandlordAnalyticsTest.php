<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\UnitAvailabilityStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LandlordAnalyticsTest
 *
 * Covers GET /api/landlord/analytics and GET /api/landlord/analytics/export:
 * - documented shape
 * - portfolio-wide scoping across MULTIPLE properties (the older generic
 *   analytics controllers only ever looked at the landlord's first property)
 * - strict per-landlord isolation
 * - needs-attention digest surfaces a real overdue tenant
 * - auth (401) and role (403) boundaries
 * - export streams a CSV and writes an audit log entry
 */
class LandlordAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
    }

    public function test_analytics_returns_documented_shape(): void
    {
        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/analytics');

        $response->assertStatus(200)->assertJsonStructure([
            'range' => ['key', 'label', 'from', 'to', 'prev_from', 'prev_to'],
            'summary' => [
                'collected_cents', 'collected_prev_cents', 'expected_cents',
                'outstanding_cents', 'overdue_cents', 'occupied_units', 'total_units', 'occupancy_pct',
            ],
            'financial_trend' => [['month', 'collected_cents', 'expected_cents']],
            'revenue_by_property',
            'occupancy' => [
                'trend' => [['month', 'occupied', 'total', 'occupancy_pct']],
                'unit_status' => ['occupied', 'vacant_listed', 'vacant_draft', 'vacant_unlisted', 'total'],
                'vacancy_by_property',
            ],
            'listings' => ['funnel', 'applications_by_listing', 'status_breakdown'],
            'payments' => [
                'behavior_trend' => [['month', 'on_time', 'late']],
                'aging' => [['bucket', 'amount_cents', 'example']],
                'overdue_tenants',
            ],
            'maintenance' => ['by_status', 'by_category', 'resolution_trend'],
            'needs_attention',
            'properties',
        ]);

        $this->assertCount(6, $response->json('financial_trend'));
        $this->assertCount(6, $response->json('occupancy.trend'));
        $this->assertCount(6, $response->json('payments.behavior_trend'));
        $this->assertCount(6, $response->json('maintenance.resolution_trend'));
        $this->assertCount(4, $response->json('payments.aging'));
    }

    public function test_analytics_aggregates_across_the_landlord_s_full_portfolio(): void
    {
        // Two properties for the SAME landlord — the bug this rebuild fixes
        // is the old generic controllers only ever looking at property #1.
        $propertyA = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $propertyB = Property::factory()->create(['landlord_id' => $this->landlord->id]);

        Unit::factory()->create(['property_id' => $propertyA->id, 'availability_status' => UnitAvailabilityStatus::OCCUPIED]);
        Unit::factory()->create(['property_id' => $propertyB->id, 'availability_status' => UnitAvailabilityStatus::OCCUPIED]);
        Unit::factory()->create(['property_id' => $propertyB->id, 'availability_status' => UnitAvailabilityStatus::AVAILABLE]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/analytics');

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_units', 3)
            ->assertJsonPath('summary.occupied_units', 2);

        $this->assertCount(2, $response->json('properties'));
        $names = collect($response->json('properties'))->pluck('name');
        $this->assertTrue($names->contains($propertyA->name));
        $this->assertTrue($names->contains($propertyB->name));
    }

    public function test_analytics_is_scoped_to_the_authenticated_landlord(): void
    {
        $other = User::factory()->landlord()->create();
        $otherProperty = Property::factory()->create(['landlord_id' => $other->id]);
        Unit::factory()->count(3)->create([
            'property_id' => $otherProperty->id,
            'availability_status' => UnitAvailabilityStatus::OCCUPIED,
        ]);
        MaintenanceRequest::factory()->create(['landlord_id' => $other->id]);

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        Unit::factory()->create(['property_id' => $property->id, 'availability_status' => UnitAvailabilityStatus::AVAILABLE]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/analytics');

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_units', 1)
            ->assertJsonPath('summary.occupied_units', 0);

        $this->assertCount(1, $response->json('properties'));
    }

    public function test_needs_attention_surfaces_an_overdue_tenant(): void
    {
        $tenant = User::factory()->tenant()->create(['first_name' => 'Yaa', 'last_name' => 'Boateng']);
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id, 'availability_status' => UnitAvailabilityStatus::OCCUPIED]);
        $listing = Listing::factory()->active()->create(['unit_id' => $unit->id, 'landlord_id' => $this->landlord->id]);
        $contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $this->landlord->id,
        ]);

        LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $tenant->id,
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 390000,
            'due_date' => now()->subDays(33),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/analytics');

        $response->assertStatus(200);

        $overdue = collect($response->json('payments.overdue_tenants'));
        $this->assertCount(1, $overdue);
        $this->assertEquals(390000, $overdue->first()['overdue_cents']);

        $attention = collect($response->json('needs_attention'));
        $this->assertTrue($attention->contains(fn ($item) => $item['tone'] === 'red' && str_contains($item['title'], 'Yaa Boateng')));
    }

    public function test_needs_attention_surfaces_unassigned_urgent_maintenance(): void
    {
        MaintenanceRequest::factory()->create([
            'landlord_id' => $this->landlord->id,
            'title' => 'Kitchen sink leak',
            'priority' => MaintenancePriority::URGENT,
            'status' => MaintenanceStatus::OPEN,
            'assigned_at' => null,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/analytics');

        $attention = collect($response->json('needs_attention'));
        $this->assertTrue($attention->contains(fn ($item) => str_contains($item['title'], 'Kitchen sink leak')));
    }

    public function test_listings_funnel_reflects_real_applications(): void
    {
        $listing = Listing::factory()->active()->create(['landlord_id' => $this->landlord->id, 'view_count' => 42]);
        Application::factory()->create(['landlord_id' => $this->landlord->id, 'listing_id' => $listing->id, 'status' => ApplicationStatus::SUBMITTED]);
        Application::factory()->approved()->create(['landlord_id' => $this->landlord->id, 'listing_id' => $listing->id]);
        Application::factory()->create(['landlord_id' => $this->landlord->id, 'listing_id' => $listing->id, 'status' => ApplicationStatus::DRAFT]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/analytics');

        $funnel = collect($response->json('listings.funnel'))->keyBy('step');
        $this->assertEquals(42, $funnel['Views']['value']);
        $this->assertEquals(3, $funnel['Applications started']['value']);
        $this->assertEquals(2, $funnel['Applications submitted']['value']);
        $this->assertEquals(1, $funnel['Approved']['value']);
    }

    public function test_collected_includes_a_payment_made_today_for_every_range(): void
    {
        // Regression test: a bare date string ("2026-07-05") as the upper
        // bound of a created_at range compares as LESS than a same-day
        // timestamped row ("2026-07-05 01:26:43"), silently excluding
        // today's payments from "year to date" / "last 90 days" ranges.
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id, 'availability_status' => UnitAvailabilityStatus::OCCUPIED]);
        $listing = Listing::factory()->active()->create(['unit_id' => $unit->id, 'landlord_id' => $this->landlord->id]);
        $contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        LedgerEntry::factory()->paid()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $this->landlord->id,
            'type' => 'payment',
            'amount_cents' => -150000,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        foreach (['this', '90', 'ytd'] as $range) {
            $response = $this->getJson("/api/landlord/analytics?range={$range}");
            $response->assertStatus(200)->assertJsonPath('summary.collected_cents', 150000);
        }
    }

    public function test_analytics_requires_authentication(): void
    {
        $this->getJson('/api/landlord/analytics')->assertStatus(401);
    }

    public function test_tenant_cannot_access_landlord_analytics(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/landlord/analytics')->assertStatus(403);
    }

    public function test_export_streams_csv_and_writes_an_audit_log(): void
    {
        Property::factory()->create(['landlord_id' => $this->landlord->id, 'name' => 'Ridge Heights']);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->get('/api/landlord/analytics/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertNotEmpty($response->headers->get('X-Export-Checksum'));
        $response->assertHeader('X-Export-Row-Count', '1');

        $content = $response->getContent();
        $this->assertEquals(hash('sha256', $content), $response->headers->get('X-Export-Checksum'));
        $this->assertStringContainsString('Ridge Heights', $content);
        $this->assertStringContainsString('SUMMARY', $content);
        $this->assertStringContainsString('FINANCIAL TREND', $content);
        $this->assertStringContainsString('Property,Area,Units,Occupied', $content);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'analytics_exported',
        ]);
        $log = AuditLog::where('action', 'analytics_exported')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->landlord->id, $log->actor_id);
    }

    public function test_export_respects_the_property_filter(): void
    {
        $propertyA = Property::factory()->create(['landlord_id' => $this->landlord->id, 'name' => 'Ridge Heights']);
        $propertyB = Property::factory()->create(['landlord_id' => $this->landlord->id, 'name' => 'Harbor View']);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->get("/api/landlord/analytics/export?property_id={$propertyA->id}");

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Ridge Heights', $content);
        $this->assertStringNotContainsString('Harbor View', $content);
        $this->assertStringContainsString('"Property filter","Ridge Heights"', $content);
    }

    public function test_property_filter_scopes_every_section_and_cannot_see_another_landlords_property(): void
    {
        $propertyA = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $propertyB = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        Unit::factory()->create(['property_id' => $propertyA->id, 'availability_status' => UnitAvailabilityStatus::OCCUPIED]);
        Unit::factory()->create(['property_id' => $propertyB->id, 'availability_status' => UnitAvailabilityStatus::AVAILABLE]);

        $other = User::factory()->landlord()->create();
        $foreignProperty = Property::factory()->create(['landlord_id' => $other->id]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson("/api/landlord/analytics?property_id={$propertyA->id}");
        $response->assertStatus(200)
            ->assertJsonPath('summary.total_units', 1)
            ->assertJsonPath('summary.occupied_units', 1);
        $this->assertCount(1, $response->json('properties'));

        // A property_id belonging to another landlord must never leak that
        // landlord's data — it should simply behave as an empty result set.
        $foreignResponse = $this->getJson("/api/landlord/analytics?property_id={$foreignProperty->id}");
        $foreignResponse->assertStatus(200)
            ->assertJsonPath('summary.total_units', 0)
            ->assertJsonPath('summary.occupied_units', 0);
        $this->assertCount(0, $foreignResponse->json('properties'));
    }

    public function test_needs_attention_items_carry_a_category(): void
    {
        MaintenanceRequest::factory()->create([
            'landlord_id' => $this->landlord->id,
            'priority' => MaintenancePriority::URGENT,
            'status' => MaintenanceStatus::OPEN,
            'assigned_at' => null,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/analytics');

        $attention = collect($response->json('needs_attention'));
        $this->assertTrue($attention->contains(fn ($item) => $item['category'] === 'maintenance'));
    }
}
