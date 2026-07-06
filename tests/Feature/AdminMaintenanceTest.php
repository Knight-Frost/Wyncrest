<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Contract;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminMaintenanceTest
 *
 * Covers GET /api/admin/maintenance and /api/admin/maintenance/summary — the
 * first admin-facing view into maintenance requests. Viewing is a baseline
 * admin privilege (no admin.can: capability gate), matching the existing
 * Users/Contracts/Ledger convention, so a scoped admin with zero granted
 * capabilities must still be able to read it.
 */
class AdminMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_admin_can_view_the_maintenance_queue_without_a_capability(): void
    {
        $scopedAdmin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $contract = Contract::factory()->active()->create();

        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $contract->listing->unit->property_id,
            'unit_id' => $contract->listing->unit_id,
            'status' => 'open',
            'priority' => 'urgent',
        ]);

        $this->actingAs($scopedAdmin, 'admin');

        $this->getJson('/api/admin/maintenance')->assertStatus(200);
        $this->getJson('/api/admin/maintenance/summary')->assertStatus(200);
    }

    public function test_summary_counts_urgent_overdue_and_waiting(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $contract = Contract::factory()->active()->create();
        $propertyId = $contract->listing->unit->property_id;
        $unitId = $contract->listing->unit_id;

        // Urgent, open, not overdue.
        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'status' => 'open',
            'priority' => 'urgent',
            'submitted_at' => now()->subDays(1),
        ]);

        // Overdue: still open, expected_completion_date in the past.
        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'status' => 'in_progress',
            'priority' => 'medium',
            'expected_completion_date' => now()->subDays(2)->toDateString(),
            'submitted_at' => now()->subDays(5),
        ]);

        // Waiting.
        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'status' => 'waiting',
            'priority' => 'low',
            'waiting_reason' => 'Waiting on part delivery',
            'submitted_at' => now()->subDays(3),
        ]);

        // Resolved — must not count as open.
        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'status' => 'resolved',
            'priority' => 'high',
            'submitted_at' => now()->subDays(20),
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->getJson('/api/admin/maintenance/summary');

        $response->assertStatus(200)
            ->assertJsonPath('open', 3)
            ->assertJsonPath('urgent', 1)
            ->assertJsonPath('overdue', 1)
            ->assertJsonPath('waiting', 1);

        $this->assertNotNull($response->json('oldest'));
    }

    public function test_status_filter_scopes_the_list(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $contract = Contract::factory()->active()->create();

        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $contract->listing->unit->property_id,
            'unit_id' => $contract->listing->unit_id,
            'status' => 'waiting',
            'priority' => 'low',
        ]);
        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $contract->listing->unit->property_id,
            'unit_id' => $contract->listing->unit_id,
            'status' => 'open',
            'priority' => 'urgent',
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->getJson('/api/admin/maintenance?status=waiting');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('waiting', $response->json('data.0.status'));
    }

    public function test_forbidden_for_non_admin(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/admin/maintenance')->assertStatus(401);
    }
}
