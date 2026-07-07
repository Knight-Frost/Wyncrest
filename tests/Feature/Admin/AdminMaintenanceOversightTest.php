<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Contract;
use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers GET /api/admin/maintenance/oversight — platform-wide aggregates
 * restricted to super admins. Proves the gate is tier-based (super admin),
 * not capability-based: a regular admin holding manage_maintenance still
 * cannot reach it, since cross-landlord aggregate oversight is a materially
 * different privilege than managing an individual case.
 */
class AdminMaintenanceOversightTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(array $overrides = []): MaintenanceRequest
    {
        $contract = Contract::factory()->active()->create();

        return MaintenanceRequest::factory()->create(array_merge([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $contract->listing->unit->property_id,
            'unit_id' => $contract->listing->unit_id,
            'status' => 'open',
        ], $overrides));
    }

    public function test_regular_admin_with_manage_maintenance_is_still_forbidden(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_maintenance']]);

        $this->actingAs($admin, 'admin');

        $this->getJson('/api/admin/maintenance/oversight')->assertStatus(403);
    }

    public function test_super_admin_sees_real_grouped_aggregates(): void
    {
        $superAdmin = Admin::factory()->create(['is_super_admin' => true]);
        $handlingAdmin = Admin::factory()->create();

        // Same landlord, two overdue open requests => a real "repeat overdue" row.
        $landlordContractA = Contract::factory()->active()->create();
        $landlordContractB = Contract::factory()->active()->create([
            'landlord_id' => $landlordContractA->landlord_id,
        ]);

        foreach ([$landlordContractA, $landlordContractB] as $contract) {
            MaintenanceRequest::factory()->create([
                'tenant_id' => $contract->tenant_id,
                'landlord_id' => $contract->landlord_id,
                'contract_id' => $contract->id,
                'property_id' => $contract->listing->unit->property_id,
                'unit_id' => $contract->listing->unit_id,
                'status' => 'in_progress',
                'expected_completion_date' => now()->subDays(3)->toDateString(),
                'handling_admin_id' => $handlingAdmin->id,
            ]);
        }

        // One severe safety flag, open.
        $this->makeRequest(['safety_flags' => ['no_power']]);

        $this->actingAs($superAdmin, 'admin');

        $response = $this->getJson('/api/admin/maintenance/oversight')->assertStatus(200);

        $this->assertGreaterThanOrEqual(3, $response->json('open_platform_wide'));

        $repeatOverdue = collect($response->json('landlords_with_repeat_overdue'));
        $row = $repeatOverdue->firstWhere('landlord_id', $landlordContractA->landlord_id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['overdue_count']);

        $caseload = collect($response->json('admin_caseload'));
        $adminRow = $caseload->firstWhere('admin_id', $handlingAdmin->id);
        $this->assertNotNull($adminRow);
        $this->assertSame(2, $adminRow['open_case_count']);

        $this->assertNotEmpty($response->json('unresolved_safety_flags'));
    }
}
