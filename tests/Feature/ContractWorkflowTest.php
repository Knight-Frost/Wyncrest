<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\TerminatedBy;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\Listing;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ContractWorkflowTest
 *
 * Tests complete contract workflows:
 * - Happy path (create → send → accept → terminate)
 * - Unauthorized access
 * - Duplicate listing prevention
 * - Admin forced termination with audit
 */
class ContractWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $landlord;

    protected User $tenant;

    protected Admin $admin;

    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::factory()->landlord()->create();
        $this->tenant = User::factory()->tenant()->create();
        $this->admin = Admin::factory()->create();

        // Create a listing owned by landlord
        $property = Property::factory()->create(['landlord_id' => $this->landlord->id]);
        $unit = Unit::factory()->create(['property_id' => $property->id]);
        $this->listing = Listing::factory()->active()->create([
            'unit_id' => $unit->id,
            'landlord_id' => $this->landlord->id,
        ]);
    }

    public function test_happy_path_contract_workflow()
    {
        // Step 1: Landlord creates contract
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/contracts', [
                'listing_id' => $this->listing->id,
                'tenant_id' => $this->tenant->id,
                'rent_amount' => 250000, // $2500 in cents
                'payment_day' => 1,
                'start_date' => now()->addDays(7)->format('Y-m-d'),
                'end_date' => now()->addYear()->format('Y-m-d'),
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Contract created as draft',
            ]);

        $contractId = $response->json('contract.id');

        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'status' => ContractStatus::DRAFT->value,
            'listing_id' => $this->listing->id,
        ]);

        // Step 2: Landlord sends contract to tenant
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/contracts/{$contractId}/send");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Contract sent to tenant',
            ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'status' => ContractStatus::PENDING_TENANT->value,
        ]);

        // Step 3: Tenant accepts contract
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson("/api/tenant/contracts/{$contractId}/accept");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Contract accepted and activated',
            ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'status' => ContractStatus::ACTIVE->value,
        ]);

        // Step 4: Tenant terminates contract
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson("/api/tenant/contracts/{$contractId}/terminate", [
                'reason' => 'Moving to another city for work relocation',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'status' => ContractStatus::TERMINATED->value,
            'terminated_by' => TerminatedBy::TENANT->value,
        ]);
    }

    public function test_landlord_can_create_contract_by_tenant_email()
    {
        // The UI identifies the tenant by email; the backend resolves it to a
        // real tenant id (tenant_id is no longer required from the client).
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/contracts', [
                'listing_id' => $this->listing->id,
                'tenant_email' => $this->tenant->email,
                'rent_amount' => 250000,
                'payment_day' => 1,
                'start_date' => now()->addDays(7)->format('Y-m-d'),
                'end_date' => now()->addYear()->format('Y-m-d'),
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('contracts', [
            'id' => $response->json('contract.id'),
            'tenant_id' => $this->tenant->id,
            'listing_id' => $this->listing->id,
        ]);
    }

    public function test_create_contract_with_unknown_email_returns_clear_error()
    {
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/contracts', [
                'listing_id' => $this->listing->id,
                'tenant_email' => 'nobody@nowhere.test',
                'rent_amount' => 250000,
                'payment_day' => 1,
                'start_date' => now()->addDays(7)->format('Y-m-d'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tenant_email']);
    }

    public function test_create_contract_by_email_rejects_non_tenant_account()
    {
        // A landlord's email must not resolve to a tenant_id.
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/contracts', [
                'listing_id' => $this->listing->id,
                'tenant_email' => $this->landlord->email,
                'rent_amount' => 250000,
                'payment_day' => 1,
                'start_date' => now()->addDays(7)->format('Y-m-d'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tenant_email']);
    }

    public function test_duplicate_listing_prevention()
    {
        // Create first contract
        Contract::factory()->create([
            'listing_id' => $this->listing->id,
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
        ]);

        // Attempt to create second contract for same listing
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/contracts', [
                'listing_id' => $this->listing->id,
                'tenant_id' => $this->tenant->id,
                'rent_amount' => 250000,
                'payment_day' => 1,
                'start_date' => now()->addDays(7)->format('Y-m-d'),
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'This listing already has a contract',
            ]);
    }

    public function test_tenant_cannot_create_contract()
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson('/api/landlord/contracts', [
                'listing_id' => $this->listing->id,
                'tenant_id' => $this->tenant->id,
                'rent_amount' => 250000,
                'payment_day' => 1,
                'start_date' => now()->addDays(7)->format('Y-m-d'),
            ]);

        $response->assertStatus(403);
    }

    public function test_landlord_cannot_access_tenant_routes()
    {
        $contract = Contract::factory()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->landlord, 'sanctum')
            ->getJson('/api/tenant/contracts');

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_view_contract()
    {
        $otherUser = User::factory()->landlord()->create();

        $contract = Contract::factory()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/landlord/contracts/{$contract->id}");

        $response->assertStatus(403);
    }

    public function test_admin_forced_termination_with_audit()
    {
        $contract = Contract::factory()->active()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/contracts/{$contract->id}/terminate", [
                'reason' => 'Contract terminated due to violation of community guidelines and repeated complaints from neighbors',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Contract terminated by admin',
            ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => ContractStatus::TERMINATED->value,
            'terminated_by' => TerminatedBy::ADMIN->value,
            'admin_id' => $this->admin->id,
        ]);

        // Verify audit log exists
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'contract_force_terminated',
            'subject_type' => 'App\Models\Contract',
            'subject_id' => $contract->id,
            'severity' => 'critical',
        ]);
    }

    public function test_cannot_terminate_non_active_contract()
    {
        $contract = Contract::factory()->draft()->create([
            'landlord_id' => $this->landlord->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson("/api/landlord/contracts/{$contract->id}/terminate", [
                'reason' => 'Test termination',
            ]);

        $response->assertStatus(403);
    }

    public function test_payment_day_validation()
    {
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/contracts', [
                'listing_id' => $this->listing->id,
                'tenant_id' => $this->tenant->id,
                'rent_amount' => 250000,
                'payment_day' => 29, // Invalid - must be 1-28
                'start_date' => now()->addDays(7)->format('Y-m-d'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('payment_day');
    }

    public function test_start_date_cannot_be_in_past()
    {
        $response = $this->actingAs($this->landlord, 'sanctum')
            ->postJson('/api/landlord/contracts', [
                'listing_id' => $this->listing->id,
                'tenant_id' => $this->tenant->id,
                'rent_amount' => 250000,
                'payment_day' => 1,
                'start_date' => now()->subDays(1)->format('Y-m-d'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_date');
    }
}
