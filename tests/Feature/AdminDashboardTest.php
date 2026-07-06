<?php

namespace Tests\Feature;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminDashboardTest
 *
 * Covers GET /api/admin/dashboard — the Phase A command-center overview
 * (attention queue, priority cases, platform snapshot, rent risk monitor,
 * review queues, system health, recent activity). Asserts the JSON shape,
 * that aggregates reflect seeded data via the same LedgerComputationEngine
 * the ledger page uses, and that authorization is enforced.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create(['is_super_admin' => true]);
    }

    public function test_returns_full_dashboard_shape_for_admin(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'properties',
                'units',
                'attention_queue' => [
                    'verification' => ['pending', 'pending_by_role', 'oldest', 'action_route'],
                    'listings' => ['pending', 'oldest', 'action_route'],
                    'rent_risk' => ['overdue_count', 'overdue_total_cents', 'affected_tenants', 'oldest', 'highest_risk', 'action_route'],
                    'finance_issues' => ['count', 'window_days', 'latest', 'action_route'],
                    'maintenance' => ['open', 'urgent', 'overdue', 'waiting', 'oldest', 'action_route'],
                    'notifications' => ['failed_total', 'critical_failed', 'latest', 'action_route'],
                ],
                'priority_cases',
                'platform_snapshot' => [
                    'users' => ['tenants', 'landlords', 'active', 'suspended', 'pending_verifications', 'new_this_week'],
                    'listings' => ['total', 'active', 'pending', 'draft', 'rejected', 'recently_submitted'],
                    'contracts' => ['active', 'ending_soon', 'awaiting_action', 'with_overdue_rent'],
                    'rent_ledger' => ['expected_this_month_cents', 'collected_this_month_cents', 'outstanding_cents', 'overdue_cents'],
                    'maintenance',
                    'notifications',
                ],
                'rent_risk_monitor' => ['summary', 'cases'],
                'review_queues' => ['verification', 'listings'],
                'system_health' => ['failed_jobs', 'failed_notifications', 'payment_failures_24h', 'scheduler'],
                'recent_activity',
            ]);
    }

    public function test_platform_snapshot_reflects_seeded_data(): void
    {
        Listing::factory()->draft()->count(2)->create();
        Listing::factory()->pendingReview()->count(3)->create();
        Listing::factory()->active()->count(4)->create();

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('platform_snapshot.users.landlords', User::landlords()->count())
            ->assertJsonPath('platform_snapshot.users.tenants', User::tenants()->count())
            ->assertJsonPath('properties', Property::count())
            ->assertJsonPath('units', Unit::count())
            ->assertJsonPath('platform_snapshot.listings.pending', 3)
            ->assertJsonPath('platform_snapshot.listings.active', 4)
            ->assertJsonPath('platform_snapshot.listings.draft', 2)
            ->assertJsonPath('platform_snapshot.listings.total', 9);
    }

    public function test_attention_queue_verification_card_is_accurate(): void
    {
        $oldTenant = User::factory()->tenant()->create();
        $newLandlord = User::factory()->landlord()->create();

        VerificationRequest::create([
            'user_id' => $oldTenant->id,
            'status' => 'pending',
            'submitted_at' => now()->subDays(2),
        ]);
        VerificationRequest::create([
            'user_id' => $newLandlord->id,
            'status' => 'under_review',
            'submitted_at' => now(),
        ]);
        // NEEDS_MORE_INFORMATION waits on the user, not the admin — excluded.
        VerificationRequest::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'needs_more_information',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('attention_queue.verification.pending', 2)
            ->assertJsonPath('attention_queue.verification.pending_by_role.tenant', 1)
            ->assertJsonPath('attention_queue.verification.pending_by_role.landlord', 1)
            ->assertJsonPath('attention_queue.verification.oldest.user_name', $oldTenant->full_name)
            ->assertJsonPath('attention_queue.verification.oldest.waiting_days', 2);
    }

    public function test_attention_queue_notifications_card_counts_failures(): void
    {
        $tenant = User::factory()->create(['is_active' => true]);

        Notification::factory()->create(['user_id' => $tenant->id, 'delivery_failed_at' => now()]);
        Notification::factory()->create(['user_id' => $tenant->id, 'sms_failed_at' => now()]);
        Notification::factory()->create(['user_id' => $tenant->id, 'delivered_at' => now()]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('attention_queue.notifications.failed_total', 2)
            ->assertJsonPath('system_health.failed_notifications', 2);
    }

    public function test_contract_snapshot_counts_by_status(): void
    {
        Contract::factory()->draft()->count(2)->create();
        Contract::factory()->pendingTenant()->count(1)->create();
        Contract::factory()->active()->count(4)->create();
        Contract::factory()->terminated()->count(1)->create();

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('platform_snapshot.contracts.active', 4)
            ->assertJsonPath('platform_snapshot.contracts.awaiting_action', 1);
    }

    public function test_rent_ledger_snapshot_aggregates_platform_wide(): void
    {
        $contract = Contract::factory()->active()->create();

        // Outstanding = pending + overdue.
        LedgerEntry::factory()->pending()->create([
            'contract_id' => $contract->id,
            'amount_cents' => 100_000,
        ]);
        LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'amount_cents' => 50_000,
            'due_date' => now()->subDays(18),
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
        ]);

        $paidThisMonth = LedgerEntry::factory()->paid()->create([
            'contract_id' => $contract->id,
            'amount_cents' => 70_000,
            'due_date' => now()->startOfMonth()->addDays(2),
        ]);
        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'type' => LedgerType::PAYMENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => -70_000,
            'related_rent_entry_id' => $paidThisMonth->id,
            'created_at' => now()->startOfMonth()->addDays(2),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('platform_snapshot.rent_ledger.outstanding_cents', 150_000)
            ->assertJsonPath('platform_snapshot.rent_ledger.overdue_cents', 50_000)
            ->assertJsonPath('platform_snapshot.rent_ledger.collected_this_month_cents', 70_000)
            ->assertJsonPath('attention_queue.rent_risk.overdue_count', 1)
            ->assertJsonPath('attention_queue.rent_risk.overdue_total_cents', 50_000);

        $oldest = $response->json('attention_queue.rent_risk.oldest');
        $this->assertSame(18, $oldest['days_late']);

        $riskCases = $response->json('rent_risk_monitor.cases');
        $this->assertCount(1, $riskCases);
        $this->assertSame(50_000, $riskCases[0]['amount_cents']);
    }

    public function test_attention_queue_maintenance_card_counts_urgent_and_overdue(): void
    {
        $contract = Contract::factory()->active()->create();

        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $contract->listing->unit->property_id,
            'unit_id' => $contract->listing->unit_id,
            'status' => 'open',
            'priority' => 'urgent',
            'submitted_at' => now()->subDays(3),
        ]);
        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $contract->listing->unit->property_id,
            'unit_id' => $contract->listing->unit_id,
            'status' => 'resolved',
            'priority' => 'low',
            'submitted_at' => now()->subDays(10),
        ]);

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('attention_queue.maintenance.open', 1)
            ->assertJsonPath('attention_queue.maintenance.urgent', 1)
            ->assertJsonPath('platform_snapshot.maintenance.open', 1);
    }

    public function test_priority_cases_capped_at_eight(): void
    {
        for ($i = 0; $i < 10; $i++) {
            VerificationRequest::create([
                'user_id' => User::factory()->create()->id,
                'status' => 'pending',
                'submitted_at' => now()->subDays($i),
            ]);
        }

        $this->actingAs($this->admin, 'admin');

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(8, count($response->json('priority_cases')));
    }

    public function test_forbidden_for_landlord(): void
    {
        $landlord = User::factory()->landlord()->create();
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    public function test_forbidden_for_tenant(): void
    {
        $tenant = User::factory()->tenant()->create();
        Sanctum::actingAs($tenant, [], 'sanctum');

        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }
}
