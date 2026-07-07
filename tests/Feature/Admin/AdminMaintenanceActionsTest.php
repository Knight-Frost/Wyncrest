<?php

namespace Tests\Feature\Admin;

use App\Enums\MediaCollection;
use App\Enums\MediaVisibility;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\MaintenanceEvent;
use App\Models\MaintenanceRequest;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the mutating admin maintenance-oversight actions: capability gating,
 * case ownership, escalation, internal notes, and status overrides. Escalate/
 * assign-owner are internal admin metadata and must NEVER appear on the
 * tenant/landlord-visible maintenance_events timeline; override-close/reopen
 * ARE real status transitions and must appear there, with the admin as actor.
 */
class AdminMaintenanceActionsTest extends TestCase
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

    public function test_scoped_admin_without_capability_is_forbidden_from_every_mutating_action(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $owner = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $request = $this->makeRequest();

        $this->actingAs($admin, 'admin');

        $this->postJson("/api/admin/maintenance/{$request->id}/assign", ['handling_admin_id' => $owner->id])->assertStatus(403);
        $this->postJson("/api/admin/maintenance/{$request->id}/escalate", ['reason' => 'Needs review'])->assertStatus(403);
        $this->postJson("/api/admin/maintenance/{$request->id}/notes", ['body' => 'Internal note'])->assertStatus(403);
        $this->postJson("/api/admin/maintenance/{$request->id}/override-close", ['reason' => 'Abandoned by landlord'])->assertStatus(403);
        $this->postJson("/api/admin/maintenance/{$request->id}/override-reopen", ['reason' => 'Tenant disputes resolution'])->assertStatus(403);
        $this->getJson('/api/admin/maintenance/export?scope=full')->assertStatus(403);
    }

    public function test_admin_with_capability_can_assign_a_case_owner_without_writing_a_tenant_visible_event(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_maintenance']]);
        $owner = Admin::factory()->create();
        $request = $this->makeRequest();

        $this->actingAs($admin, 'admin');

        $this->postJson("/api/admin/maintenance/{$request->id}/assign", ['handling_admin_id' => $owner->id])
            ->assertStatus(200)
            ->assertJsonPath('data.handling_admin.id', $owner->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'maintenance_case_owner_assigned',
            'actor_id' => $admin->id,
            'actor_type' => Admin::class,
        ]);

        $this->assertSame(0, MaintenanceEvent::where('maintenance_request_id', $request->id)->count());
    }

    public function test_admin_with_capability_can_escalate_without_writing_a_tenant_visible_event(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_maintenance']]);
        $request = $this->makeRequest();

        $this->actingAs($admin, 'admin');

        $this->postJson("/api/admin/maintenance/{$request->id}/escalate", ['reason' => 'Safety hazard, landlord unresponsive'])
            ->assertStatus(200)
            ->assertJsonPath('data.escalation_reason', 'Safety hazard, landlord unresponsive');

        $log = AuditLog::where('action', 'maintenance_escalated')->first();
        $this->assertNotNull($log);
        $this->assertSame('warning', $log->severity);

        $this->assertSame(0, MaintenanceEvent::where('maintenance_request_id', $request->id)->count());
    }

    public function test_admin_note_is_never_exposed_to_tenant_or_landlord(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_maintenance']]);
        $request = $this->makeRequest();

        $this->actingAs($admin, 'admin');
        $this->postJson("/api/admin/maintenance/{$request->id}/notes", ['body' => 'Internal-only context'])
            ->assertStatus(201)
            ->assertJsonPath('note.body', 'Internal-only context');

        $this->assertDatabaseHas('maintenance_admin_notes', [
            'maintenance_request_id' => $request->id,
            'body' => 'Internal-only context',
        ]);

        $tenant = $request->tenant;
        $this->actingAs($tenant, 'sanctum');
        $tenantResponse = $this->getJson("/api/tenant/maintenance/{$request->id}")->assertStatus(200);
        $this->assertArrayNotHasKey('admin_notes', $tenantResponse->json());
        $this->assertStringNotContainsString('Internal-only context', $tenantResponse->getContent());

        $landlord = $request->landlord;
        $this->actingAs($landlord, 'sanctum');
        $landlordResponse = $this->getJson("/api/landlord/maintenance/{$request->id}")->assertStatus(200);
        $this->assertStringNotContainsString('Internal-only context', $landlordResponse->getContent());
    }

    public function test_override_close_writes_a_tenant_visible_event_with_the_admin_as_actor(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_maintenance']]);
        $request = $this->makeRequest(['status' => 'waiting']);

        $this->actingAs($admin, 'admin');
        $this->postJson("/api/admin/maintenance/{$request->id}/override-close", ['reason' => 'Landlord unresponsive for 30 days'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'closed');

        $event = MaintenanceEvent::where('maintenance_request_id', $request->id)->where('event', 'closed')->first();
        $this->assertNotNull($event);
        $this->assertSame(Admin::class, $event->actor_type);
        $this->assertSame($admin->id, $event->actor_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'maintenance_status_updated',
            'actor_id' => $admin->id,
            'actor_type' => Admin::class,
        ]);
    }

    public function test_override_reopen_writes_a_tenant_visible_event_with_the_admin_as_actor(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_maintenance']]);
        $request = $this->makeRequest(['status' => 'resolved', 'resolved_at' => now()]);

        $this->actingAs($admin, 'admin');
        $this->postJson("/api/admin/maintenance/{$request->id}/override-reopen", ['reason' => 'Tenant says the leak returned'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'acknowledged');

        $event = MaintenanceEvent::where('maintenance_request_id', $request->id)->where('event', 'reopened')->first();
        $this->assertNotNull($event);
        $this->assertSame(Admin::class, $event->actor_type);
    }

    public function test_export_returns_a_verifiable_sha256_checksum_and_is_audited(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => ['manage_maintenance']]);
        $this->makeRequest();

        $this->actingAs($admin, 'admin');
        $response = $this->getJson('/api/admin/maintenance/export?scope=full')->assertStatus(200);

        $checksum = $response->headers->get('X-Export-Checksum');
        $this->assertNotEmpty($checksum);
        $this->assertSame(hash('sha256', $response->getContent()), $checksum);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin_maintenance_exported',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_admin_can_stream_maintenance_evidence_without_a_capability(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('maintenance_evidence/1/evidence.jpg', 'fake-image-bytes');

        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $request = $this->makeRequest();

        $asset = MediaAsset::create([
            'attachable_type' => MaintenanceRequest::class,
            'attachable_id' => $request->id,
            'owner_user_id' => $request->tenant_id,
            'uploaded_by_id' => $request->tenant_id,
            'collection' => MediaCollection::MaintenanceEvidence->value,
            'disk' => 'local',
            'path' => 'maintenance_evidence/1/evidence.jpg',
            'original_filename' => 'evidence.jpg',
            'stored_filename' => 'evidence.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 17,
            'visibility' => MediaVisibility::Private->value,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin');
        $this->get("/api/admin/media/{$asset->id}")->assertStatus(200);
    }

    public function test_baseline_viewing_show_and_analytics_need_no_capability(): void
    {
        $admin = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $request = $this->makeRequest();

        $this->actingAs($admin, 'admin');

        $this->getJson("/api/admin/maintenance/{$request->id}")->assertStatus(200);
        $this->getJson('/api/admin/maintenance/analytics')->assertStatus(200);
    }
}
