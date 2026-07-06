<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Application;
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
 * LandlordExportTest
 *
 * Feature 4: landlord-scoped CSV exports for ledger, listings and applications.
 * Each export returns text/csv with a header + data rows, is strictly scoped to
 * the owning landlord, and is blocked for non-landlords.
 */
class LandlordExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Property $property;

    protected Unit $unit;

    protected Listing $listing;

    protected Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create([
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'email' => 'ama.tenant@example.com',
        ]);

        $this->property = Property::factory()->create([
            'landlord_id' => $this->landlord->id,
            'name' => 'Cantonments Court',
        ]);
        $this->unit = Unit::factory()->create([
            'property_id' => $this->property->id,
            'unit_number' => 'A1',
            'rent_amount' => 1500.00,
        ]);
        $this->listing = Listing::factory()->active()->create([
            'unit_id' => $this->unit->id,
            'landlord_id' => $this->landlord->id,
            'title' => 'Bright 2-bed in Cantonments',
        ]);
        $this->contract = Contract::factory()->active()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
        ]);
    }

    private function csvBody($response): string
    {
        return $response->streamedContent();
    }

    // ── Ledger export ────────────────────────────────────────────────────────

    public function test_ledger_export_returns_csv_with_data(): void
    {
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => 150000,
            'due_date' => now(),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->get('/api/landlord/ledger/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $body = $this->csvBody($response);
        $this->assertStringContainsString('Date,Tenant,Unit,Property,Type,Direction,Reference,Amount,', $body);
        $this->assertStringContainsString('Balance Impact', $body);
        $this->assertStringContainsString('Balance after', $body);
        $this->assertStringContainsString('Ama Mensah', $body);
        $this->assertStringContainsString('Cantonments Court', $body);
        $this->assertStringContainsString('1500.00', $body);
    }

    public function test_ledger_export_excludes_other_landlords_data(): void
    {
        // This landlord's entry.
        LedgerEntry::factory()->create([
            'contract_id' => $this->contract->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PENDING,
            'amount_cents' => 100000,
            'due_date' => now(),
        ]);

        // Another landlord with their own tenant + entry.
        $other = User::factory()->landlord()->create();
        $otherTenant = User::factory()->tenant()->create([
            'first_name' => 'Kojo',
            'last_name' => 'Asante',
        ]);
        $otherContract = Contract::factory()->active()->create([
            'landlord_id' => $other->id,
            'tenant_id' => $otherTenant->id,
        ]);
        LedgerEntry::factory()->create([
            'contract_id' => $otherContract->id,
            'landlord_id' => $other->id,
            'tenant_id' => $otherTenant->id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => 777000,
            'due_date' => now(),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $body = $this->csvBody($this->get('/api/landlord/ledger/export'));

        $this->assertStringContainsString('Ama Mensah', $body);
        $this->assertStringNotContainsString('Kojo Asante', $body);
        $this->assertStringNotContainsString('7770.00', $body);
    }

    public function test_ledger_export_blocked_for_tenant(): void
    {
        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $this->get('/api/landlord/ledger/export')->assertStatus(403);
    }

    // ── Listings export ────────────────────────────────────────────────────────

    public function test_listings_export_returns_csv_with_data(): void
    {
        Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->get('/api/landlord/listings/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $this->csvBody($response);
        $this->assertStringContainsString('Title,Property,Unit,Status,Rent,Views,Applications,Featured,Updated', $body);
        $this->assertStringContainsString('Bright 2-bed in Cantonments', $body);
        $this->assertStringContainsString('1500.00', $body);
    }

    public function test_listings_export_excludes_other_landlords_data(): void
    {
        $other = User::factory()->landlord()->create();
        $otherProperty = Property::factory()->create(['landlord_id' => $other->id]);
        $otherUnit = Unit::factory()->create(['property_id' => $otherProperty->id]);
        Listing::factory()->active()->create([
            'unit_id' => $otherUnit->id,
            'landlord_id' => $other->id,
            'title' => 'Secret Other Listing XYZ',
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $body = $this->csvBody($this->get('/api/landlord/listings/export'));

        $this->assertStringContainsString('Bright 2-bed in Cantonments', $body);
        $this->assertStringNotContainsString('Secret Other Listing XYZ', $body);
    }

    public function test_listings_export_blocked_for_tenant(): void
    {
        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $this->get('/api/landlord/listings/export')->assertStatus(403);
    }

    // ── Applications export ─────────────────────────────────────────────────────

    public function test_applications_export_returns_csv_with_data(): void
    {
        Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $response = $this->get('/api/landlord/applications/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $this->csvBody($response);
        $this->assertStringContainsString('Applicant,Email,Listing,Unit,Status,', $body);
        $this->assertStringContainsString('Readiness %', $body);
        $this->assertStringContainsString('Ama Mensah', $body);
        $this->assertStringContainsString('ama.tenant@example.com', $body);
    }

    public function test_applications_export_excludes_other_landlords_data(): void
    {
        Application::factory()->create([
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
        ]);

        $other = User::factory()->landlord()->create();
        $otherProperty = Property::factory()->create(['landlord_id' => $other->id]);
        $otherUnit = Unit::factory()->create(['property_id' => $otherProperty->id]);
        $otherListing = Listing::factory()->active()->create([
            'unit_id' => $otherUnit->id,
            'landlord_id' => $other->id,
        ]);
        $otherTenant = User::factory()->tenant()->create([
            'email' => 'hidden.other@example.com',
        ]);
        Application::factory()->create([
            'tenant_id' => $otherTenant->id,
            'listing_id' => $otherListing->id,
            'landlord_id' => $other->id,
        ]);

        Sanctum::actingAs($this->landlord, [], 'sanctum');

        $body = $this->csvBody($this->get('/api/landlord/applications/export'));

        $this->assertStringContainsString('ama.tenant@example.com', $body);
        $this->assertStringNotContainsString('hidden.other@example.com', $body);
    }

    public function test_applications_export_blocked_for_tenant(): void
    {
        Sanctum::actingAs($this->tenant, [], 'sanctum');

        $this->get('/api/landlord/applications/export')->assertStatus(403);
    }
}
