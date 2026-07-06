<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MaintenanceWorkflowTest
 *
 * Tests the full maintenance request domain:
 *   - Tenant submits, views, and cancels requests
 *   - Landlord lists and updates request status
 *   - Authorization boundaries enforced throughout
 *
 * Assumed route registrations (supervisor wires these into routes/api.php):
 *
 *   // Tenant
 *   Route::middleware(['auth:sanctum', 'tenant', 'rate.limit.role'])->prefix('tenant')->group(function () {
 *       Route::get('/maintenance', [MaintenanceRequestController::class, 'index']);
 *       Route::post('/maintenance', [MaintenanceRequestController::class, 'store']);
 *       Route::get('/maintenance/{maintenanceRequest}', [MaintenanceRequestController::class, 'show']);
 *       Route::post('/maintenance/{maintenanceRequest}/cancel', [MaintenanceRequestController::class, 'cancel']);
 *   });
 *
 *   // Landlord
 *   Route::middleware(['auth:sanctum', 'landlord', 'rate.limit.role'])->prefix('landlord')->group(function () {
 *       Route::get('/maintenance', [LandlordMaintenanceController::class, 'index']);
 *       Route::patch('/maintenance/{maintenanceRequest}/status', [LandlordMaintenanceController::class, 'updateStatus']);
 *   });
 */
class MaintenanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Build a complete graph (landlord → property → unit → listing → contract)
     * and return all pieces.
     */
    private function buildActiveGraph(): array
    {
        $landlord = User::factory()->landlord()->create();
        $property = Property::factory()->create(['landlord_id' => $landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
        ]);
        $tenant = User::factory()->tenant()->create();
        $contract = Contract::factory()->active()->create([
            'listing_id' => $listing->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
        ]);

        return compact('landlord', 'property', 'unit', 'listing', 'tenant', 'contract');
    }

    private function maintenancePayload(string $contractId, array $overrides = []): array
    {
        return array_merge([
            'contract_id' => $contractId,
            'title' => 'Leaking kitchen tap',
            'description' => 'The kitchen tap has been dripping continuously for two days.',
            'category' => MaintenanceCategory::PLUMBING->value,
            'priority' => MaintenancePriority::MEDIUM->value,
            'area' => \App\Enums\MaintenanceArea::KITCHEN->value,
            'onset' => \App\Enums\MaintenanceOnset::TODAY->value,
            'access_permission' => \App\Enums\MaintenanceAccess::YES->value,
        ], $overrides);
    }

    // ─── Tenant: Create ───────────────────────────────────────────────────────

    /** @test */
    public function test_tenant_with_active_contract_can_file_maintenance_request(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['tenant'], ['*']);

        $response = $this->postJson('/api/tenant/maintenance', $this->maintenancePayload($graph['contract']->id));

        $response->assertStatus(201);

        // Critical: property_id, unit_id, landlord_id must be derived from the
        // contract — not supplied by the client.
        $response->assertJson([
            'property_id' => $graph['property']->id,
            'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
            'tenant_id' => $graph['tenant']->id,
            'status' => MaintenanceStatus::OPEN->value,
        ]);

        $this->assertDatabaseHas('maintenance_requests', [
            'contract_id' => $graph['contract']->id,
            'tenant_id' => $graph['tenant']->id,
            'property_id' => $graph['property']->id,
            'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);
    }

    /** @test */
    public function test_tenant_cannot_file_against_non_active_contract(): void
    {
        $graph = $this->buildActiveGraph();

        // Re-set the contract to a non-active status
        $graph['contract']->update(['status' => ContractStatus::PENDING_TENANT]);

        Sanctum::actingAs($graph['tenant'], ['*']);

        $response = $this->postJson('/api/tenant/maintenance', $this->maintenancePayload($graph['contract']->id));

        $response->assertStatus(422)
            ->assertJson(['message' => 'You can only open maintenance requests against an active lease.']);
    }

    /** @test */
    public function test_tenant_cannot_file_against_another_tenants_contract(): void
    {
        $graph = $this->buildActiveGraph();
        $otherTenant = User::factory()->tenant()->create();

        // otherTenant tries to use graph['contract'] which belongs to graph['tenant']
        Sanctum::actingAs($otherTenant, ['*']);

        $response = $this->postJson('/api/tenant/maintenance', $this->maintenancePayload($graph['contract']->id));

        $response->assertStatus(403);
    }

    // ─── Tenant: Index ────────────────────────────────────────────────────────

    /** @test */
    public function test_tenant_index_returns_only_own_requests(): void
    {
        $graph = $this->buildActiveGraph();
        $graph2 = $this->buildActiveGraph();

        // Create one request for each tenant
        $req1 = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id,
            'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id,
            'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        MaintenanceRequest::factory()->create([
            'tenant_id' => $graph2['tenant']->id,
            'contract_id' => $graph2['contract']->id,
            'property_id' => $graph2['property']->id,
            'unit_id' => $graph2['unit']->id,
            'landlord_id' => $graph2['landlord']->id,
        ]);

        Sanctum::actingAs($graph['tenant'], ['*']);

        $response = $this->getJson('/api/tenant/maintenance');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($req1->id));
    }

    // ─── Tenant: Show ─────────────────────────────────────────────────────────

    /** @test */
    public function test_tenant_cannot_view_another_tenants_request(): void
    {
        $graph = $this->buildActiveGraph();
        $graph2 = $this->buildActiveGraph();

        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph2['tenant']->id,
            'contract_id' => $graph2['contract']->id,
            'property_id' => $graph2['property']->id,
            'unit_id' => $graph2['unit']->id,
            'landlord_id' => $graph2['landlord']->id,
        ]);

        Sanctum::actingAs($graph['tenant'], ['*']);

        $this->getJson("/api/tenant/maintenance/{$req->id}")->assertStatus(403);
    }

    // ─── Tenant: Cancel ───────────────────────────────────────────────────────

    /** @test */
    public function test_tenant_can_cancel_open_request(): void
    {
        $graph = $this->buildActiveGraph();

        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id,
            'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id,
            'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
            'status' => MaintenanceStatus::OPEN->value,
        ]);

        Sanctum::actingAs($graph['tenant'], ['*']);

        $response = $this->postJson("/api/tenant/maintenance/{$req->id}/cancel");

        $response->assertStatus(200)
            ->assertJson(['status' => MaintenanceStatus::CANCELLED->value]);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $req->id,
            'status' => MaintenanceStatus::CANCELLED->value,
        ]);
    }

    /** @test */
    public function test_tenant_cannot_cancel_non_open_request(): void
    {
        $graph = $this->buildActiveGraph();

        // Create a request that is already acknowledged (not cancellable)
        $req = MaintenanceRequest::factory()->inProgress()->create([
            'tenant_id' => $graph['tenant']->id,
            'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id,
            'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        Sanctum::actingAs($graph['tenant'], ['*']);

        $this->postJson("/api/tenant/maintenance/{$req->id}/cancel")->assertStatus(403);
    }

    // ─── Landlord: Index ──────────────────────────────────────────────────────

    /** @test */
    public function test_landlord_index_returns_only_own_requests(): void
    {
        $graph = $this->buildActiveGraph();
        $graph2 = $this->buildActiveGraph();

        $ownReq = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id,
            'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id,
            'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        // Request belonging to another landlord
        MaintenanceRequest::factory()->create([
            'tenant_id' => $graph2['tenant']->id,
            'contract_id' => $graph2['contract']->id,
            'property_id' => $graph2['property']->id,
            'unit_id' => $graph2['unit']->id,
            'landlord_id' => $graph2['landlord']->id,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $response = $this->getJson('/api/landlord/maintenance');

        $response->assertStatus(200);

        $ids = collect($response->json())->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($ownReq->id));
    }

    // ─── Landlord: updateStatus ───────────────────────────────────────────────

    /** @test */
    public function test_landlord_can_update_status_of_own_request(): void
    {
        $graph = $this->buildActiveGraph();

        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id,
            'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id,
            'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
            'status' => MaintenanceStatus::OPEN->value,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $response = $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::IN_PROGRESS->value,
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => MaintenanceStatus::IN_PROGRESS->value]);
    }

    /** @test */
    public function test_landlord_cannot_update_another_landlords_request(): void
    {
        $graph = $this->buildActiveGraph();
        $graph2 = $this->buildActiveGraph();

        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id,
            'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id,
            'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        // A different landlord tries to update the request
        Sanctum::actingAs($graph2['landlord'], ['*']);

        $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::ACKNOWLEDGED->value,
        ])->assertStatus(403);
    }

    // ─── Unauthenticated ─────────────────────────────────────────────────────

    /** @test */
    public function test_unauthenticated_cannot_access_tenant_maintenance(): void
    {
        $this->getJson('/api/tenant/maintenance')->assertStatus(401);
        $this->postJson('/api/tenant/maintenance', [])->assertStatus(401);
    }

    /** @test */
    public function test_unauthenticated_cannot_access_landlord_maintenance(): void
    {
        $this->getJson('/api/landlord/maintenance')->assertStatus(401);
    }

    // ─── Landlord: assign / waiting / resolve / close / reopen ───────────────

    /** @test */
    public function test_landlord_can_assign_a_vendor(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id, 'status' => MaintenanceStatus::OPEN->value,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $response = $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::ASSIGNED->value,
            'assignee_name' => 'AquaFix Plumbers',
            'assignee_phone' => '+233 30 291 4471',
            'assignee_type' => 'vendor',
            'appointment_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => MaintenanceStatus::ASSIGNED->value, 'assignee_name' => 'AquaFix Plumbers']);

        $this->assertDatabaseHas('maintenance_events', [
            'maintenance_request_id' => $req->id,
            'event' => 'assigned',
        ]);
    }

    /** @test */
    public function test_assign_requires_assignee_name(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::ASSIGNED->value,
        ])->assertStatus(422)->assertJsonValidationErrors('assignee_name');
    }

    /** @test */
    public function test_landlord_can_mark_waiting_with_reason(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::WAITING->value,
        ])->assertStatus(422)->assertJsonValidationErrors('waiting_reason');

        $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::WAITING->value,
            'waiting_reason' => 'Waiting on a replacement part.',
        ])->assertStatus(200)->assertJson([
            'status' => MaintenanceStatus::WAITING->value,
            'waiting_reason' => 'Waiting on a replacement part.',
        ]);
    }

    /** @test */
    public function test_landlord_can_resolve_with_costs(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id, 'status' => MaintenanceStatus::IN_PROGRESS->value,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::RESOLVED->value,
        ])->assertStatus(422)->assertJsonValidationErrors('resolution_notes');

        $response = $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::RESOLVED->value,
            'resolution_notes' => 'Replaced the faulty valve and tested.',
            'labor_cost_cents' => 15000,
            'parts_cost_cents' => 5000,
        ]);

        $response->assertStatus(200)->assertJson([
            'status' => MaintenanceStatus::RESOLVED->value,
            'labor_cost_cents' => 15000,
            'parts_cost_cents' => 5000,
            'total_cost_cents' => 20000,
        ]);
    }

    /** @test */
    public function test_landlord_can_close_then_reopen(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->resolved()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::CLOSED->value,
        ])->assertStatus(200)->assertJson(['status' => MaintenanceStatus::CLOSED->value]);

        $req->refresh();
        $this->assertNotNull($req->resolved_at);
        $this->assertNotNull($req->closed_at);

        $response = $this->postJson("/api/landlord/maintenance/{$req->id}/reopen", [
            'reason' => 'The issue returned.',
        ]);

        $response->assertStatus(200)->assertJson(['status' => MaintenanceStatus::ACKNOWLEDGED->value]);

        // History is additive: resolved_at/closed_at are never cleared on reopen.
        $req->refresh();
        $this->assertNotNull($req->resolved_at);
        $this->assertNotNull($req->closed_at);

        $this->assertDatabaseHas('maintenance_events', [
            'maintenance_request_id' => $req->id,
            'event' => 'reopened',
        ]);
    }

    /** @test */
    public function test_cannot_reopen_an_open_request(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id, 'status' => MaintenanceStatus::OPEN->value,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $this->postJson("/api/landlord/maintenance/{$req->id}/reopen", ['reason' => 'x'])
            ->assertStatus(422);
    }

    /** @test */
    public function test_landlord_can_update_costs_after_resolution(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->resolved()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $response = $this->patchJson("/api/landlord/maintenance/{$req->id}/costs", [
            'labor_cost_cents' => 10000,
            'parts_cost_cents' => 2500,
            'invoice_reference' => 'INV-1001',
            'cost_paid' => true,
        ]);

        $response->assertStatus(200)->assertJson([
            'invoice_reference' => 'INV-1001',
            'cost_paid' => true,
        ]);
    }

    // ─── Landlord-authored requests ──────────────────────────────────────────

    /** @test */
    public function test_landlord_can_log_a_request_in_new_mode(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['landlord'], ['*']);

        $response = $this->postJson('/api/landlord/maintenance', [
            'contract_id' => $graph['contract']->id,
            'title' => 'Garden gate hinge rusted',
            'description' => 'Found during routine inspection.',
            'category' => MaintenanceCategory::STRUCTURAL->value,
            'priority' => MaintenancePriority::LOW->value,
            'mode' => 'new',
        ]);

        $response->assertStatus(201)->assertJson([
            'status' => MaintenanceStatus::OPEN->value,
            'reported_by' => 'landlord',
            'tenant_id' => $graph['tenant']->id,
        ]);
    }

    /** @test */
    public function test_landlord_can_log_a_request_already_resolved(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['landlord'], ['*']);

        $response = $this->postJson('/api/landlord/maintenance', [
            'contract_id' => $graph['contract']->id,
            'title' => 'Bulb replaced in corridor',
            'description' => '',
            'category' => MaintenanceCategory::ELECTRICAL->value,
            'priority' => MaintenancePriority::LOW->value,
            'mode' => 'resolved',
        ]);

        $response->assertStatus(201)->assertJson(['status' => MaintenanceStatus::RESOLVED->value]);
    }

    /** @test */
    public function test_landlord_cannot_log_a_request_against_another_landlords_contract(): void
    {
        $graph = $this->buildActiveGraph();
        $otherLandlord = User::factory()->landlord()->create();
        Sanctum::actingAs($otherLandlord, ['*']);

        $this->postJson('/api/landlord/maintenance', [
            'contract_id' => $graph['contract']->id,
            'title' => 'Not my contract',
            'category' => MaintenanceCategory::GENERAL->value,
            'priority' => MaintenancePriority::LOW->value,
            'mode' => 'new',
        ])->assertStatus(403);
    }

    // ─── Messaging ────────────────────────────────────────────────────────────

    /** @test */
    public function test_tenant_and_landlord_can_message_each_other_on_a_request(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        Sanctum::actingAs($graph['tenant'], ['*']);
        $this->postJson("/api/tenant/maintenance/{$req->id}/messages", ['body' => 'Any update?'])
            ->assertStatus(201);

        Sanctum::actingAs($graph['landlord'], ['*']);
        $response = $this->postJson("/api/landlord/maintenance/{$req->id}/messages", ['body' => 'On it today.']);
        $response->assertStatus(201);
        $this->assertCount(2, $response->json('messages'));

        $thread = $this->getJson("/api/landlord/maintenance/{$req->id}/messages");
        $thread->assertStatus(200);
        $this->assertCount(2, $thread->json('messages'));
    }

    /** @test */
    public function test_stranger_cannot_message_on_a_request(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        $otherTenant = User::factory()->tenant()->create();
        Sanctum::actingAs($otherTenant, ['*']);

        $this->postJson("/api/tenant/maintenance/{$req->id}/messages", ['body' => 'Hi'])
            ->assertStatus(403);
    }

    // ─── Media ────────────────────────────────────────────────────────────────

    /** @test */
    public function test_tenant_can_upload_and_view_evidence_photo(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('media.disk_private', 'local'));
        \Illuminate\Support\Facades\Storage::fake(config('media.disk_public', 'public'));

        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        Sanctum::actingAs($graph['tenant'], ['*']);

        $file = \Illuminate\Http\UploadedFile::fake()->image('leak.jpg');
        $response = $this->postJson("/api/tenant/maintenance/{$req->id}/media", ['file' => $file]);
        $response->assertStatus(201);

        $assetId = $response->json('id');

        // Landlord (named on the request) may view it too.
        Sanctum::actingAs($graph['landlord'], ['*']);
        $this->getJson("/api/media/{$assetId}")->assertStatus(200);
    }

    /** @test */
    public function test_stranger_cannot_upload_evidence_photo(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        $otherLandlord = User::factory()->landlord()->create();
        Sanctum::actingAs($otherLandlord, ['*']);

        $file = \Illuminate\Http\UploadedFile::fake()->image('leak.jpg');
        $this->postJson("/api/landlord/maintenance/{$req->id}/media", ['file' => $file])
            ->assertStatus(403);
    }

    // ─── Export ───────────────────────────────────────────────────────────────

    /** @test */
    public function test_landlord_export_returns_csv_with_matching_checksum(): void
    {
        $graph = $this->buildActiveGraph();
        MaintenanceRequest::factory()->count(3)->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $response = $this->get('/api/landlord/maintenance/export?scope=full');

        $response->assertStatus(200);
        $checksum = $response->headers->get('X-Export-Checksum');
        $this->assertNotEmpty($checksum);
        $this->assertSame($checksum, hash('sha256', $response->getContent()));
        $this->assertSame('3', $response->headers->get('X-Export-Row-Count'));
    }

    /** @test */
    public function test_export_is_scoped_to_the_authenticated_landlord(): void
    {
        $graph = $this->buildActiveGraph();
        $graph2 = $this->buildActiveGraph();

        MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id,
        ]);
        MaintenanceRequest::factory()->create([
            'tenant_id' => $graph2['tenant']->id, 'contract_id' => $graph2['contract']->id,
            'property_id' => $graph2['property']->id, 'unit_id' => $graph2['unit']->id,
            'landlord_id' => $graph2['landlord']->id,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $response = $this->get('/api/landlord/maintenance/export?scope=full');

        $response->assertStatus(200);
        $this->assertSame('1', $response->headers->get('X-Export-Row-Count'));
    }

    // ─── Notifications ────────────────────────────────────────────────────────

    /** @test */
    public function test_landlord_is_notified_when_tenant_files_a_request(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['tenant'], ['*']);

        $this->postJson('/api/tenant/maintenance', $this->maintenancePayload($graph['contract']->id))
            ->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $graph['landlord']->id,
            'type' => \App\Enums\NotificationType::MAINTENANCE_REQUEST_SUBMITTED->value,
        ]);
    }

    /** @test */
    public function test_tenant_is_notified_when_landlord_updates_status(): void
    {
        $graph = $this->buildActiveGraph();
        $req = MaintenanceRequest::factory()->create([
            'tenant_id' => $graph['tenant']->id, 'contract_id' => $graph['contract']->id,
            'property_id' => $graph['property']->id, 'unit_id' => $graph['unit']->id,
            'landlord_id' => $graph['landlord']->id, 'status' => MaintenanceStatus::OPEN->value,
        ]);

        Sanctum::actingAs($graph['landlord'], ['*']);

        $this->patchJson("/api/landlord/maintenance/{$req->id}/status", [
            'status' => MaintenanceStatus::ACKNOWLEDGED->value,
        ])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $graph['tenant']->id,
            'type' => \App\Enums\NotificationType::MAINTENANCE_STATUS_UPDATED->value,
        ]);
    }

    // ─── Intake ("repair report") fields ─────────────────────────────────────

    /** @test */
    public function test_intake_fields_are_persisted_and_returned(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['tenant'], ['*']);

        $payload = $this->maintenancePayload($graph['contract']->id, [
            'area' => \App\Enums\MaintenanceArea::BATHROOM->value,
            'specific_location' => 'Behind the toilet cistern',
            'onset' => \App\Enums\MaintenanceOnset::THIS_WEEK->value,
            'safety_flags' => [
                \App\Enums\MaintenanceSafetyFlag::WATER_LEAK->value,
                \App\Enums\MaintenanceSafetyFlag::MOLD->value,
            ],
            'access_permission' => \App\Enums\MaintenanceAccess::CONTACT_FIRST->value,
            'preferred_visit_window' => \App\Enums\MaintenanceVisitWindow::EVENING->value,
            'preferred_contact_method' => \App\Enums\MaintenanceContactMethod::PHONE->value,
            'access_instructions' => 'Please call before arrival. Dog in the bedroom.',
        ]);

        $response = $this->postJson('/api/tenant/maintenance', $payload);

        $response->assertStatus(201)->assertJson([
            'area' => 'bathroom',
            'specific_location' => 'Behind the toilet cistern',
            'onset' => 'this_week',
            'access_permission' => 'contact_first',
            'preferred_visit_window' => 'evening',
            'preferred_contact_method' => 'phone',
            'access_instructions' => 'Please call before arrival. Dog in the bedroom.',
            // Water leak is a severe flag, so the queue indicator is true.
            'has_severe_safety_flag' => true,
        ]);

        $this->assertEqualsCanonicalizing(
            ['water_leak', 'mold'],
            $response->json('safety_flags'),
        );

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $response->json('id'),
            'area' => 'bathroom',
            'onset' => 'this_week',
            'access_permission' => 'contact_first',
        ]);
    }

    /** @test */
    public function test_intake_core_fields_are_required(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['tenant'], ['*']);

        // Strip the three required intake fields.
        $payload = $this->maintenancePayload($graph['contract']->id);
        unset($payload['area'], $payload['onset'], $payload['access_permission']);

        $this->postJson('/api/tenant/maintenance', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area', 'onset', 'access_permission']);
    }

    /** @test */
    public function test_intake_rejects_invalid_enum_and_safety_flag(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['tenant'], ['*']);

        $this->postJson('/api/tenant/maintenance', $this->maintenancePayload($graph['contract']->id, [
            'area' => 'rooftop_helipad',
            'safety_flags' => ['meteor_strike'],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area', 'safety_flags.0']);
    }

    /** @test */
    public function test_no_safety_flags_means_no_severe_indicator(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['tenant'], ['*']);

        $response = $this->postJson('/api/tenant/maintenance', $this->maintenancePayload($graph['contract']->id, [
            'safety_flags' => [],
        ]));

        $response->assertStatus(201)->assertJson(['has_severe_safety_flag' => false]);
    }

    /** @test */
    public function test_tenant_submission_records_a_timeline_event(): void
    {
        $graph = $this->buildActiveGraph();
        Sanctum::actingAs($graph['tenant'], ['*']);

        $response = $this->postJson('/api/tenant/maintenance', $this->maintenancePayload($graph['contract']->id));
        $response->assertStatus(201);

        $this->assertDatabaseHas('maintenance_events', [
            'maintenance_request_id' => $response->json('id'),
            'event' => 'submitted',
        ]);
    }
}
