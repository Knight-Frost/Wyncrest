<?php

namespace Tests\Feature;

use App\Enums\AdminCapability;
use App\Enums\ApplicationStatus;
use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Admin;
use App\Models\Application;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\LedgerAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AdminAnalyticsOverviewTest
 *
 * Covers GET /api/admin/analytics/overview — the Super Admin Platform
 * Analytics composite payload. Verifies authorization (super admin vs
 * scoped admin with/without the view_analytics capability), the response
 * shape, and that key figures reconcile with real seeded data rather than
 * being fabricated.
 */
class AdminAnalyticsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = Admin::factory()->create(['is_super_admin' => true]);
    }

    public function test_returns_full_shape_for_super_admin(): void
    {
        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'range' => ['key', 'start_date', 'end_date'],
                'analytics' => [
                    'generated_at',
                    'overview' => ['landlords', 'tenants', 'admins', 'properties', 'units', 'outstanding_cents', 'overdue_cents'],
                    'financial' => ['collected_cents', 'outstanding_cents', 'overdue_cents', 'collection_rate_percentage', 'outstanding_by_age', 'outstanding_by_landlord'],
                    'ledger_integrity' => ['status', 'issue_count', 'issues'],
                    'rent_collection' => ['on_time_rate_percentage', 'late_rate_percentage', 'missed_count', 'top_overdue_cases', 'top_landlords_by_overdue'],
                    'users' => ['tenants', 'landlords', 'admins', 'signups_by_month'],
                    'listings' => ['by_status', 'average_approval_time_hours', 'occupancy'],
                    'contracts',
                    'applications' => ['submitted_total', 'in_review', 'approved', 'rejected', 'stale_count'],
                    'verifications' => ['pending', 'verified', 'rejected', 'average_review_time_hours', 'overdue_count'],
                    'maintenance' => ['open', 'urgent', 'overdue', 'resolved_count', 'average_resolution_days', 'by_priority', 'by_category'],
                    'notifications',
                    'admin_activity' => ['logins_24h', 'sensitive_actions_period', 'failed_access_attempts_period', 'by_admin'],
                    'risk',
                    'system_health' => ['failed_jobs', 'failed_notifications', 'payment_failures_24h'],
                    'exports' => ['recent_exports'],
                ],
            ]);
    }

    public function test_scoped_admin_without_capability_is_forbidden(): void
    {
        $scoped = Admin::factory()->create(['is_super_admin' => false, 'capabilities' => []]);
        $this->actingAs($scoped, 'admin');

        $this->getJson('/api/admin/analytics/overview')
            ->assertStatus(403)
            ->assertJsonPath('required_capability', 'view_analytics');
    }

    public function test_scoped_admin_with_capability_is_allowed(): void
    {
        $scoped = Admin::factory()->create([
            'is_super_admin' => false,
            'capabilities' => [AdminCapability::VIEW_ANALYTICS->value],
        ]);
        $this->actingAs($scoped, 'admin');

        $this->getJson('/api/admin/analytics/overview')->assertStatus(200);
    }

    public function test_forbidden_for_landlord(): void
    {
        $landlord = User::factory()->landlord()->create();
        Sanctum::actingAs($landlord, [], 'sanctum');

        $this->getJson('/api/admin/analytics/overview')->assertStatus(401);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/analytics/overview')->assertStatus(401);
    }

    public function test_empty_platform_returns_zeros_not_errors(): void
    {
        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonPath('analytics.financial.outstanding_cents', 0)
            ->assertJsonPath('analytics.financial.overdue_cents', 0)
            ->assertJsonPath('analytics.rent_collection.missed_count', 0)
            ->assertJsonPath('analytics.applications.submitted_total', 0)
            ->assertJsonPath('analytics.maintenance.resolved_count', 0);
    }

    public function test_financial_section_separates_outstanding_from_overdue(): void
    {
        $contract = Contract::factory()->active()->create();

        // Not yet due — outstanding, but not overdue.
        LedgerEntry::factory()->pending()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'amount_cents' => 40_000,
            'due_date' => now()->addDays(5),
        ]);
        // Past due — counts toward both outstanding and overdue.
        LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'amount_cents' => 25_000,
            'due_date' => now()->subDays(10),
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonPath('analytics.financial.outstanding_cents', 65_000)
            ->assertJsonPath('analytics.financial.overdue_cents', 25_000);

        // Regression: overdue-case rows must be flattened to plain name
        // strings (the frontend renders these directly as text) rather than
        // the raw eager-loaded tenant/landlord relation arrays.
        $topCase = $response->json('analytics.rent_collection.top_overdue_cases.0');
        $this->assertIsString($topCase['tenant']);
        $this->assertIsString($topCase['landlord']);
    }

    public function test_outstanding_by_age_buckets_by_days_late(): void
    {
        $contract = Contract::factory()->active()->create();

        LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'amount_cents' => 10_000,
            'due_date' => now()->subDays(3), // bucket: 1-7 days
        ]);
        LedgerEntry::factory()->overdue()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'amount_cents' => 20_000,
            'due_date' => now()->subDays(90), // bucket: 60+ days
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');
        $buckets = collect($response->json('analytics.financial.outstanding_by_age'))->keyBy('label');

        $this->assertSame(10_000, $buckets['1-7 days']['amount_cents']);
        $this->assertSame(20_000, $buckets['60+ days']['amount_cents']);
        $this->assertSame(0, $buckets['8-30 days']['amount_cents']);
    }

    public function test_applications_funnel_counts_exclude_drafts(): void
    {
        Application::factory()->create(['status' => ApplicationStatus::DRAFT]);
        Application::factory()->create(['status' => ApplicationStatus::IN_REVIEW, 'submitted_at' => now()]);
        Application::factory()->approved()->create();
        Application::factory()->rejected()->create();

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonPath('analytics.applications.approved', 1)
            ->assertJsonPath('analytics.applications.rejected', 1)
            ->assertJsonPath('analytics.applications.in_review', 1);
    }

    public function test_stale_applications_are_flagged_after_five_days(): void
    {
        Application::factory()->create([
            'status' => ApplicationStatus::IN_REVIEW,
            'submitted_at' => now()->subDays(11),
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)->assertJsonPath('analytics.applications.stale_count', 1);
    }

    public function test_verification_overdue_count_after_seventy_two_hours(): void
    {
        VerificationRequest::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'submitted_at' => now()->subHours(80),
        ]);
        VerificationRequest::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'submitted_at' => now()->subHours(10),
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonPath('analytics.verifications.pending', 2)
            ->assertJsonPath('analytics.verifications.overdue_count', 1);
    }

    public function test_maintenance_resolution_metrics_are_accurate(): void
    {
        $contract = Contract::factory()->active()->create();

        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $contract->listing->unit->property_id,
            'unit_id' => $contract->listing->unit_id,
            'status' => 'resolved',
            'submitted_at' => now()->subDays(10),
            'resolved_at' => now()->subDays(6),
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonPath('analytics.maintenance.resolved_count', 1)
            ->assertJsonPath('analytics.maintenance.average_resolution_days', 4);
    }

    public function test_listings_by_status_is_a_real_mutually_exclusive_distribution(): void
    {
        Listing::factory()->draft()->count(2)->create();
        Listing::factory()->pendingReview()->count(1)->create();
        Listing::factory()->active()->count(3)->create();

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');
        $byStatus = $response->json('analytics.listings.by_status');

        // Regression: this used to reuse ListingReviewService::counts(), whose
        // queue-summary keys (pending/approved/rejected/all/approved_today/...)
        // overlap each other and would double-count in a "parts of a whole"
        // chart. It must now be a clean, non-overlapping status breakdown.
        $this->assertArrayNotHasKey('all', $byStatus);
        $this->assertArrayNotHasKey('approved_today', $byStatus);
        $this->assertSame(2, $byStatus['draft']);
        $this->assertSame(1, $byStatus['pending_review']);
        $this->assertSame(3, $byStatus['active']);
        $this->assertSame(6, array_sum($byStatus));
    }

    public function test_failed_admin_access_attempt_is_counted(): void
    {
        // A scoped admin without manage_ledger reaching a gated route should
        // be logged and show up in the analytics page's own admin_activity
        // section (real signal, added alongside this feature).
        $scoped = Admin::factory()->create([
            'is_super_admin' => false,
            'capabilities' => [AdminCapability::VIEW_ANALYTICS->value],
        ]);
        $this->actingAs($scoped, 'admin');
        $this->getJson('/api/admin/access/summary')->assertStatus(403);

        $this->actingAs($this->superAdmin, 'admin');
        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonPath('analytics.admin_activity.failed_access_attempts_period', 1);
    }

    public function test_date_range_filter_scopes_results(): void
    {
        Application::factory()->create([
            'status' => ApplicationStatus::APPROVED,
            'submitted_at' => now()->subDays(2),
            'decided_at' => now()->subDays(1),
            'created_at' => now()->subDays(2),
        ]);
        Application::factory()->create([
            'status' => ApplicationStatus::APPROVED,
            'submitted_at' => now()->subDays(200),
            'decided_at' => now()->subDays(199),
            'created_at' => now()->subDays(200),
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $recent = $this->getJson('/api/admin/analytics/overview?range=7d');
        $recent->assertStatus(200)->assertJsonPath('analytics.applications.approved', 1);

        $wide = $this->getJson('/api/admin/analytics/overview?range=custom&start_date='.now()->subDays(365)->toDateString().'&end_date='.now()->toDateString());
        $wide->assertStatus(200)->assertJsonPath('analytics.applications.approved', 2);
    }

    public function test_overview_reports_new_landlord_and_tenant_counts_this_month(): void
    {
        User::factory()->landlord()->create(['created_at' => now()]);
        User::factory()->landlord()->create(['created_at' => now()->subMonths(2)]);
        User::factory()->tenant()->create(['created_at' => now()]);
        User::factory()->tenant()->create(['created_at' => now()->subMonths(2)]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonPath('analytics.overview.new_landlords_this_month', 1)
            ->assertJsonPath('analytics.overview.new_tenants_this_month', 1);
    }

    public function test_overview_reports_contract_and_maintenance_period_signals(): void
    {
        $contract = Contract::factory()->active()->create([
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addDays(10),
        ]);

        MaintenanceRequest::factory()->create([
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'contract_id' => $contract->id,
            'property_id' => $contract->listing->unit->property_id,
            'unit_id' => $contract->listing->unit_id,
            'status' => 'open',
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');

        $response->assertStatus(200)
            ->assertJsonPath('analytics.overview.contracts_ending_within_30_days', 1)
            ->assertJsonPath('analytics.overview.properties_with_open_maintenance', 1);
    }

    public function test_missing_rent_generation_is_flagged_for_active_contract_with_no_current_period_rent(): void
    {
        Contract::factory()->active()->create([
            'start_date' => now()->subMonths(2),
            'end_date' => null,
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');
        $issues = collect($response->json('analytics.ledger_integrity.issues'));

        $this->assertTrue($issues->contains(fn ($i) => $i['code'] === 'missing_rent_generation'));
    }

    public function test_missing_rent_generation_not_flagged_once_current_period_rent_exists(): void
    {
        $contract = Contract::factory()->active()->create([
            'start_date' => now()->subMonths(2),
            'end_date' => null,
        ]);

        app(LedgerAutomationService::class)->generateRentForContract($contract);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');
        $issues = collect($response->json('analytics.ledger_integrity.issues'));

        $this->assertFalse($issues->contains(fn ($i) => $i['code'] === 'missing_rent_generation'));
    }

    public function test_admin_activity_recent_feed_surfaces_sensitive_actions(): void
    {
        $this->actingAs($this->superAdmin, 'admin');

        // A real sensitive action: promote another admin to super admin.
        $target = Admin::factory()->create(['is_super_admin' => false]);
        $this->postJson("/api/admin/access/admins/{$target->id}/promote-super", ['reason' => 'Test promotion'])
            ->assertStatus(200);

        $response = $this->getJson('/api/admin/analytics/overview');

        $recent = collect($response->json('analytics.admin_activity.recent'));
        $this->assertTrue($recent->contains(fn ($r) => $r['action'] === 'admin_promoted_super'));
    }

    public function test_exports_section_includes_both_ledger_and_admin_analytics_exports(): void
    {
        $this->actingAs($this->superAdmin, 'admin');

        $this->get('/api/admin/ledger/export')->assertStatus(200);
        $this->get('/api/admin/analytics/admin-summary/export')->assertStatus(200);

        $response = $this->getJson('/api/admin/analytics/overview');
        $actions = collect($response->json('analytics.exports.recent_exports'))->pluck('action');

        $this->assertTrue($actions->contains('ledger_exported'));
        $this->assertTrue($actions->contains('admin_analytics_exported'));
    }

    public function test_financial_trends_are_real_and_reconcile(): void
    {
        $contract = Contract::factory()->active()->create();

        $rent = LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::RENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => 50_000,
        ]);

        LedgerEntry::factory()->create([
            'contract_id' => $contract->id,
            'tenant_id' => $contract->tenant_id,
            'landlord_id' => $contract->landlord_id,
            'type' => LedgerType::PAYMENT,
            'status' => LedgerStatus::PAID,
            'amount_cents' => -50_000,
            'related_rent_entry_id' => $rent->id,
        ]);

        $this->actingAs($this->superAdmin, 'admin');

        $response = $this->getJson('/api/admin/analytics/overview');
        $thisMonth = now()->format('Y-m');

        $response->assertStatus(200)
            ->assertJsonPath("analytics.financial.billed_by_month.{$thisMonth}", 50_000)
            ->assertJsonPath("analytics.financial.collected_by_month.{$thisMonth}", 50_000)
            ->assertJsonPath("analytics.financial.outstanding_trend_by_month.{$thisMonth}", 0);

        $trend = $response->json('analytics.financial.outstanding_trend_by_month');
        $this->assertCount(6, $trend);
    }
}
