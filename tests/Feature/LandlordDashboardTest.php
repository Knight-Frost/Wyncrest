<?php

namespace Tests\Feature;

use App\Enums\UnitAvailabilityStatus;
use App\Models\Application;
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
 * LandlordDashboardTest
 *
 * Covers GET /api/landlord/dashboard:
 * - shape + counts reflect the landlord's seeded data
 * - strict per-landlord isolation
 * - auth (401) and role (403) boundaries
 */
class LandlordDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
    }

    public function test_dashboard_returns_documented_shape(): void
    {
        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'portfolio' => [
                    'total_properties',
                    'total_units',
                    'occupied_units',
                    'vacant_units',
                    'active_listings',
                    'draft_listings',
                    'pending_review_listings',
                ],
                'contracts' => ['active', 'pending_tenant', 'draft', 'expiring_soon'],
                'applications' => ['awaiting_review'],
                'maintenance' => ['open', 'in_progress'],
                'ledger' => [
                    'outstanding_cents',
                    'overdue_cents',
                    'collected_this_month_cents',
                    'next_due_date',
                ],
                'rent_trend' => [['month', 'collected_cents', 'outstanding_cents']],
                'recent_applications',
                'recent_maintenance',
                'recent_listings',
            ]);

        // rent_trend always returns exactly 6 months (oldest → newest).
        $this->assertCount(6, $response->json('rent_trend'));
    }

    public function test_dashboard_counts_reflect_seeded_data(): void
    {
        // Freeze time to early in a month so the "due this month" ledger entries
        // below are deterministic. The suite's DB/now() run in UTC, so without
        // this the pending entry (due on the 6th) reads as overdue whenever the
        // test runs after midnight UTC on/after the 6th. The global tearDown in
        // Tests\TestCase resets setTestNow, so this does not leak.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 6, 4, 12));

        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);

        // Units: 1 occupied, 2 available, 1 maintenance (neither occupied nor vacant)
        Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::OCCUPIED,
        ]);
        Unit::factory()->count(2)->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);
        Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::MAINTENANCE,
        ]);

        // Listings: 1 active, 1 draft, 1 pending review.
        // The listing unit is set to MAINTENANCE so it counts toward total_units
        // without skewing occupied/vacant (which would default to AVAILABLE).
        $listingUnit = Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::MAINTENANCE,
        ]);
        Listing::factory()->active()->create([
            'unit_id' => $listingUnit->id,
            'landlord_id' => $this->landlord->id,
        ]);
        Listing::factory()->draft()->create([
            'unit_id' => $listingUnit->id,
            'landlord_id' => $this->landlord->id,
        ]);
        Listing::factory()->pendingReview()->create([
            'unit_id' => $listingUnit->id,
            'landlord_id' => $this->landlord->id,
        ]);

        // Contracts: 1 active, 1 pending tenant, 1 draft
        Contract::factory()->active()->create(['landlord_id' => $this->landlord->id]);
        Contract::factory()->pendingTenant()->create(['landlord_id' => $this->landlord->id]);
        Contract::factory()->draft()->create(['landlord_id' => $this->landlord->id]);

        // Applications: 2 active (awaiting review), 1 approved (final, excluded)
        Application::factory()->count(2)->create(['landlord_id' => $this->landlord->id]);
        Application::factory()->approved()->create(['landlord_id' => $this->landlord->id]);

        // Maintenance: 1 open, 1 in_progress, 1 resolved (excluded)
        MaintenanceRequest::factory()->create(['landlord_id' => $this->landlord->id]);
        MaintenanceRequest::factory()->inProgress()->create(['landlord_id' => $this->landlord->id]);
        MaintenanceRequest::factory()->resolved()->create(['landlord_id' => $this->landlord->id]);

        // Ledger: pending 100000 (due this month), overdue 50000, paid 70000 (due this month)
        LedgerEntry::factory()->create([
            'landlord_id' => $this->landlord->id,
            'status' => 'pending',
            'amount_cents' => 100000,
            'due_date' => now()->startOfMonth()->addDays(5),
        ]);
        LedgerEntry::factory()->overdue()->create([
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 50000,
        ]);
        LedgerEntry::factory()->paid()->create([
            'landlord_id' => $this->landlord->id,
            'amount_cents' => 70000,
            'due_date' => now()->startOfMonth()->addDays(3),
        ]);
        // A PAYMENT receipt (stored as a negative amount) due this month must NOT
        // affect collected_this_month_cents, which is scoped to RENT obligations.
        LedgerEntry::factory()->paid()->create([
            'landlord_id' => $this->landlord->id,
            'type' => 'payment',
            'amount_cents' => -70000,
            'due_date' => now()->startOfMonth()->addDays(3),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('portfolio.total_properties', 1)
            ->assertJsonPath('portfolio.total_units', 5)
            ->assertJsonPath('portfolio.occupied_units', 1)
            ->assertJsonPath('portfolio.vacant_units', 2)
            ->assertJsonPath('portfolio.active_listings', 1)
            ->assertJsonPath('portfolio.draft_listings', 1)
            ->assertJsonPath('portfolio.pending_review_listings', 1)
            ->assertJsonPath('contracts.active', 1)
            ->assertJsonPath('contracts.pending_tenant', 1)
            ->assertJsonPath('contracts.draft', 1)
            ->assertJsonPath('applications.awaiting_review', 2)
            ->assertJsonPath('maintenance.open', 1)
            ->assertJsonPath('maintenance.in_progress', 1)
            // outstanding = pending(100000) + overdue(50000)
            ->assertJsonPath('ledger.outstanding_cents', 150000)
            ->assertJsonPath('ledger.overdue_cents', 50000)
            ->assertJsonPath('ledger.collected_this_month_cents', 70000)
            ->assertJsonPath('ledger.next_due_date', now()->startOfMonth()->addDays(5)->format('Y-m-d'));

        // recent_applications includes every application (3: 2 active + 1 approved).
        $this->assertCount(3, $response->json('recent_applications'));
        // recent_maintenance includes every request (3: open + in_progress + resolved).
        $this->assertCount(3, $response->json('recent_maintenance'));
    }

    public function test_dashboard_is_scoped_to_the_authenticated_landlord(): void
    {
        // Other landlord with their own data.
        $other = User::factory()->landlord()->create();
        $otherProperty = Property::factory()->create(['landlord_id' => $other->id]);
        Unit::factory()->count(3)->create([
            'property_id' => $otherProperty->id,
            'availability_status' => UnitAvailabilityStatus::OCCUPIED,
        ]);
        Contract::factory()->active()->create(['landlord_id' => $other->id]);
        Application::factory()->create(['landlord_id' => $other->id]);
        MaintenanceRequest::factory()->create(['landlord_id' => $other->id]);
        LedgerEntry::factory()->create([
            'landlord_id' => $other->id,
            'status' => 'pending',
            'amount_cents' => 999000,
        ]);

        // Authenticated landlord has a single property with one available unit.
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        Unit::factory()->create([
            'property_id' => $property->id,
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->getJson('/api/landlord/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('portfolio.total_properties', 1)
            ->assertJsonPath('portfolio.total_units', 1)
            ->assertJsonPath('portfolio.occupied_units', 0)
            ->assertJsonPath('portfolio.vacant_units', 1)
            ->assertJsonPath('contracts.active', 0)
            ->assertJsonPath('applications.awaiting_review', 0)
            ->assertJsonPath('maintenance.open', 0)
            ->assertJsonPath('ledger.outstanding_cents', 0);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/landlord/dashboard')->assertStatus(401);
    }

    public function test_tenant_cannot_access_landlord_dashboard(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/landlord/dashboard')->assertStatus(403);
    }
}
